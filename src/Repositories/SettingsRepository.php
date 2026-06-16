<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Repositories;

use Statamic\Support\Arr;
use Statamic\Facades\Addon;

class SettingsRepository
{
    public const ADDON = 'grizzlypumpkin/statamic-json-ld';

    protected array $settings;

    public function get(string|null $key = null, mixed $default = null): mixed
    {
        if(!isset($this->settings)) {
            $this->settings = array_replace_recursive(
                $this->defaults(),
                Addon::get(self::ADDON)?->settings()->all() ?? [],
            );
        }

        if(!is_null($key)) {
            return Arr::get($this->settings, $key, $default);
        }

        return $this->settings;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function collectionConfigs(string $handle): array
    {
        return collect($this->get('collection_schemas'))
            ->filter(function(array $config) use($handle) {
                return $config['collection'] === $handle
                    && filter_var($config['enabled'], FILTER_VALIDATE_BOOLEAN);
            })
            ->toArray();
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
