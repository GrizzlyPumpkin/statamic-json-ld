<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Antlers;
use Statamic\Fields\Value;

class SchemaManager
{
    // TODO: Replace this to work like the collections
    private const GLOBAL_TYPES = [
        'organization' => 'Organization',
        'website' => 'WebSite',
        'professional_service' => 'ProfessionalService',
        'local_business' => 'LocalBusiness',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CurrentEntryResolver $entries,
        private readonly PropertyMapper $mapper,
        private readonly JsonLdRenderer $renderer,
    ) {
    }

    public function script(mixed $context, mixed $params): string
    {
        $settings = $this->settings->all();

        if ($this->boolean($settings['enabled'] ?? true) === false) {
            return '';
        }

        $pretty = $this->boolean($this->param($params, 'pretty', $settings['pretty_print'] ?? false));
        $entry = $this->entries->resolve($context, $this->param($params, 'entry'));
        $collectionConfigs = $entry ? $this->collectionConfigs($entry, $settings) : [];
        $graph = $this->globalNodes($settings, $context, $entry);

        foreach ($collectionConfigs as $config) {
            $graph = array_merge($graph, $this->entryNodes($entry, $config, $settings, $context));
        }

        $graph = $this->mapper->prune($graph);

        if ($graph === []) {
            return '';
        }

        return $this->renderer->script([
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ], $pretty);
    }

    private function globalNodes(array $settings, mixed $context, ?EntryContract $entry): array
    {
        $nodes = [];

        foreach ($settings['global_schema'] ?? [] as $config) {
            if (! is_array($config) || $this->boolean($config['enabled'] ?? true) === false) {
                continue;
            }

            if ($this->setType($config) === 'other') {
                $nodes = array_merge($nodes, $this->templateNodes($config['json_template'] ?? null, $this->templateData($context, $entry)));

                continue;
            }

            $nodes[] = $this->globalNode($config, $settings);
        }

        return $nodes;
    }

    private function globalNode(array $config, array $settings): array
    {
        $set = $this->setType($config);

        $node = match ($set) {
            'website' => $this->websiteNode($config, $settings),
            'contact_point' => $this->contactPointNode($config, $settings),
            'postal_address' => $this->postalAddressNode($config, $settings),
            default => $this->genericGlobalNode($config, $settings),
        };

        return $this->mergeConfiguredProperties($node, $config);
    }

    private function genericGlobalNode(array $config, array $settings): array
    {
        $siteUrl = $this->siteUrl($settings);
        $set = $this->setType($config);

        return [
            '@type' => self::GLOBAL_TYPES[$set] ?? 'Thing',
            '@id' => $this->globalNodeId($config, $set, $siteUrl),
            'name' => $config['name'] ?? null,
            'url' => ! empty($config['url']) ? $this->mapper->absoluteUrl($config['url']) : null,
            'logo' => $this->mapper->firstUrl($config['logo'] ?? null),
            'image' => $this->mapper->firstUrl($config['image'] ?? null),
            'telephone' => $config['telephone'] ?? null,
            'email' => $config['email'] ?? null,
            'priceRange' => $config['price_range'] ?? null,
            'serviceType' => $config['service_type'] ?? null,
            'areaServed' => $this->mapper->transform($config['area_served'] ?? null, 'array'),
            'sameAs' => $this->mapper->transform($config['same_as'] ?? null, 'array'),
            'address' => $this->addressNode($config['address'] ?? []),
            'provider' => ! empty($config['provider_id']) ? ['@id' => $config['provider_id']] : null,
        ];
    }

    private function websiteNode(array $config, array $settings): array
    {
        $siteUrl = $this->siteUrl($settings);
        $node = [
            '@type' => 'WebSite',
            '@id' => $this->globalNodeId($config, 'website', $siteUrl),
            'name' => $config['name'] ?? null,
            'url' => $this->mapper->absoluteUrl($config['url'] ?? $siteUrl),
            'publisher' => ($organizationId = $this->globalSetId($settings, 'organization')) ? ['@id' => $organizationId] : null,
        ];

        if (! empty($config['search_url_template'])) {
            $node['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $config['search_url_template'],
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $node;
    }

    private function contactPointNode(array $config, array $settings): array
    {
        $siteUrl = $this->siteUrl($settings);

        return [
            '@type' => 'ContactPoint',
            '@id' => $this->globalNodeId($config, 'contact_point', $siteUrl),
            'telephone' => $config['telephone'] ?? null,
            'email' => $config['email'] ?? null,
            'contactType' => $config['contact_type'] ?? null,
            'areaServed' => $this->mapper->transform($config['area_served'] ?? null, 'array'),
            'availableLanguage' => $this->mapper->transform($config['available_language'] ?? null, 'array'),
        ];
    }

    private function postalAddressNode(array $config, array $settings): array
    {
        $siteUrl = $this->siteUrl($settings);

        return array_merge([
            '@id' => $this->globalNodeId($config, 'postal_address', $siteUrl),
        ], $this->addressNode($config, true) ?? []);
    }

    private function entryNodes(EntryContract $entry, array $config, array $settings, mixed $context): array
    {
        $entryUrl = $this->entryUrl($entry);
        $schemaId = $entryUrl.'#schema';
        $schema = array_merge([
            '@type' => $config['schema_type'] === 'other'
                ? $config['schema_type_custom']
                : $config['schema_type'],
            '@id' => $schemaId,
            'url' => $entryUrl,
        ], $this->mapper->map($entry, $config['mappings'] ?? []));

        if ($decoded = $this->decodeJson($config['raw_json'] ?? null)) {
            $schema = array_replace_recursive($schema, $decoded);
        }

        $nodes = [];

        if ($this->boolean($config['include_webpage'] ?? true) === true) {
            $websiteId = $this->globalSetId($settings, 'website');
            $organizationId = $this->globalSetId($settings, 'organization');

            $nodes[] = [
                '@type' => 'WebPage',
                '@id' => $entryUrl.'#webpage',
                'url' => $entryUrl,
                'name' => method_exists($entry, 'title') ? $entry->title() : null,
                'isPartOf' => $websiteId ? ['@id' => $websiteId] : null,
                'about' => $organizationId ? ['@id' => $organizationId] : null,
                'mainEntity' => ['@id' => $schemaId],
            ];
        }

        $nodes[] = $schema;

        return $nodes;
    }

    private function mergeConfiguredProperties(array $node, array $config): array
    {
        $node = array_merge($node, $this->mapper->mapValues($config['properties'] ?? []));

        if ($raw = $this->decodeJson($config['raw_json'] ?? null)) {
            $node = array_replace_recursive($node, $raw);
        }

        return $node;
    }

    private function addressNode(array $address, bool $force = false): ?array
    {
        $node = [
            '@type' => 'PostalAddress',
            'streetAddress' => $address['street_address'] ?? null,
            'addressLocality' => $address['address_locality'] ?? null,
            'addressRegion' => $address['address_region'] ?? null,
            'postalCode' => $address['postal_code'] ?? null,
            'addressCountry' => $address['address_country'] ?? null,
        ];

        if ($force) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if ($key !== '@type' && ! in_array($value, [null, '', []], true)) {
                return $node;
            }
        }

        return null;
    }

    private function templateNodes(mixed $template, array $data): array
    {
        if (! is_string($template) || trim($template) === '') {
            return [];
        }

        $decoded = $this->decodeJson(Antlers::parse($template, $data));

        if (! $decoded) {
            return [];
        }

        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            return array_is_list($decoded['@graph']) ? $decoded['@graph'] : [$decoded['@graph']];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        unset($decoded['@context']);

        return [$decoded];
    }

    private function shouldIncludeGlobal(string $include, array $settings, array $collectionConfigs): bool
    {
        if (! in_array($include, ['all', 'global'], true)) {
            return false;
        }

        if ($this->boolean($settings['include_global_schema'] ?? true) === false) {
            return false;
        }

        foreach ($collectionConfigs as $config) {
            if ($this->boolean($config['include_global_schema'] ?? true) === false) {
                return false;
            }
        }

        return true;
    }

    private function shouldIncludeEntry(string $include): bool
    {
        return in_array($include, ['all', 'entry'], true);
    }

    private function collectionConfigs(EntryContract $entry, array $settings): array
    {
        $collection = method_exists($entry, 'collectionHandle')
            ? $entry->collectionHandle()
            : (method_exists($entry, 'collection') ? $entry->collection()?->handle() : null);

        return $collection ? $this->settings->collectionConfigs($collection, $settings) : [];
    }

    private function entryUrl(EntryContract $entry): string
    {
        foreach (['absoluteUrl', 'absolute_url', 'url'] as $method) {
            if (method_exists($entry, $method)) {
                $url = $this->mapper->absoluteUrl($entry->{$method}());

                if (is_string($url) && $url !== '') {
                    return rtrim($url, '/');
                }
            }
        }

        return rtrim(URL::current(), '/');
    }

    private function siteUrl(array $settings): string
    {
        return rtrim((string) ($settings['site_url'] ?: config('app.url') ?: URL::to('/')), '/');
    }

    private function globalNodeId(array $config, string $set, string $siteUrl): string
    {
        $id = trim((string) ($config['id'] ?? ''));

        if ($id === '') {
            $id = '#'.Str::kebab($set);
        }

        if (preg_match('/^https?:\/\//', $id)) {
            return $id;
        }

        if (str_starts_with($id, '#')) {
            return $siteUrl.'/'.$id;
        }

        return $siteUrl.'/#'.ltrim($id, '#/');
    }

    private function globalSetId(array $settings, string $set): ?string
    {
        $siteUrl = $this->siteUrl($settings);

        foreach ($settings['global_schema'] ?? [] as $config) {
            if (is_array($config) && $this->setType($config) === $set && $this->boolean($config['enabled'] ?? true)) {
                return $this->globalNodeId($config, $set, $siteUrl);
            }
        }

        return null;
    }

    private function templateData(mixed $context, ?EntryContract $entry = null): array
    {
        $data = $this->normaliseTemplateData($this->contextArray($context));

        if ($entry) {
            $entryData = method_exists($entry, 'toAugmentedArray') ? $this->arrayFromValue($entry->toAugmentedArray()) : [];
            $entryData = $this->normaliseTemplateData($entryData);
            $entryData['id'] ??= method_exists($entry, 'id') ? $entry->id() : null;
            $entryData['title'] ??= method_exists($entry, 'title') ? $entry->title() : null;
            $entryData['url'] ??= $this->entryUrl($entry);

            $data = array_replace($data, $entryData);
        }

        return $data;
    }

    private function contextArray(mixed $context): array
    {
        if ($context instanceof Collection) {
            return $context->all();
        }

        if (is_array($context)) {
            return $context;
        }

        if (is_object($context) && method_exists($context, 'all')) {
            return $context->all();
        }

        return [];
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
                $data[$key] = $value->all();
            } elseif (is_array($value)) {
                $data[$key] = $this->normaliseTemplateData($value);
            }
        }

        return $data;
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    private function setType(array $config): string
    {
        return (string) ($config['type'] ?? '');
    }

    private function param(mixed $params, string $key, mixed $default = null): mixed
    {
        if (is_array($params)) {
            return $params[$key] ?? $default;
        }

        if (is_object($params) && method_exists($params, 'get')) {
            return $params->get($key, $default);
        }

        return $default;
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
