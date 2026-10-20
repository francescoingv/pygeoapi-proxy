<?php

namespace App\Services\Ogc;

use App\Support\Ogc\OgcLanguage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class OgcProcessCache
{
    public const CatalogKey = 'ogc-processes.catalog';

    public const LatestCatalogKey = 'ogc-processes.latest.catalog';

    public const WarmupLockKey = 'ogc-processes.warmup.lock';

    public const WarmupDispatchedKey = 'ogc-processes.warmup.dispatched';

    public function __construct(private OgcProcessesClient $client) {}

    /**
     * @return array<string, mixed>|null
     */
    public function catalog(?string $language = null): ?array
    {
        return $this->freshCatalog($language) ?? $this->latestCatalog($language);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function freshCatalog(?string $language = null): ?array
    {
        $catalog = Cache::get($this->catalogKey($language));

        return is_array($catalog) ? $catalog : null;
    }

    public function hasFreshCatalog(?string $language = null): bool
    {
        return $this->freshCatalog($language) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function process(string $processId, ?string $language = null): ?array
    {
        return $this->freshProcess($processId, $language) ?? $this->latestProcess($processId, $language);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function freshProcess(string $processId, ?string $language = null): ?array
    {
        $process = Cache::get($this->processKey($processId, $language));

        return is_array($process) ? $process : null;
    }

    public function hasFreshProcess(string $processId, ?string $language = null): bool
    {
        return $this->freshProcess($processId, $language) !== null;
    }

    public function catalogLastUpdatedAt(?string $language = null): ?string
    {
        return $this->latestUpdatedAt($this->latestCatalogKey($language));
    }

    public function processLastUpdatedAt(string $processId, ?string $language = null): ?string
    {
        return $this->latestUpdatedAt($this->latestProcessKey($processId, $language));
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    public function putCatalog(array $catalog, ?string $updatedAt = null, ?string $language = null): void
    {
        $updatedAt ??= now()->toIso8601String();

        Cache::put($this->catalogKey($language), $catalog, $this->ttl());
        Cache::forever($this->latestCatalogKey($language), $this->latestPayload($catalog, $updatedAt));
    }

    /**
     * @param  array<string, mixed>  $process
     */
    public function putProcess(string $processId, array $process, ?string $updatedAt = null, ?string $language = null): void
    {
        $updatedAt ??= now()->toIso8601String();

        Cache::put($this->processKey($processId, $language), $process, $this->ttl());
        Cache::forever($this->latestProcessKey($processId, $language), $this->latestPayload($process, $updatedAt));
    }

    public function warm(string $language = 'it'): void
    {
        $language = OgcLanguage::normalize($language);
        $loadedProcesses = 0;

        try {
            $catalog = $this->client->processes($language);
            $processes = [];

            foreach ($this->processIds($catalog) as $processId) {
                $processes[$processId] = $this->client->process($processId, $language);
                $loadedProcesses++;
            }

            $updatedAt = now()->toIso8601String();

            $this->putCatalog($catalog, $updatedAt, $language);

            foreach ($processes as $processId => $process) {
                $this->putProcess($processId, $process, $updatedAt, $language);
            }

            Log::info('OGC process cache warm-up completed.', [
                'base_url' => $this->baseUrl(),
                'language' => $language,
                'process_count' => $loadedProcesses,
            ]);
        } catch (Throwable $exception) {
            Log::error('OGC process cache warm-up failed.', [
                'base_url' => $this->baseUrl(),
                'language' => $language,
                'loaded_process_count' => $loadedProcesses,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<int, string>
     */
    public function processIds(array $catalog): array
    {
        $ids = [];

        foreach (($catalog['processes'] ?? []) as $process) {
            if (! is_array($process)) {
                continue;
            }

            $id = $process['id'] ?? null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    public function catalogKey(?string $language = null): string
    {
        return self::CatalogKey.'.'.$this->language($language);
    }

    public function latestCatalogKey(?string $language = null): string
    {
        return self::LatestCatalogKey.'.'.$this->language($language);
    }

    public function processKey(string $processId, ?string $language = null): string
    {
        return "ogc-processes.process.{$this->language($language)}.{$processId}";
    }

    public function latestProcessKey(string $processId, ?string $language = null): string
    {
        return "ogc-processes.latest.process.{$this->language($language)}.{$processId}";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestCatalog(?string $language): ?array
    {
        return $this->latestData($this->latestCatalogKey($language));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestProcess(string $processId, ?string $language): ?array
    {
        return $this->latestData($this->latestProcessKey($processId, $language));
    }

    /**
     * @return array{data: array<string, mixed>, updated_at: string}
     */
    private function latestPayload(array $data, string $updatedAt): array
    {
        return [
            'data' => $data,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestData(string $key): ?array
    {
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            return null;
        }

        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    private function latestUpdatedAt(string $key): ?string
    {
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            return null;
        }

        $updatedAt = $payload['updated_at'] ?? null;

        return is_string($updatedAt) && $updatedAt !== '' ? $updatedAt : null;
    }

    private function ttl(): int
    {
        return max(1, (int) config('services.ogc_processes.cache_ttl', 300));
    }

    private function language(?string $language): string
    {
        return OgcLanguage::normalize($language ?? app()->getLocale());
    }

    private function baseUrl(): string
    {
        return (string) config('services.ogc_processes.base_url');
    }
}
