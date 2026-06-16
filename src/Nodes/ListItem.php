<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Nodes;

class ListItem extends Node
{
    public string $type = 'ListItem';
    public int $position;
    public string $name;
    public string $item;

    public static function fromArray(array $data, string $id): static
    {
        $self = parent::fromArray($data, $id);
        $self->position = $data['position'];
        $self->name = $data['name'];
        $self->item = $data['item'];
        return $self;
    }
}