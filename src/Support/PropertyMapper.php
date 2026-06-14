<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Antlers;
use Statamic\Facades\Asset;
use Statamic\Fields\Value;

class PropertyMapper
{
    public function map(EntryContract $entry, array $mappings): array
    {
        $schema = [];

        foreach ($mappings as $mapping) {
            $property = $this->schemaProperty($mapping);

            if (! is_array($mapping) || $property === '') {
                continue;
            }

            $value = $this->resolveValue($entry, $mapping);

            if ($this->isBlank($value) && array_key_exists('fallback', $mapping)) {
                $value = $mapping['fallback'];
            }

            $value = $this->transform($value, $mapping['transform'] ?? 'none');

            if ($this->isBlank($value)) {
                continue;
            }

            $this->setProperty($schema, $property, $value);
        }

        return $schema;
    }

    public function mapValues(array $mappings): array
    {
        $schema = [];

        foreach ($mappings as $mapping) {
            $property = $this->schemaProperty($mapping);

            if (! is_array($mapping) || $property === '') {
                continue;
            }

            $value = $this->resolveStaticValue($mapping);

            if ($this->isBlank($value) && array_key_exists('fallback', $mapping)) {
                $value = $mapping['fallback'];
            }

            $value = $this->transform($value, $mapping['transform'] ?? 'none');

            if ($this->isBlank($value)) {
                continue;
            }

            $this->setProperty($schema, $property, $value);
        }

        return $schema;
    }

    public function transform(mixed $value, ?string $transform): mixed
    {
        $value = $this->normaliseValue($value);

        return match ($transform ?: 'none') {
            'absolute_url' => $this->absoluteUrl($value),
            'asset_url' => $this->firstUrl($value),
            'asset_urls' => $this->urls($value),
            'iso8601' => $this->iso8601($value),
            'strip_tags' => is_string($value) ? trim(strip_tags($value)) : $value,
            'array' => $this->arrayValue($value),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'integer' => is_numeric($value) ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            default => $value,
        };
    }

    public function absoluteUrl(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(fn ($item) => $this->absoluteUrl($item), $value),
                fn ($item) => ! $this->isBlank($item)
            ));
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^https?:\/\//', $value)) {
            return $value;
        }

        return URL::to($value);
    }

    public function firstUrl(mixed $value): mixed
    {
        $urls = $this->urls($value);

        return is_array($urls) ? ($urls[0] ?? null) : $urls;
    }

    public function urls(mixed $value): mixed
    {
        $value = $this->normaliseValue($value);

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return array_values(array_filter(
                array_map(fn ($item) => $this->urlFromValue($item), $value),
                fn ($item) => ! $this->isBlank($item)
            ));
        }

        return $this->urlFromValue($value);
    }

    public function prune(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $item = $this->prune($item);

            if (! $this->isBlank($item)) {
                $clean[$key] = $item;
            }
        }

        return array_is_list($value) ? array_values($clean) : $clean;
    }

    private function resolveValue(EntryContract $entry, array $mapping): mixed
    {
        if (! array_key_exists('source', $mapping)) {
            return is_string($mapping['value'] ?? null)
                ? $this->parseEntryValue($entry, $mapping['value'])
                : $this->normaliseValue($mapping['value'] ?? null);
        }

        $source = $mapping['source'] ?? 'field';
        $value = $mapping['value'] ?? null;

        return match ($source) {
            'literal' => $value,
            'template' => $this->parseTemplate($entry, (string) $value),
            'raw_json' => $this->decodeJson($value),
            'url' => $this->entryUrl($entry),
            default => is_string($value) ? $this->fieldValue($entry, $value) : null,
        };
    }

    private function resolveStaticValue(array $mapping): mixed
    {
        $source = $mapping['source'] ?? 'literal';
        $value = $mapping['value'] ?? null;

        return match ($source) {
            'raw_json' => $this->decodeJson($value),
            default => $value,
        };
    }

    private function schemaProperty(mixed $mapping): string
    {
        if (! is_array($mapping)) {
            return '';
        }

        return trim((string) ($mapping['property'] ?? $mapping['schema_property'] ?? ''));
    }

    private function parseEntryValue(EntryContract $entry, string $template): mixed
    {
        $parsed = trim(Antlers::parse($template, $this->entryData($entry)));

        return $this->decodeJsonObject($parsed) ?? $parsed;
    }

    private function fieldValue(EntryContract $entry, string $path): mixed
    {
        $segments = preg_split('/[.:]/', $path) ?: [];
        $field = array_shift($segments);

        if (! $field) {
            return null;
        }

        $value = method_exists($entry, 'augmentedValue')
            ? $entry->augmentedValue($field)
            : $entry->get($field);

        $value = $this->normaliseValue($value);

        foreach ($segments as $segment) {
            $value = $this->segmentValue($value, $segment);
            $value = $this->normaliseValue($value);
        }

        return $value;
    }

    private function segmentValue(mixed $value, string $segment): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_values(array_filter(
                    array_map(fn ($item) => $this->segmentValue($item, $segment), $value),
                    fn ($item) => ! $this->isBlank($item)
                ));
            }

            return Arr::get($value, $segment);
        }

        if (is_object($value)) {
            if (method_exists($value, $segment)) {
                return $value->{$segment}();
            }

            if (method_exists($value, 'get')) {
                return $value->get($segment);
            }

            if (isset($value->{$segment})) {
                return $value->{$segment};
            }
        }

        return null;
    }

    private function parseTemplate(EntryContract $entry, string $template): mixed
    {
        return Antlers::parse($template, $this->entryData($entry));
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function setProperty(array &$schema, string $path, mixed $value): void
    {
        $segments = array_filter(explode('.', $path), fn ($segment) => $segment !== '');
        $target = &$schema;

        foreach ($segments as $index => $segment) {
            if ($index === array_key_last($segments)) {
                $target[$segment] = $value;
                break;
            }

            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }
    }

    private function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof Value) {
            return $value->value();
        }

        return $value;
    }

    private function entryData(EntryContract $entry): array
    {
        $data = method_exists($entry, 'toAugmentedArray') ? $this->arrayFromValue($entry->toAugmentedArray()) : [];
        $data = $this->normaliseTemplateData($data);
        $data['id'] ??= method_exists($entry, 'id') ? $entry->id() : null;
        $data['title'] ??= method_exists($entry, 'title') ? $entry->title() : null;
        $data['url'] ??= $this->entryUrl($entry);

        return $data;
    }

    private function arrayFromValue(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->all();
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'all')) {
            return $value->all();
        }

        return [];
    }

    private function normaliseTemplateData(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof Value) {
                $data[$key] = $value->value();
            } elseif ($value instanceof Collection) {
                $data[$key] = $this->normaliseTemplateData($value->all());
            } elseif (is_array($value)) {
                $data[$key] = $this->normaliseTemplateData($value);
            }
        }

        return $data;
    }

    private function decodeJsonObject(string $value): mixed
    {
        $value = trim($value);

        if (! str_starts_with($value, '{') && ! str_starts_with($value, '[')) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function urlFromValue(mixed $value): ?string
    {
        $value = $this->normaliseValue($value);

        if (is_string($value)) {
            if (str_contains($value, '::')) {
                $asset = Asset::find($value);

                if ($asset) {
                    return $this->absoluteUrl($asset->url());
                }
            }

            return $this->absoluteUrl($value);
        }

        if (is_object($value)) {
            foreach (['absoluteUrl', 'absolute_url', 'url', 'permalink'] as $method) {
                if (method_exists($value, $method)) {
                    return $this->absoluteUrl($value->{$method}());
                }
            }
        }

        return null;
    }

    private function entryUrl(EntryContract $entry): ?string
    {
        foreach (['absoluteUrl', 'absolute_url', 'url'] as $method) {
            if (method_exists($entry, $method)) {
                return $this->absoluteUrl($entry->{$method}());
            }
        }

        return null;
    }

    private function iso8601(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toAtomString();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_string($value) && strtotime($value) !== false) {
            return date(DateTimeInterface::ATOM, strtotime($value));
        }

        return null;
    }

    private function arrayValue(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->values()->all();
        }

        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value)) {
            return array_values(array_filter(
                array_map('trim', preg_split('/[\n|,]/', $value) ?: []),
                fn ($item) => ! $this->isBlank($item)
            ));
        }

        return $value === null ? [] : [$value];
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
