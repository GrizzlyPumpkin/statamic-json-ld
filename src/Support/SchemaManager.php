<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Support;

use Statamic\Fields\Value;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use GrizzlyPumpkin\StatamicJsonLd\Nodes\Custom;
use GrizzlyPumpkin\StatamicJsonLd\Nodes\Website;
use GrizzlyPumpkin\StatamicJsonLd\Contracts\Node;
use GrizzlyPumpkin\StatamicJsonLd\Nodes\ListItem;
use Statamic\Contracts\Entries\Entry as EntryContract;
use GrizzlyPumpkin\StatamicJsonLd\Nodes\CustomTemplate;
use GrizzlyPumpkin\StatamicJsonLd\Nodes\BreadcrumbList;
use GrizzlyPumpkin\StatamicJsonLd\Renderers\JsonLdRenderer;
use GrizzlyPumpkin\StatamicJsonLd\Nodes\ProfessionalService;
use GrizzlyPumpkin\StatamicJsonLd\Repositories\SettingsRepository;

class SchemaManager
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CurrentEntryResolver $entries,
        private readonly PropertyMapper $mapper,
        private readonly JsonLdRenderer $renderer,
    ) {
    }

    public function render(mixed $context): string
    {
        if (!$this->settings->boolean('enabled')) {
            return '';
        }

        $entry = $this->entries->resolve($context);

        $graph = $this->globalNodes();

        if ($entry) {
            $graph = array_merge($graph, $this->entryNodes($entry));
        }

        if ($this->settings->boolean('include_breadcrumb_schema') && $entry) {
            $graph[] = $this->breadcrumbNode($entry);
        }

        if ($graph === []) {
            return '';
        }

        return $this->renderer->script($graph, $this->templateData($context, $entry));
    }

    private function globalNodes(): array
    {
        $nodes = [];

        foreach ($this->settings->get('global_schema', []) as $config) {
            if (! is_array($config) || $this->boolean($config['enabled'] ?? true) === false) {
                continue;
            }

            $nodes[] = $this->makeNode($config);
        }

        return $nodes;
    }

    private function entryNodes(EntryContract $entry): array
    {
        $nodes = [];

        foreach ($this->collectionConfigs($entry) as $config) {
            foreach($config['schema'] as $schema) {
                $nodes[] = $this->makeNode($schema);
            }
        }

        return $nodes;
    }

    private function makeNode(array $config): Node
    {
        return match ($config['type']) {
            'website' => Website::fromArray($config, $this->makeId($config['type'])),
            'professional_service' => ProfessionalService::fromArray($config, $this->makeId($config['type'])),
            default => CustomTemplate::fromArray($config, $this->makeId($config['type'])),
        };
    }

    private function breadcrumbNode(EntryContract $entry): ?BreadcrumbList
    {
        $crumbs = $this->breadcrumbCrumbsForEntry($entry);

        if (count($crumbs) < 2) {
            $crumbs = $this->breadcrumbCrumbsForMount($entry);
        }

        $crumbs = $this->uniqueConsecutiveCrumbs($crumbs);

        if (count($crumbs) < 2) {
            return null;
        }

        $items = [];

        foreach ($crumbs as $index => $crumb) {
            $items[] = ListItem::fromArray([
                'position' => $index + 1,
                'name' => $crumb['name'],
                'item' => $crumb['item'],
            ], $this->makeId('breadcrumb_item') . '-' . $index);
        }

        return BreadcrumbList::fromArray([
            'items' => $items,
        ], $this->makeId('breadcrumb_list'));
    }

    private function breadcrumbCrumbsForEntry(EntryContract $entry): array
    {
        if (! method_exists($entry, 'page') || ! $page = $entry->page()) {
            return [];
        }

        return $this->crumbsFromPages($this->pageTrail($page));
    }

    private function breadcrumbCrumbsForMount(EntryContract $entry): array
    {
        $collection = $this->entryCollection($entry);

        if (! is_object($collection) || ! method_exists($collection, 'mount') || ! $mount = $collection->mount()) {
            return [];
        }

        $crumbs = method_exists($mount, 'page') && $mount->page()
            ? $this->crumbsFromPages($this->pageTrail($mount->page()))
            : [];

        if ($crumbs === []) {
            $crumb = $this->crumbFromEntry($mount, false);

            if ($crumb === null) {
                return [];
            }

            $crumbs[] = $crumb;
        }

        $entryCrumb = $this->crumbFromEntry($entry, true);

        if ($entryCrumb === null) {
            return [];
        }

        $crumbs[] = $entryCrumb;

        return $crumbs;
    }

    private function entryCollection(EntryContract $entry): mixed
    {
        return method_exists($entry, 'collection') ? $entry->collection() : null;
    }

    private function pageTrail(mixed $page): array
    {
        $pages = [];
        $seen = [];

        for ($depth = 0; $page && $depth < 50; $depth++) {
            $key = is_object($page) ? spl_object_id($page) : $depth;

            if (isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;
            $pages[] = $page;
            $page = is_object($page) && method_exists($page, 'parent') ? $page->parent() : null;
        }

        return array_reverse($pages);
    }

    private function crumbsFromPages(array $pages): array
    {
        $crumbs = [];

        foreach ($pages as $page) {
            $crumb = $this->crumbFromPage($page);

            if ($crumb === null) {
                return [];
            }

            $crumbs[] = $crumb;
        }

        return $crumbs;
    }

    private function crumbFromPage(mixed $page): ?array
    {
        $name = $this->pageTitle($page);
        $url = $this->pageUrl($page);

        return $name !== null && $url !== null ? [
            'name' => $name,
            'item' => $url,
        ] : null;
    }

    private function crumbFromEntry(mixed $entry, bool $allowCurrentUrlFallback): ?array
    {
        if (! $entry instanceof EntryContract) {
            return null;
        }

        $name = $this->entryTitle($entry);
        $url = $this->entryAbsoluteUrl($entry) ?? ($allowCurrentUrlFallback ? $this->entryUrl($entry) : null);

        return $name !== null && $url !== null ? [
            'name' => $name,
            'item' => $url,
        ] : null;
    }

    private function uniqueConsecutiveCrumbs(array $crumbs): array
    {
        $unique = [];
        $previousUrl = null;

        foreach ($crumbs as $crumb) {
            $url = $crumb['item'] ?? null;

            if ($url === null || $url === $previousUrl) {
                continue;
            }

            $unique[] = $crumb;
            $previousUrl = $url;
        }

        return $unique;
    }

    private function pageTitle(mixed $page): ?string
    {
        if (! is_object($page) || ! method_exists($page, 'title')) {
            return null;
        }

        return $this->stringValue($page->title());
    }

    private function entryTitle(EntryContract $entry): ?string
    {
        if (method_exists($entry, 'title')) {
            return $this->stringValue($entry->title());
        }

        return method_exists($entry, 'get') ? $this->stringValue($entry->get('title')) : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof Value) {
            $value = $value->value();
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function pageUrl(mixed $page): ?string
    {
        if (! is_object($page)) {
            return null;
        }

        foreach (['absoluteUrl', 'absolute_url', 'url'] as $method) {
            if (method_exists($page, $method)) {
                $url = $this->mapper->absoluteUrl($page->{$method}());

                if (is_string($url) && $url !== '') {
                    return rtrim($url, '/');
                }
            }
        }

        return null;
    }

    private function collectionConfigs(EntryContract $entry): array
    {
        $collection = method_exists($entry, 'collectionHandle')
            ? $entry->collectionHandle()
            : (method_exists($entry, 'collection') ? $entry->collection()?->handle() : null);

        return $collection ? $this->settings->collectionConfigs($collection) : [];
    }

    private function entryUrl(EntryContract $entry): string
    {
        if ($url = $this->entryAbsoluteUrl($entry)) {
            return $url;
        }

        return rtrim(URL::current(), '/');
    }

    private function entryAbsoluteUrl(EntryContract $entry): ?string
    {
        foreach (['absoluteUrl', 'absolute_url', 'url'] as $method) {
            if (method_exists($entry, $method)) {
                $url = $this->mapper->absoluteUrl($entry->{$method}());

                if (is_string($url) && $url !== '') {
                    return rtrim($url, '/');
                }
            }
        }

        return null;
    }

    private function siteUrl(): string
    {
        return rtrim((string) ($this->settings->get('site_url') ?: config('app.url') ?: URL::to('/')), '/');
    }

    private function makeId(string $type): string
    {
        return $this->siteUrl() . '/#' . Str::kebab($type ?: 'thing');
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

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
