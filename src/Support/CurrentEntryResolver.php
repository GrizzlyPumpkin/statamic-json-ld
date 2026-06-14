<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Support;

use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Value;
use Throwable;

class CurrentEntryResolver
{
    public function resolve(mixed $context, mixed $entry = null): ?EntryContract
    {
        if ($entry instanceof EntryContract) {
            return $entry;
        }

        if (is_string($entry) && $entry !== '') {
            return EntryFacade::find($entry);
        }

        $candidate = $this->contextValue($context, 'entry');

        if ($candidate instanceof EntryContract) {
            return $candidate;
        }

        $id = $this->contextValue($context, 'id') ?? $this->contextValue($context, 'entry_id');

        if (is_string($id) && $id !== '') {
            return EntryFacade::find($id);
        }

        return $this->requestEntry();
    }

    private function requestEntry(): ?EntryContract
    {
        $request = request();

        foreach ($this->requestCandidates($request) as $candidate) {
            if ($candidate instanceof EntryContract) {
                return $candidate;
            }

            if (is_string($candidate) && $candidate !== '' && $entry = EntryFacade::find($candidate)) {
                return $entry;
            }
        }

        foreach ($this->uriCandidates($request) as $uri) {
            if ($entry = $this->findByUri($uri)) {
                return $entry;
            }
        }

        return null;
    }

    private function requestCandidates(Request $request): array
    {
        $route = $request->route();

        return array_filter([
            $route?->parameter('entry'),
            $route?->parameter('content'),
            $request->attributes->get('entry'),
            $request->attributes->get('content'),
            $request->attributes->get('statamic.entry'),
            $request->attributes->get('statamic.content'),
        ]);
    }

    private function uriCandidates(Request $request): array
    {
        $path = $request->getPathInfo() ?: '/';
        $paths = [$path, rawurldecode($path), rtrim($path, '/') ?: '/', ltrim($path, '/')];
        $sitePath = $this->currentSitePath();

        if ($sitePath && $sitePath !== '/' && str_starts_with($path, $sitePath)) {
            $relative = '/'.ltrim(substr($path, strlen($sitePath)), '/');
            $paths[] = $relative ?: '/';
            $paths[] = rtrim($relative, '/') ?: '/';
            $paths[] = ltrim($relative, '/');
        }

        return array_values(array_unique(array_filter($paths)));
    }

    private function findByUri(string $uri): ?EntryContract
    {
        $site = $this->currentSiteHandle();

        if ($site) {
            try {
                if ($entry = EntryFacade::findByUri($uri, $site)) {
                    return $entry;
                }
            } catch (Throwable) {
                // Fall through to the site-agnostic lookup.
            }
        }

        try {
            return EntryFacade::findByUri($uri);
        } catch (Throwable) {
            return null;
        }
    }

    private function currentSiteHandle(): ?string
    {
        try {
            $site = Site::current();

            return is_object($site) && method_exists($site, 'handle') ? $site->handle() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function currentSitePath(): ?string
    {
        try {
            $site = Site::current();
            $url = is_object($site) && method_exists($site, 'url') ? $site->url() : null;
            $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

            return $path ? '/'.trim($path, '/') : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function contextValue(mixed $context, string $key): mixed
    {
        $value = null;

        if (is_array($context)) {
            $value = $context[$key] ?? null;
        } elseif (is_object($context) && method_exists($context, 'get')) {
            $value = $context->get($key);
        }

        return $value instanceof Value ? $value->raw() : $value;
    }
}
