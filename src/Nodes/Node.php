<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Nodes;

use Statamic\Facades\Antlers;
use GrizzlyPumpkin\StatamicJsonLd\Contracts\Node as Contract;

abstract class Node implements Contract
{
    public string $type;
    public string $id;

    public static function fromArray(array $data, string $id): static
    {
        $self = new static;
        $self->id = $id;
        return $self;
    }

    public function renderToArray(array $data = []): array
    {
        $data = get_object_vars($this);
        $data = json_decode(Antlers::parse(json_encode($data), $data), true);

        return array_filter([
            '@type' => $this->type,
            '@id' => $this->id,
            ...$data,
        ]);
    }
}