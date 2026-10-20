<?php

use App\Jobs\Ogc\SubmitProcessExecutionJob;
use App\Jobs\Ogc\WarmOgcProcessCacheJob;
use App\Models\ProcessExecution;
use App\Models\User;
use App\Services\Ogc\OgcProcessCache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

beforeEach(function () {
    Inertia::disableSsr();
});

test('catalog and form metadata follow the language cookie', function (string $cookie, string $expected) {
    Http::preventStrayRequests();
    $cache = app(OgcProcessCache::class);

    foreach (['it' => 'Italiano', 'en' => 'English'] as $language => $label) {
        app()->setLocale($language);
        $process = ogcFixture('process-conduit');
        $process['title'] = $label;
        $process['description'] = $label.' description';
        $process['inputs']['melt_composition']['title'] = $label.' input';
        $process['outputs']['gas']['description'] = $label.' output';
        $cache->putCatalog(['processes' => [$process]]);
        $cache->putProcess('conduit', $process);
    }

    $this->actingAs(User::factory()->create())
        ->withUnencryptedCookie('language', $cookie);

    $this->get(route('processes.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('processes.0.title', $expected)
        ->where('processes.0.description', $expected.' description'));

    $this->get(route('processes.show', 'conduit'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('process.title', $expected)
        ->where('formSchema.title', $expected)
        ->where('formSchema.description', $expected.' description')
        ->where('formSchema.fields.melt_composition.title', $expected.' input')
        ->where('formSchema.outputs.gas.description', $expected.' output'));

    Http::assertNothingSent();
})->with([
    'italian' => ['it', 'Italiano'],
    'english' => ['en', 'English'],
    'unsupported cookie uses the interface default' => ['fr', 'English'],
]);

test('a missing language warms without serving another language or unscoped legacy entries', function () {
    Bus::fake([WarmOgcProcessCacheJob::class]);
    Http::preventStrayRequests();
    app()->setLocale('it');
    $cache = app(OgcProcessCache::class);
    $cache->putCatalog(ogcFixture('processes'));
    $cache->putProcess('conduit', ogcFixture('process-conduit'));
    Cache::forever('ogc-processes.catalog', ogcFixture('processes'));
    Cache::forever('ogc-processes.process.conduit', ogcFixture('process-conduit'));

    $this->actingAs(User::factory()->create())
        ->withUnencryptedCookie('language', 'en');

    $this->get(route('processes.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('catalogStatus', 'warming')
        ->where('catalogLastUpdatedAt', null)
        ->has('processes', 0));

    $this->get(route('processes.show', 'conduit'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('processStatus', 'warming')
        ->where('processLastUpdatedAt', null)
        ->where('process', null)
        ->where('formSchema', null));

    Bus::assertDispatchedTimes(WarmOgcProcessCacheJob::class, 2);
    Http::assertNothingSent();
});

test('partial service reloads identify the language of their metadata', function () {
    app()->setLocale('it');
    app(OgcProcessCache::class)->putCatalog(ogcFixture('processes'));
    $manifest = public_path('build/manifest.json');

    $this->actingAs(User::factory()->create())
        ->withUnencryptedCookie('language', 'it')
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => file_exists($manifest) ? hash_file('xxh128', $manifest) : '',
            'X-Inertia-Partial-Component' => 'processes/index',
            'X-Inertia-Partial-Data' => 'processes',
        ])
        ->get(route('processes.index'))
        ->assertOk()
        ->assertJsonPath('props.language', 'it')
        ->assertJsonCount(3, 'props.processes');
});

test('starting a process uses the same language metadata as its form', function () {
    Bus::fake([SubmitProcessExecutionJob::class]);
    Http::preventStrayRequests();
    $cache = app(OgcProcessCache::class);

    foreach (['it' => 'Pressione', 'en' => 'Pressure'] as $language => $label) {
        app()->setLocale($language);
        $cache->putProcess('localized', [
            'id' => 'localized',
            'version' => '1.0',
            'title' => $label,
            'inputs' => ['pressure' => ['title' => $label, 'schema' => ['type' => 'number']]],
            'outputs' => ['result' => ['schema' => ['type' => 'number']]],
        ]);
    }

    $this->actingAs(User::factory()->create())
        ->withUnencryptedCookie('language', 'it')
        ->post(route('processes.jobs.store', 'localized'), [
            'inputs' => ['pressure' => 10],
            'outputs' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $execution = ProcessExecution::query()->sole();
    expect($execution->process_title)->toBe('Pressione');
    expect($execution->input_snapshot['fields']['pressure']['title'])->toBe('Pressione');
    expect($execution->request_payload['inputs']['pressure'])->toBe(10);
    Bus::assertDispatched(SubmitProcessExecutionJob::class);
    Http::assertNothingSent();
});
