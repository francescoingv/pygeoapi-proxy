<?php

use App\Jobs\Ogc\WarmOgcProcessCacheJob;
use App\Models\ProcessExecution;
use App\Models\User;
use App\Services\Ogc\OgcProcessCache;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow();
    Cache::flush();
    Http::preventStrayRequests();
    Http::allowStrayRequests(['*__inertia_ssr']);

    config([
        'services.ogc_processes.base_url' => 'https://voice.pi.ingv.it/geoinquire/',
        'services.ogc_processes.cache_ttl' => 300,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('guests cannot access process catalog', function () {
    $this->get('/processes')->assertRedirect(route('login'));
});

test('authenticated users can view cached process catalog', function () {
    app(OgcProcessCache::class)->putCatalog(ogcFixture('processes'));

    $this->actingAs(User::factory()->create())
        ->get('/processes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/index')
            ->where('catalogStatus', 'ready')
            ->has('processes', 3));
});

test('authenticated users see catalog warming state when cache is missing', function () {
    $this->actingAs(User::factory()->create())
        ->get('/processes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/index')
            ->where('catalogStatus', 'warming')
            ->has('processes', 0));
});

test('authenticated users see the latest catalog without warming when fresh cache is missing', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-02 10:30:00'));

    app(OgcProcessCache::class)->putCatalog(ogcFixture('processes'));
    Cache::forget(app(OgcProcessCache::class)->catalogKey());

    $this->actingAs(User::factory()->create())
        ->get('/processes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/index')
            ->where('catalogStatus', 'ready')
            ->where('catalogLastUpdatedAt', now()->toIso8601String())
            ->has('processes', 3));
});

test('authenticated users trigger warm-up when catalog cache is missing', function () {
    Bus::fake();

    $this->actingAs(User::factory()->create())
        ->get('/processes')
        ->assertOk();

    Bus::assertDispatched(WarmOgcProcessCacheJob::class);
});

test('authenticated users can view cached process detail with normalized schema', function () {
    app(OgcProcessCache::class)->putProcess('conduit', ogcFixture('process-conduit'));

    $this->actingAs(User::factory()->create())
        ->get('/processes/conduit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/show')
            ->where('processStatus', 'ready')
            ->where('process.id', 'conduit')
            ->where('inputPrefill', null)
            ->has('formSchema.fields.melt_composition'));
});

test('process detail exposes only reusable inputs from an authorized source job', function () {
    app(OgcProcessCache::class)->putProcess('conduit', ogcFixture('process-conduit'));

    $user = User::factory()->create();
    $sourceJob = ProcessExecution::factory()->for($user)->create([
        'name' => 'Scenario sorgente',
        'process_id' => 'conduit',
        'request_payload' => [
            'inputs' => [
                'melt_composition' => [
                    'value' => ['sio2' => 0.7669],
                ],
                'searching_mode' => [
                    'value' => '[redacted inline value]',
                    'sizeBytes' => 4096,
                ],
                'retired_input' => 12,
            ],
            'outputs' => ['gas' => ['transmissionMode' => 'value']],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('processes.show', [
            'process' => 'conduit',
            'sourceJob' => $sourceJob->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/show')
            ->where('inputPrefill.sourceJobId', $sourceJob->id)
            ->where('inputPrefill.sourceJobName', 'Scenario sorgente')
            ->where('inputPrefill.inputs.melt_composition.value.sio2', 0.7669)
            ->where('inputPrefill.skippedInputs', [
                'searching_mode',
                'retired_input',
            ])
            ->missing('inputPrefill.inputs.searching_mode')
            ->missing('inputPrefill.inputs.retired_input')
            ->missing('inputPrefill.outputs'));
});

test('process detail skips a whole input when its stored object contains removed fields', function () {
    app(OgcProcessCache::class)->putProcess('conduit', ogcFixture('process-conduit'));

    $user = User::factory()->create();
    $sourceJob = ProcessExecution::factory()->for($user)->create([
        'process_id' => 'conduit',
        'request_payload' => [
            'inputs' => [
                'melt_composition' => [
                    'value' => [
                        'sio2' => 0.7669,
                        'retired_component' => 0.1,
                    ],
                ],
                'geometry' => [
                    'value' => ['g' => 'conduit', 'l' => 1000],
                ],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('processes.show', [
            'process' => 'conduit',
            'sourceJob' => $sourceJob->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('inputPrefill.skippedInputs', ['melt_composition'])
            ->where('inputPrefill.inputs.geometry.value.g', 'conduit')
            ->missing('inputPrefill.inputs.melt_composition'));
});

test('users cannot reuse inputs from another users job', function () {
    app(OgcProcessCache::class)->putProcess('conduit', ogcFixture('process-conduit'));

    $sourceJob = ProcessExecution::factory()->create([
        'process_id' => 'conduit',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('processes.show', [
            'process' => 'conduit',
            'sourceJob' => $sourceJob->id,
        ]))
        ->assertForbidden();
});

test('users cannot reuse inputs on a different process', function () {
    app(OgcProcessCache::class)->putProcess('conduit', ogcFixture('process-conduit'));

    $user = User::factory()->create();
    $sourceJob = ProcessExecution::factory()->for($user)->create([
        'process_id' => 'pybox',
    ]);

    $this->actingAs($user)
        ->get(route('processes.show', [
            'process' => 'conduit',
            'sourceJob' => $sourceJob->id,
        ]))
        ->assertNotFound();
});

test('authenticated users see process warming state when detail cache is missing', function () {
    $this->actingAs(User::factory()->create())
        ->get('/processes/conduit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/show')
            ->where('processStatus', 'warming')
            ->where('process', null)
            ->where('formSchema', null));
});

test('authenticated users see the latest process detail without warming when fresh cache is missing', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-02 10:45:00'));

    $cache = app(OgcProcessCache::class);
    $cache->putProcess('conduit', ogcFixture('process-conduit'));
    Cache::forget($cache->processKey('conduit'));

    $this->actingAs(User::factory()->create())
        ->get('/processes/conduit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/show')
            ->where('processStatus', 'ready')
            ->where('process.id', 'conduit')
            ->where('processLastUpdatedAt', now()->toIso8601String())
            ->has('formSchema.fields.melt_composition'));
});

test('authenticated users trigger warm-up when process detail cache is missing', function () {
    Bus::fake();

    $this->actingAs(User::factory()->create())
        ->get('/processes/conduit')
        ->assertOk();

    Bus::assertDispatched(WarmOgcProcessCacheJob::class);
});

test('process detail exposes named examples for local environments', function () {
    app()->detectEnvironment(fn (): string => 'local');
    app(OgcProcessCache::class)->putProcess('conduit', ogcFixture('process-conduit'));

    $this->actingAs(User::factory()->create())
        ->get('/processes/conduit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/show')
            ->where('processStatus', 'ready')
            ->has('formSchema.examples', 1)
            ->where('formSchema.examples.0.title', 'payload_example')
            ->where('formSchema.examples.0.inputs.melt_composition.value.sio2', 0.7669));
});

test('process detail exposes named examples outside local environments', function () {
    app()->detectEnvironment(fn (): string => 'production');
    app(OgcProcessCache::class)->putProcess('conduit', ogcFixture('process-conduit'));

    $this->actingAs(User::factory()->create())
        ->get('/processes/conduit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('processes/show')
            ->where('processStatus', 'ready')
            ->has('formSchema.examples', 1)
            ->where('formSchema.examples.0.title', 'payload_example')
            ->where('formSchema.examples.0.inputs.melt_composition.value.sio2', 0.7669));
});

test('process examples retain compatible inputs from every named payload and ignore curl commands', function () {
    $description = ogcFixture('process-solwcad');
    $firstPayload = $description['examples'][0]['payload_example'];
    $firstPayload['inputs']['pippo'] = 'ignored';
    $description['examples'][0] = ['payload_example_1' => $firstPayload];
    $description['examples'][] = [
        'payload_example_2' => [
            'inputs' => [
                'swinput.data' => ['value' => ['kl' => 1, 'ndat1' => 1, 'iopen' => 0]],
                'sw.data' => [['5.d8', '1323.15', '0.1']],
            ],
            'outputs' => ['solwcad_out' => ['transmissionMode' => 'value']],
        ],
    ];
    app(OgcProcessCache::class)->putProcess('solwcad', $description);

    $this->actingAs(User::factory()->create())
        ->get('/processes/solwcad')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('formSchema.examples', 2)
            ->where('formSchema.examples.0.title', 'payload_example_1')
            ->where('formSchema.examples.0.inputs', fn ($inputs): bool => $inputs->keys()->all() === ['swinput.data', 'sw.data']
                && $inputs['swinput.data']['value']['kl'] === 0
                && $inputs['sw.data'][0][0] === '1.00d8')
            ->where('formSchema.examples.1.title', 'payload_example_2')
            ->where('formSchema.examples.1.inputs', fn ($inputs): bool => $inputs->all() === [
                'swinput.data' => ['value' => ['kl' => 1, 'ndat1' => 1, 'iopen' => 0]],
                'sw.data' => [['5.d8', '1323.15', '0.1']],
            ]));
});

test('process examples skip malformed payloads and payloads without known inputs', function () {
    $description = ogcFixture('process-pybox');
    $description['examples'] = [
        null,
        'curl -X POST /execution',
        ['no_inputs' => ['outputs' => []]],
        ['string_inputs' => ['inputs' => 'not an object']],
        ['list_inputs' => ['inputs' => [40.82, 14.428]]],
        ['unknown_inputs' => ['inputs' => ['pippo' => 42]]],
        ['empty_inputs' => ['inputs' => []]],
        [
            'custom_example' => ['inputs' => ['lat' => 0, 'pippo' => 42]],
            'another_example' => ['inputs' => ['lon' => 14.428]],
        ],
    ];
    app(OgcProcessCache::class)->putProcess('pybox', $description);

    $this->actingAs(User::factory()->create())
        ->get('/processes/pybox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('formSchema.examples', [
                ['id' => 'custom_example', 'title' => 'custom_example', 'description' => null, 'inputs' => ['lat' => 0]],
                ['id' => 'another_example', 'title' => 'another_example', 'description' => null, 'inputs' => ['lon' => 14.428]],
            ]));
});

test('process detail handles missing or invalid example collections', function (mixed $examples) {
    $description = ogcFixture('process-pybox');
    $description['examples'] = $examples;
    app(OgcProcessCache::class)->putProcess('pybox', $description);

    $this->actingAs(User::factory()->create())
        ->get('/processes/pybox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('formSchema.examples', []));
})->with([
    'null' => [null],
    'string' => ['curl -X POST /execution'],
    'empty' => [[]],
]);

test('process detail also accepts the legacy example collection', function () {
    $description = ogcFixture('process-pybox');
    $description['example'] = $description['examples'];
    unset($description['examples']);
    app(OgcProcessCache::class)->putProcess('pybox', $description);

    $this->actingAs(User::factory()->create())
        ->get('/processes/pybox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('formSchema.examples', 1)
            ->where('formSchema.examples.0.inputs.lat', 40.82));
});

test('process examples use titles and descriptions from payload metadata and exclude string values', function () {
    $description = ogcFixture('process-pybox');
    $description['examples'] = [
        [
            'id' => 'payload_1',
            'title' => 'Simple payload example N°1',
            'description' => 'A simple execution payload.',
            'value' => ['inputs' => ['dt' => 0.5, 'margin' => 5000, 'unknown_input' => 42]],
        ],
        [
            'id' => 'payload_2',
            'title' => 'Simple payload example N°2',
            'description' => 'A simple alternative execution payload.',
            'value' => ['inputs' => ['dt' => 0.3, 'margin' => 1000]],
        ],
        [
            'id' => 'curlRequest_1',
            'title' => 'Simple curl request',
            'description' => 'A simple request via curl command.',
            'value' => 'curl -X POST /execution',
        ],
        [
            'id' => 'unrelated_payload',
            'title' => 'Unrelated payload',
            'value' => ['inputs' => ['unknown_input' => 42]],
        ],
    ];
    app(OgcProcessCache::class)->putProcess('pybox', $description);

    $this->actingAs(User::factory()->create())
        ->get('/processes/pybox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('formSchema.examples', [
                [
                    'id' => 'payload_1',
                    'title' => 'Simple payload example N°1',
                    'description' => 'A simple execution payload.',
                    'inputs' => ['dt' => 0.5, 'margin' => 5000],
                ],
                [
                    'id' => 'payload_2',
                    'title' => 'Simple payload example N°2',
                    'description' => 'A simple alternative execution payload.',
                    'inputs' => ['dt' => 0.3, 'margin' => 1000],
                ],
            ]));
});
