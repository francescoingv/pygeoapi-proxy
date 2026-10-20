<?php

namespace App\Jobs\Ogc;

use App\Services\Ogc\OgcProcessCache;
use App\Support\Ogc\OgcLanguage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class WarmOgcProcessCacheJob implements ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public string $language = 'it';

    public function __construct(string $language = 'it')
    {
        $this->language = OgcLanguage::normalize($language);
    }

    public function handle(OgcProcessCache $cache): void
    {
        $cache->warm($this->language);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(OgcProcessCache::WarmupLockKey.'.'.$this->language))
                ->shared()
                ->expireAfter(300)
                ->dontRelease(),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
