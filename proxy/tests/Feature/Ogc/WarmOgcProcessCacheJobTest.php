<?php

use App\Jobs\Ogc\WarmOgcProcessCacheJob;
use App\Services\Ogc\OgcProcessCache;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();

    config([
        'services.ogc_processes.base_url' => 'https://voice.pi.ingv.it/geoinquire/',
        'services.ogc_processes.cache_ttl' => 300,
    ]);
});

test('each language warms in its own queued execution', function (string $language, string $otherLanguage) {
    Http::fake([
        "https://voice.pi.ingv.it/geoinquire/processes?f=json&lang={$language}" => Http::response(ogcFixture('processes')),
        "https://voice.pi.ingv.it/geoinquire/processes/solwcad?f=json&lang={$language}" => Http::response(ogcFixture('process-solwcad')),
        "https://voice.pi.ingv.it/geoinquire/processes/conduit?f=json&lang={$language}" => Http::response(ogcFixture('process-conduit')),
        "https://voice.pi.ingv.it/geoinquire/processes/pybox?f=json&lang={$language}" => Http::response(ogcFixture('process-pybox')),
    ]);

    (new WarmOgcProcessCacheJob($language))->handle(app(OgcProcessCache::class));

    $cache = app(OgcProcessCache::class);

    expect($cache->catalog($language)['processes'])->toHaveCount(3)
        ->and($cache->process('conduit', $language)['id'])->toBe('conduit')
        ->and($cache->catalog($otherLanguage))->toBeNull()
        ->and($cache->process('conduit', $otherLanguage))->toBeNull();
    Http::assertSentCount(4);
})->with([
    'italian' => ['it', 'en'],
    'english' => ['en', 'it'],
]);

test('it prevents overlapping warm-up executions', function () {
    $middleware = (new WarmOgcProcessCacheJob)->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('it retries with progressive backoff', function () {
    $job = new WarmOgcProcessCacheJob;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120, 300]);
});

test('queued payloads without a language keep the original italian default', function () {
    $job = new WarmOgcProcessCacheJob;
    unset($job->language);

    $restored = unserialize(serialize($job));

    expect($restored->language)->toBe('it');
});

test('warming another language can run while duplicate work for the same language is blocked', function () {
    $italian = new WarmOgcProcessCacheJob('it');
    $english = new WarmOgcProcessCacheJob('en');
    $ranEnglish = false;
    $ranDuplicate = false;

    $italian->middleware()[0]->handle($italian, function () use ($italian, $english, &$ranEnglish, &$ranDuplicate): void {
        $english->middleware()[0]->handle($english, function () use (&$ranEnglish): void {
            $ranEnglish = true;
        });
        $italian->middleware()[0]->handle($italian, function () use (&$ranDuplicate): void {
            $ranDuplicate = true;
        });
    });

    expect($ranEnglish)->toBeTrue()
        ->and($ranDuplicate)->toBeFalse();
});
