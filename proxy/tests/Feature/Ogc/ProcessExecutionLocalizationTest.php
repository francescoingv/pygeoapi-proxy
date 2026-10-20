<?php

use App\Actions\Ogc\CreateProcessExecution;
use App\Enums\Ogc\ExecutionMode;
use App\Models\ProcessExecution;
use App\Models\ProcessExecutionResult;
use App\Models\User;
use App\Services\Ogc\OgcProcessCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Cache::flush();
    Http::preventStrayRequests();
    Storage::fake('local');
});

function historicalLocalizedProcess(string $language): array
{
    $italian = $language === 'it';

    return [
        'id' => 'history', 'version' => '1.0',
        'title' => $italian ? 'Servizio storico' : 'Historical service',
        'inputs' => [
            'settings' => ['title' => $italian ? 'Impostazioni' : 'Settings', 'schema' => [
                'type' => 'object', 'properties' => [
                    'choice' => ['title' => $italian ? 'Scelta' : 'Choice', 'description' => $italian ? 'Scegli il metodo' : 'Choose the method', 'oneOf' => [
                        ['title' => $italian ? 'Metodo originale' : 'Original method', 'description' => $italian ? 'Descrizione metodo' : 'Method description', 'properties' => [
                            'amount' => ['title' => $italian ? 'Quantità' : 'Amount', 'description' => $italian ? 'Quantità richiesta' : 'Requested amount', 'type' => 'integer', 'minimum' => 0, 'enum' => [0, 1]],
                        ]],
                    ]],
                    'retained' => ['title' => 'Historical-only label', 'type' => 'string'],
                ],
            ]],
            'data' => ['title' => $italian ? 'Dati' : 'Data', 'schema' => ['contentMediaType' => 'text/csv']],
        ],
        'outputs' => ['report' => ['title' => $italian ? 'Rapporto' : 'Report', 'description' => $italian ? 'Descrizione rapporto' : 'Report description', 'schema' => ['type' => 'string']]],
    ];
}

test('job views localize cached metadata and preserve the submitted history', function (string $language, string $title, string $inputTitle, string $variant, string $outputTitle) {
    $user = User::factory()->create();
    $original = historicalLocalizedProcess('en');
    $original['outputs']['archive'] = $original['outputs']['report'];
    $inputs = ['settings' => ['choice' => ['variant' => '0', 'value' => ['amount' => 0]], 'retained' => 'raw value'], 'data' => ['value' => "1,2\n", 'mediaType' => 'text/csv']];
    $execution = app(CreateProcessExecution::class)->handle(
        user: $user, process: $original, payload: ['inputs' => $inputs], mode: ExecutionMode::Async,
        submittedInputs: $inputs, inputFiles: [['path' => ['data'], 'name' => 'original.csv']],
    );
    $result = ProcessExecutionResult::factory()->for($execution)->create(['output_id' => 'report', 'title' => 'Report', 'description' => 'Report description']);
    $fileResult = ProcessExecutionResult::factory()->for($execution)->create(['output_id' => 'archive', 'title' => 'Report - user-file.csv', 'description' => 'Original filename']);
    $storedExecution = $execution->fresh()->getRawOriginal();
    $storedResult = $result->fresh()->getRawOriginal();
    $translated = historicalLocalizedProcess($language);
    $translated['outputs']['archive'] = $translated['outputs']['report'];
    $translated['inputs']['settings']['schema']['properties']['choice']['oneOf'][0]['properties']['amount']['minimum'] = 100;
    $translated['inputs']['settings']['schema']['properties']['choice']['oneOf'][0]['properties']['amount']['enum'] = [100, 200];
    unset($translated['inputs']['settings']['schema']['properties']['retained']['title']);
    $translated['inputs']['newInput'] = ['title' => 'Never submitted', 'schema' => ['type' => 'string']];
    app(OgcProcessCache::class)->putProcess('history', $translated, language: $language);

    $this->actingAs($user)->withUnencryptedCookie('language', $language)->get(route('jobs.show', $execution))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('execution.processTitle', $title)
        ->where('execution.displayName', fn (string $value): bool => str_starts_with($value, $title.' '))
        ->where('execution.outputMetadata.report.title', $outputTitle)
        ->where('execution.outputMetadata.report.description', $language === 'it' ? 'Descrizione rapporto' : 'Report description')
        ->where('execution.results.0.title', $outputTitle)
        ->where('execution.results.0.description', $language === 'it' ? 'Descrizione rapporto' : 'Report description')
        ->where('execution.results.1.title', 'Report - user-file.csv')
        ->where('execution.results.1.description', 'Original filename')
        ->where('inputReview.fields.settings.title', $inputTitle)
        ->where('inputReview.fields.settings.fields.choice.description', $language === 'it' ? 'Scegli il metodo' : 'Choose the method')
        ->where('inputReview.fields.settings.fields.choice.variants.0.label', $variant)
        ->where('inputReview.fields.settings.fields.choice.variants.0.description', $language === 'it' ? 'Descrizione metodo' : 'Method description')
        ->where('inputReview.fields.settings.fields.choice.variants.0.fields.amount.description', $language === 'it' ? 'Quantità richiesta' : 'Requested amount')
        ->where('inputReview.fields.settings.fields.choice.variants.0.fields.amount.title', $language === 'it' ? 'Quantità' : 'Amount')
        ->where('inputReview.fields.settings.fields.choice.variants.0.fields.amount.minimum', 0)
        ->where('inputReview.fields.settings.fields.choice.variants.0.fields.amount.options', [0, 1])
        ->where('inputReview.fields.settings.fields.retained.title', 'Historical-only label')
        ->where('inputReview.inputs.settings.choice.variant', '0')
        ->where('inputReview.inputs.settings.choice.value.amount', 0)
        ->where('inputReview.files.0.name', 'original.csv')
        ->missing('inputReview.fields.newInput'));
    $this->get(route('jobs.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('executions.data.0.processTitle', $title)
        ->where('executions.data.0.displayName', fn (string $value): bool => str_starts_with($value, $title.' ')));
    $this->get(route('jobs.inputs.download', [$execution, 0]))->assertDownload('original.csv');

    expect($execution->fresh()->getRawOriginal())->toBe($storedExecution);
    expect($result->fresh()->getRawOriginal())->toBe($storedResult);
    expect($fileResult->fresh()->title)->toBe('Report - user-file.csv');
    Http::assertNothingSent();
})->with([
    ['it', 'Servizio storico', 'Impostazioni', 'Metodo originale', 'Rapporto'],
    ['en', 'Historical service', 'Settings', 'Original method', 'Report'],
]);

test('missing translations and unknown or different process versions keep historical metadata', function (?string $version, bool $cacheAvailable) {
    $user = User::factory()->create();
    $execution = app(CreateProcessExecution::class)->handle(user: $user, process: historicalLocalizedProcess('en'), payload: ['inputs' => ['data' => 'original']], mode: ExecutionMode::Async);
    app(OgcProcessCache::class)->putProcess('history', historicalLocalizedProcess('en'), language: 'en');
    if ($cacheAvailable) {
        $translated = historicalLocalizedProcess('it');
        $translated['version'] = $version;
        app(OgcProcessCache::class)->putProcess('history', $translated, language: 'it');
    }
    if ($version === null && $cacheAvailable) {
        $execution->forceFill(['process_version' => null])->save();
    }

    $this->actingAs($user)->withUnencryptedCookie('language', 'it')->get(route('jobs.show', $execution))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('execution.processTitle', 'Historical service')
        ->where('execution.outputMetadata.report.title', 'Report')
        ->where('inputReview.fields.data.title', 'Data')
        ->where('inputReview.inputs.data', 'original'));
    Http::assertNothingSent();
})->with(['different version' => ['2.0', true], 'unknown version' => [null, true], 'missing selected language' => ['1.0', false]]);

test('admin job lists display translated service titles', function () {
    $execution = ProcessExecution::factory()->create(['process_id' => 'history', 'process_version' => '1.0', 'process_title' => 'Historical service', 'name' => 'Custom job name']);
    app(OgcProcessCache::class)->putProcess('history', historicalLocalizedProcess('it'), language: 'it');

    $this->actingAs(User::factory()->admin()->create())->withUnencryptedCookie('language', 'it')->get(route('admin.jobs.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('executions.data.0.id', $execution->id)
        ->where('executions.data.0.processTitle', 'Servizio storico')
        ->where('executions.data.0.displayName', 'Custom job name'));
    Http::assertNothingSent();
});

test('legacy input reviews use matching translated fields while retaining the historical payload', function () {
    $user = User::factory()->create();
    $execution = ProcessExecution::factory()->for($user)->create([
        'process_id' => 'history', 'process_version' => '1.0', 'process_title' => 'Historical service',
        'input_snapshot' => null, 'request_payload' => ['inputs' => ['data' => 'historical value']],
    ]);
    app(OgcProcessCache::class)->putProcess('history', historicalLocalizedProcess('it'), language: 'it');

    $this->actingAs($user)->withUnencryptedCookie('language', 'it')->get(route('jobs.show', $execution))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('inputReview.legacy', true)
        ->where('inputReview.fields.data.title', 'Dati')
        ->where('inputReview.inputs.data', 'historical value'));
    expect($execution->fresh()->input_snapshot)->toBeNull();
    Http::assertNothingSent();
});

test('job search finds the visible translated service title before counts and pagination', function (string $route, bool $admin) {
    $viewer = $admin ? User::factory()->admin()->create() : User::factory()->create();
    $owner = $admin ? User::factory()->create() : $viewer;
    $match = ProcessExecution::factory()->for($owner)->create(['process_id' => 'history', 'process_version' => '1.0', 'process_title' => 'Historical service', 'status' => 'failed']);
    ProcessExecution::factory()->for($owner)->create(['process_id' => 'history', 'process_version' => '0.9', 'process_title' => 'Older service', 'status' => 'accepted']);
    $other = ProcessExecution::factory()->create(['process_id' => 'history', 'process_version' => '1.0', 'process_title' => 'Historical service', 'status' => 'running']);
    $translated = historicalLocalizedProcess('it');
    $translated['title'] = "Titolo d'Italia 50%_!";
    app(OgcProcessCache::class)->putProcess('history', $translated, language: 'it');

    $this->actingAs($viewer)->withUnencryptedCookie('language', 'it')->get(route($route, ['search' => "Titolo d'Italia 50%_!", 'status' => 'failed']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('executions.total', 1)
        ->where('executions.data.0.id', $match->id)
        ->where('executions.data.0.processTitle', "Titolo d'Italia 50%_!")
        ->where('statusCounts.failed', 1)
        ->where('statusCounts', fn ($counts): bool => $counts->get('failed') === 1 && $counts->get('running') === ($admin ? 1 : null))
        ->missing('statusCounts.accepted'));

    if ($admin) {
        $this->get(route($route, ['search' => "Titolo d'Italia 50%_!", 'user' => Crypt::encryptString((string) $other->user_id)]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('executions.total', 1)
            ->where('executions.data.0.id', $other->id)
            ->where('statusCounts.running', 1)
            ->missing('statusCounts.failed'));
    }
    Http::assertNothingSent();
})->with(['personal' => ['jobs.index', false], 'admin' => ['admin.jobs.index', true]]);

test('job process sorting follows translated names with custom names and historical fallbacks', function (string $route, bool $admin, bool $descending) {
    $sorting = [['id' => 'process', 'desc' => $descending]];
    $settings = $admin ? ['admin' => ['jobs' => ['sorting' => $sorting]]] : ['jobs' => ['sorting' => $sorting]];
    $viewer = $admin ? User::factory()->admin()->create(['settings' => ['tables' => $settings]]) : User::factory()->create(['settings' => ['tables' => $settings]]);
    $owner = $admin ? User::factory()->create() : $viewer;
    $alpha = ProcessExecution::factory()->for($owner)->create(['process_id' => 'translated-alpha', 'process_version' => '1.0', 'process_title' => 'Zulu stored', 'name' => null]);
    $zulu = ProcessExecution::factory()->for($owner)->create(['process_id' => 'translated-zulu', 'process_version' => '1.0', 'process_title' => 'Alpha stored', 'name' => null]);
    $custom = ProcessExecution::factory()->for($owner)->create(['process_id' => 'translated-alpha', 'process_version' => '1.0', 'process_title' => 'Zulu stored', 'name' => 'Middle custom']);
    $mismatch = ProcessExecution::factory()->for($owner)->create(['process_id' => 'translated-alpha', 'process_version' => '0.9', 'process_title' => 'Delta historical', 'name' => null]);
    $missing = ProcessExecution::factory()->for($owner)->create(['process_id' => 'uncached', 'process_version' => '1.0', 'process_title' => 'Echo fallback', 'name' => null]);
    $unknown = ProcessExecution::factory()->for($owner)->create(['process_id' => 'translated-alpha', 'process_version' => null, 'process_title' => 'Foxtrot historical', 'name' => null]);
    app(OgcProcessCache::class)->putProcess('translated-alpha', ['id' => 'translated-alpha', 'version' => '1.0', 'title' => 'Alpha translated'], language: 'it');
    app(OgcProcessCache::class)->putProcess('translated-zulu', ['id' => 'translated-zulu', 'version' => '1.0', 'title' => 'Zulu translated'], language: 'it');
    $expected = [$alpha->id, $mismatch->id, $missing->id, $unknown->id, $custom->id, $zulu->id];
    if ($descending) {
        $expected = array_reverse($expected);
    }

    $this->actingAs($viewer)->withUnencryptedCookie('language', 'it')->get(route($route))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('executions.data', fn ($rows): bool => $rows->pluck('id')->all() === $expected));
    expect($alpha->fresh()->process_title)->toBe('Zulu stored');
    Http::assertNothingSent();
})->with(['personal' => ['jobs.index', false], 'admin' => ['admin.jobs.index', true]])->with(['ascending' => false, 'descending' => true]);
