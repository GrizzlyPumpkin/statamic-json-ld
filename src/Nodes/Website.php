<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Nodes;

class Website extends Node
{
    public string $type = 'WebSite';
    public string $name;
    public string $url;
    public string|null $searchUrl;

    public static function fromArray(array $data, string $id): static
    {
        $self = parent::fromArray($data, $id);
        $self->name = $data['name'];
        $self->url = $data['url'];
        $self->searchUrl = $data['search_url'] ?? null;
        return $self;
    }
}