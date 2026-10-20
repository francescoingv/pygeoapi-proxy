<?php

namespace App\Services\Ogc;

use App\Models\ProcessExecution;
use App\Models\ProcessExecutionResult;

class ProcessExecutionLocalization
{
    /** @var array<string, array<string, mixed>|null> */
    private array $processes = [];

    public function __construct(
        private OgcProcessCache $cache,
        private OgcTextNormalizer $textNormalizer,
    ) {}

    /** @return array<string, mixed>|null */
    public function process(ProcessExecution $execution): ?array
    {
        $version = $execution->process_version;

        if (! is_string($version) || trim($version) === '') {
            return null;
        }

        $language = app()->getLocale();
        $key = json_encode([$language, $execution->process_id, $version], JSON_THROW_ON_ERROR);

        if (! array_key_exists($key, $this->processes)) {
            $process = $this->cache->process($execution->process_id, $language);
            $this->processes[$key] = $process !== null && ($process['version'] ?? null) === $version
                ? $process
                : null;
        }

        return $this->processes[$key];
    }

    public function title(ProcessExecution $execution): ?string
    {
        return $this->translatedTitle($execution) ?? $execution->process_title;
    }

    public function translatedTitle(ProcessExecution $execution): ?string
    {
        return $this->text($this->process($execution)['title'] ?? null);
    }

    public function displayName(ProcessExecution $execution): string
    {
        $localizedExecution = clone $execution;
        $localizedExecution->process_title = $this->title($execution);

        return $localizedExecution->displayName();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function fields(ProcessExecution $execution, array $fields): array
    {
        $inputs = $this->process($execution)['inputs'] ?? [];

        foreach ($fields as $name => $field) {
            if (is_array($field) && is_array($inputs[$name] ?? null)) {
                $input = $inputs[$name];
                $fields[$name] = $this->field($field, $input['schema'] ?? [], $input);
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    public function outputs(ProcessExecution $execution): array
    {
        $outputs = $execution->process_outputs ?? [];
        $translated = $this->process($execution)['outputs'] ?? [];

        foreach ($outputs as $name => $output) {
            if (is_array($output) && is_array($translated[$name] ?? null)) {
                $outputs[$name] = $this->metadata($output, $translated[$name]);
            }
        }

        return $outputs;
    }

    /** @return array{title: string|null, description: string|null} */
    public function resultMetadata(ProcessExecution $execution, ProcessExecutionResult $result): array
    {
        $metadata = [
            'title' => $this->textNormalizer->normalize($result->title),
            'description' => $this->textNormalizer->normalize($result->description),
        ];
        $original = $execution->process_outputs[$result->output_id] ?? null;
        $translated = $this->process($execution)['outputs'][$result->output_id] ?? null;

        if (! is_array($original) || ! is_array($translated)) {
            return $metadata;
        }

        foreach (['title', 'description'] as $attribute) {
            $originalText = $this->text($original[$attribute] ?? null);
            $translatedText = $this->text($translated[$attribute] ?? null);

            if ($originalText !== null && $metadata[$attribute] === $originalText && $translatedText !== null) {
                $metadata[$attribute] = $translatedText;
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function field(array $field, array $schema, array $metadata = []): array
    {
        $field = $this->metadata($field, [
            'title' => $metadata['title'] ?? $schema['title'] ?? null,
            'description' => $metadata['description'] ?? $schema['description'] ?? null,
        ]);
        $properties = ($field['kind'] ?? null) === 'array_object'
            ? ($schema['items']['properties'] ?? [])
            : ($schema['properties'] ?? []);

        foreach ($field['fields'] ?? [] as $name => $child) {
            if (is_array($child) && is_array($properties[$name] ?? null)) {
                $field['fields'][$name] = $this->field($child, $properties[$name]);
            }
        }

        foreach ($field['variants'] ?? [] as $index => $variant) {
            $id = $variant['id'] ?? null;
            $translated = is_string($id) ? ($schema['oneOf'][$id] ?? null) : null;

            if (! is_array($translated)) {
                continue;
            }

            $variant = $this->field($variant, $translated);
            $label = $this->text($translated['title'] ?? null);
            $description = $this->text($translated['description'] ?? null);

            if ($label === null && $description !== null && mb_strlen($description) <= 80) {
                $label = $description;
            }

            if ($label !== null) {
                $variant['label'] = $label;
            }

            unset($variant['title']);
            $field['variants'][$index] = $variant;
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $translated
     * @return array<string, mixed>
     */
    private function metadata(array $stored, array $translated): array
    {
        foreach (['title', 'description'] as $attribute) {
            $text = $this->text($translated[$attribute] ?? null);

            if ($text !== null) {
                $stored[$attribute] = $text;
            }
        }

        return $stored;
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? $this->textNormalizer->normalize($value) : null;

        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
