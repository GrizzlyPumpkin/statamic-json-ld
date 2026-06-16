<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Nodes;

class BreadcrumbList extends Node
{
    public string $type = 'BreadcrumbList';
    public array $itemListElement;

    public static function fromArray(array $data, string $id): static
    {
        $self = parent::fromArray($data, $id);
        $self->itemListElement = $data['items'];
        return $self;
    }
}