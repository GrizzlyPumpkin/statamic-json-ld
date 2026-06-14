<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Tags;

use GrizzlyPumpkin\StatamicJsonLd\Support\SchemaManager;
use Statamic\Tags\Tags;

class JsonLd extends Tags
{
    protected static $handle = 'json_ld';

    public function index(): string
    {
        return app(SchemaManager::class)->script($this->context, $this->params);
    }
}
