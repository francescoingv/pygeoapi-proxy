<?php

use App\Jobs\Ogc\WarmOgcProcessCacheJob;
use App\Services\Ogc\OgcProcessCache;
use App\Services\Ogc\OgcProcessCacheWarmupDispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('it dispatches warm-up for queue runtime commands once per window', function () {
    Bus::fake();

    $dispatcher = app(OgcProcessCacheWarmupDispatcher::class);

    expect($dispatcher->dispatchForCommand('queue:work'))->toBeTrue()
        ->and(Cache::has(OgcProcessCache::WarmupDispatchedKey.'.it'))->toBeTrue()
        ->and(Cache::has(OgcProcessCache::WarmupDispatchedKey.'.en'))->toBeTrue()
        ->and($dispatcher->dispatchForCommand('queue:work'))->toBeFalse();

    Bus::assertDispatchedTimes(WarmOgcProcessCacheJob::class, 2);
    Bus::assertDispatched(WarmOgcProcessCacheJob::class, fn ($job): bool => $job->language === 'it');
    Bus::assertDispatched(WarmOgcProcessCacheJob::class, fn ($job): bool => $job->language === 'en');
});

test('a failed language dispatch does not block the other language or its own next attempt', function () {
    $queuedLanguages = [];
    $failed = false;
    Bus::shouldReceive('dispatch')->times(3)->andReturnUsing(function (WarmOgcProcessCacheJob $job) use (&$queuedLanguages, &$failed): void {
        if ($job->language === 'it' && ! $failed) {
            $failed = true;
            throw new RuntimeException('Temporary enqueue failure');
        }

        $queuedLanguages[] = $job->language;
    });
    $dispatcher = app(OgcProcessCacheWarmupDispatcher::class);

    expect($dispatcher->dispatchForCacheMiss())->toBeTrue()
        ->and($queuedLanguages)->toBe(['en'])
        ->and(Cache::has(OgcProcessCache::WarmupDispatchedKey.'.it'))->toBeFalse()
        ->and(Cache::has(OgcProcessCache::WarmupDispatchedKey.'.en'))->toBeTrue()
        ->and($dispatcher->dispatchForCacheMiss())->toBeTrue()
        ->and($queuedLanguages)->toBe(['en', 'it'])
        ->and(Cache::has(OgcProcessCache::WarmupDispatchedKey.'.it'))->toBeTrue();
});

test('it dispatches warm-up for horizon commands', function () {
    Bus::fake();

    expect(app(OgcProcessCacheWarmupDispatcher::class)->dispatchForCommand('horizon'))->toBeTrue();

    Bus::assertDispatched(WarmOgcProcessCacheJob::class);
});

test('it skips non queue runtime commands', function () {
    Bus::fake();

    $dispatcher = app(OgcProcessCacheWarmupDispatcher::class);

    expect($dispatcher->dispatchForCommand('migrate'))->toBeFalse()
        ->and($dispatcher->dispatchForCommand('route:list'))->toBeFalse()
        ->and($dispatcher->dispatchForCommand('config:cache'))->toBeFalse()
        ->and($dispatcher->dispatchForCommand(null))->toBeFalse();

    Bus::assertNotDispatched(WarmOgcProcessCacheJob::class);
});

test('it skips automatic dispatch while tests are running', function () {
    Bus::fake();

    expect(app(OgcProcessCacheWarmupDispatcher::class)->dispatchIfAppropriate())->toBeFalse();

    Bus::assertNotDispatched(WarmOgcProcessCacheJob::class);
});
