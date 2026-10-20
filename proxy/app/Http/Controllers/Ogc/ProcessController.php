<?php

namespace App\Http\Controllers\Ogc;

use App\Http\Controllers\Controller;
use App\Models\ProcessExecution;
use App\Services\Ogc\OgcProcessCache;
use App\Services\Ogc\OgcProcessCacheWarmupDispatcher;
use App\Services\Ogc\ProcessExecutionInputPrefill;
use App\Services\Ogc\ProcessSchemaNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProcessController extends Controller
{
    public function __construct(
        private OgcProcessCache $cache,
        private OgcProcessCacheWarmupDispatcher $warmupDispatcher,
        private ProcessSchemaNormalizer $normalizer,
        private ProcessExecutionInputPrefill $inputPrefill,
    ) {}

    public function index(): Response
    {
        $catalog = $this->cache->catalog();

        if (! $this->cache->hasFreshCatalog()) {
            $this->warmupDispatcher->dispatchForCacheMiss();
        }

        return Inertia::render('processes/index', [
            'catalogStatus' => $catalog === null ? 'warming' : 'ready',
            'catalogLastUpdatedAt' => $this->cache->catalogLastUpdatedAt(),
            'processes' => $catalog['processes'] ?? [],
        ]);
    }

    public function show(Request $request, string $process): Response
    {
        $sourceExecution = $this->sourceExecution($request, $process);
        $description = $this->cache->process($process);

        if (! $this->cache->hasFreshProcess($process)) {
            $this->warmupDispatcher->dispatchForCacheMiss();
        }

        if ($description === null) {
            return Inertia::render('processes/show', [
                'process' => null,
                'processStatus' => 'warming',
                'processLastUpdatedAt' => null,
                'formSchema' => null,
                'inputPrefill' => null,
            ]);
        }

        $formSchema = $this->normalizer->normalize($description);
        $formSchema['examples'] = $this->examples($description, $formSchema['fields']);

        return Inertia::render('processes/show', [
            'process' => $description,
            'processStatus' => 'ready',
            'processLastUpdatedAt' => $this->cache->processLastUpdatedAt($process),
            'formSchema' => $formSchema,
            'inputPrefill' => $sourceExecution === null
                ? null
                : $this->inputPrefill->build(
                    $sourceExecution,
                    $formSchema['fields'],
                ),
        ]);
    }

    private function sourceExecution(Request $request, string $process): ?ProcessExecution
    {
        if (! $request->filled('sourceJob')) {
            return null;
        }

        $sourceJobId = $request->integer('sourceJob');

        abort_if($sourceJobId < 1, 404);

        $sourceExecution = ProcessExecution::query()->findOrFail($sourceJobId);

        Gate::authorize('view', $sourceExecution);
        abort_unless($sourceExecution->process_id === $process, 404);

        return $sourceExecution;
    }

    /**
     * @param  array<string, mixed>  $description
     * @param  array<string, array<string, mixed>>  $fields
     * @return list<array{id: string, title: string, description: ?string, inputs: array<string, mixed>}>
     */
    private function examples(array $description, array $fields): array
    {
        $compatibleExamples = [];

        foreach (['examples', 'example'] as $key) {
            $examples = $description[$key] ?? [];

            if (! is_array($examples)) {
                continue;
            }

            foreach ($examples as $example) {
                if (! is_array($example)) {
                    continue;
                }

                $hasMetadata = array_key_exists('value', $example);
                $payloads = $hasMetadata ? ['value' => $example['value']] : $example;

                foreach ($payloads as $name => $payload) {
                    $id = $hasMetadata ? ($example['id'] ?? null) : $name;

                    if (! is_string($id) || trim($id) === '' || ! is_array($payload)) {
                        continue;
                    }

                    $inputs = $payload['inputs'] ?? null;

                    if (! is_array($inputs) || array_is_list($inputs)) {
                        continue;
                    }

                    $inputs = array_intersect_key($inputs, $fields);

                    if ($inputs === []) {
                        continue;
                    }

                    $title = $hasMetadata ? ($example['title'] ?? null) : $name;
                    $exampleDescription = $hasMetadata ? ($example['description'] ?? null) : null;

                    $compatibleExamples[] = [
                        'id' => $id,
                        'title' => is_string($title) && trim($title) !== '' ? $title : $id,
                        'description' => is_string($exampleDescription) ? $exampleDescription : null,
                        'inputs' => $inputs,
                    ];
                }
            }
        }

        return $compatibleExamples;
    }
}
