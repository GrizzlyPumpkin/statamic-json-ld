<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Nodes;

use Statamic\Facades\Antlers;

class CustomTemplate extends Node
{
    public string $template;

    public static function fromArray(array $data, string $id): static
    {
        $self = new self;
        $self->template = $data['json_template'];
        return $self;
    }

    public function renderToArray(array $data = []): array
    {
        return json_decode((string) Antlers::parse($this->template, $data), true);
    }
}