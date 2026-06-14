<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Support;

use Illuminate\Support\Collection;
use Statamic\Facades\Addon;

class SettingsRepository
{
    public const ADDON = 'grizzlypumpkin/statamic-json-ld';

    public function all(): array
    {
        $settings = Addon::get(self::ADDON)?->settings()->all() ?? [];

        if ($settings instanceof Collection) {
            $settings = $settings->all();
        }

        return array_replace_recursive($this->defaults(), $this->normalise(is_array($settings) ? $settings : []));
    }

    public function collectionConfigs(string $handle, array $settings): array
    {
        $configs = [];

        foreach ($settings['collection_schemas'] ?? [] as $config) {
            if (! is_array($config)) {
                continue;
            }

            $collection = $this->firstValue($config['collection'] ?? null);

            if ($collection !== $handle) {
                continue;
            }

            if ($this->boolean($config['enabled'] ?? true) === false) {
                continue;
            }

            $configs[] = $config;
        }

        return $configs;
    }

    private function firstValue(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return reset($value) ?: null;
        }

        return $value;
    }

    private function normalise(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (! str_contains((string) $key, '.')) {
                continue;
            }

            data_set($settings, $key, $value);
            unset($settings[$key]);
        }

        return $settings;
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function defaults(): array
    {
        return [
            'enabled' => true,
            'site_url' => null,
            'pretty_print' => false,
            'include_global_schema' => true,
            'include_breadcrumb_schema' => false,
            'global_schema' => [],
            'collection_schemas' => [],
        ];
    }
}
