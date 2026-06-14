<?php

namespace GrizzlyPumpkin\StatamicJsonLd;

use GrizzlyPumpkin\StatamicJsonLd\Tags\JsonLd;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        JsonLd::class,
    ];
}
