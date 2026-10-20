<?php

use App\Services\Ogc\OgcProcessCache;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow();
    Cache::flush();
    Http::preventStrayRequests();

    config([
        'services.ogc_processes.base_url' => 'https://voice.pi.ingv.it/geoinquire/',
        'services.ogc_processes.cache_ttl' => 300,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('it warms only the requested language catalog and every process description', function (string $language, string $label) {
    $catalog = ogcFixture('processes');
    $catalog['processes'][0]['title'] = $label;
    $responses = ["https://voice.pi.ingv.it/geoinquire/processes?f=json&lang={$language}" => Http::response($catalog)];

    foreach (['solwcad', 'conduit', 'pybox'] as $processId) {
        $process = ogcFixture("process-{$processId}");
        $process['description'] = $label;
        $responses["https://voice.pi.ingv.it/geoinquire/processes/{$processId}?f=json&lang={$language}"] = Http::response($process);
    }

    Http::fake($responses);

    $cache = app(OgcProcessCache::class);

    $cache->warm($language);

    app()->setLocale($language);
    expect($cache->catalog()['processes'])->toHaveCount(3);
    expect($cache->catalog()['processes'][0]['title'])->toBe($label);
    expect($cache->catalog($language === 'it' ? 'en' : 'it'))->toBeNull();

    foreach (['solwcad', 'conduit', 'pybox'] as $processId) {
        expect($cache->process($processId)['description'])->toBe($label);
    }

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->url() === "https://voice.pi.ingv.it/geoinquire/processes?f=json&lang={$language}");
})->with([
    'italian' => ['it', 'Italiano'],
    'english' => ['en', 'English'],
]);

test('expired metadata and timestamps stay isolated by language', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:00:00Z'));
    $cache = app(OgcProcessCache::class);
    app()->setLocale('it');
    $cache->putCatalog(['processes' => [['id' => 'example', 'title' => 'Italiano']]]);
    $cache->putProcess('example', ['id' => 'example', 'title' => 'Italiano']);
    $this->travel(2)->minutes();
    app()->setLocale('en');
    $cache->putCatalog(['processes' => [['id' => 'example', 'title' => 'English']]]);
    $cache->putProcess('example', ['id' => 'example', 'title' => 'English']);
    $this->travel(6)->minutes();

    app()->setLocale('it');
    expect($cache->hasFreshCatalog())->toBeFalse();
    expect($cache->hasFreshProcess('example'))->toBeFalse();
    expect($cache->catalog()['processes'][0]['title'])->toBe('Italiano');
    expect($cache->process('example')['title'])->toBe('Italiano');
    expect(CarbonImmutable::parse($cache->catalogLastUpdatedAt())->utc()->toIso8601String())->toBe('2026-10-20T10:00:00+00:00');
    expect(CarbonImmutable::parse($cache->processLastUpdatedAt('example'))->utc()->toIso8601String())->toBe('2026-10-20T10:00:00+00:00');
    app()->setLocale('en');
    expect($cache->catalog()['processes'][0]['title'])->toBe('English');
    expect($cache->process('example')['title'])->toBe('English');
    expect(CarbonImmutable::parse($cache->catalogLastUpdatedAt())->utc()->toIso8601String())->toBe('2026-10-20T10:02:00+00:00');
    expect(CarbonImmutable::parse($cache->processLastUpdatedAt('example'))->utc()->toIso8601String())->toBe('2026-10-20T10:02:00+00:00');
    $this->travelBack();
});

test('a failed language keeps its last complete cache while the other language refreshes', function (string $failedLanguage, string $successfulLanguage) {
    Sleep::fake();
    $cache = app(OgcProcessCache::class);
    app()->setLocale($failedLanguage);
    $cache->putCatalog(['processes' => [['id' => 'example', 'title' => 'Previous']]]);
    $cache->putProcess('example', ['id' => 'example', 'title' => 'Previous']);
    $responses = [];

    foreach (['it', 'en'] as $language) {
        $responses["https://voice.pi.ingv.it/geoinquire/processes?f=json&lang={$language}"] = Http::response([
            'processes' => [['id' => 'example', 'title' => 'Updated']],
        ]);
        $responses["https://voice.pi.ingv.it/geoinquire/processes/example?f=json&lang={$language}"] = $language === $failedLanguage
            ? Http::response([], 503)
            : Http::response(['id' => 'example', 'title' => 'Updated']);
    }

    Http::fake($responses);

    expect(fn () => $cache->warm($failedLanguage))->toThrow(RequestException::class);
    $cache->warm($successfulLanguage);

    app()->setLocale($failedLanguage);
    expect($cache->catalog()['processes'][0]['title'])->toBe('Previous');
    expect($cache->process('example')['title'])->toBe('Previous');
    app()->setLocale($successfulLanguage);
    expect($cache->catalog()['processes'][0]['title'])->toBe('Updated');
    expect($cache->process('example')['title'])->toBe('Updated');
})->with([
    'italian failure' => ['it', 'en'],
    'english failure' => ['en', 'it'],
]);

test('it returns null for missing catalog and process cache entries', function () {
    $cache = app(OgcProcessCache::class);

    expect($cache->catalog())->toBeNull()
        ->and($cache->process('conduit'))->toBeNull();
});

test('it serves the latest successful catalog when the fresh catalog expires', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-02 10:30:00'));

    $cache = app(OgcProcessCache::class);

    $cache->putCatalog(ogcFixture('processes'));
    Cache::forget($cache->catalogKey());

    expect($cache->catalog()['processes'])->toHaveCount(3)
        ->and($cache->catalogLastUpdatedAt())->toBe(now()->toIso8601String());
});

test('it serves the latest successful process description when the fresh process expires', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-02 10:45:00'));

    $cache = app(OgcProcessCache::class);

    $cache->putProcess('conduit', ogcFixture('process-conduit'));
    Cache::forget($cache->processKey('conduit'));

    expect($cache->process('conduit')['id'])->toBe('conduit')
        ->and($cache->processLastUpdatedAt('conduit'))->toBe(now()->toIso8601String());
});

test('it extracts process ids from valid catalog entries only', function () {
    $cache = app(OgcProcessCache::class);

    expect($cache->processIds([
        'processes' => [
            ['id' => 'solwcad'],
            ['id' => ''],
            ['title' => 'Missing id'],
            ['id' => 'conduit'],
            ['id' => 'solwcad'],
            'invalid',
        ],
    ]))->toBe(['solwcad', 'conduit']);
});
