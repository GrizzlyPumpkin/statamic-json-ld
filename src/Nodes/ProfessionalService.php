<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Nodes;

class ProfessionalService extends Node
{
    public string $type = 'ProfessionalService';
    public string $name;
    public string $url;
    public string|null $logo;
    public string|null $image;
    public string|null $telephone;
    public string|null $email;
    public Address|null $address;


    public static function fromArray(array $data, string $id): static
    {
        $self = parent::fromArray($data, $id);
        $self->name = $data['name'];
        $self->url = $data['url'];
        $self->logo = $data['logo'] ?? null;
        $self->image = $data['image'] ?? null;
        $self->telephone = $data['telephone'] ?? null;
        $self->email = $data['email'] ?? null;
        $self->address = $data['address'] ? Address::fromArray($data['address'], $id . '-address') : null;
        return $self;
    }
}