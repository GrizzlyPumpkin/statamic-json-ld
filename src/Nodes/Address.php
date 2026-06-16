<?php

namespace GrizzlyPumpkin\StatamicJsonLd\Nodes;

class Address extends Node
{
    public string $type = 'PostalAddress';
    public string|null $streetAddress;
    public string|null $addressLocality;
    public string|null $postalCode;
    public string|null $addressCountry;


    public static function fromArray(array $data, string $id): static
    {
        $self = parent::fromArray($data, $id);
        $self->streetAddress = $data['street_address'] ?? null;
        $self->addressLocality = $data['address_locality'] ?? null;
        $self->postalCode = $data['postal_code'] ?? null;
        $self->addressCountry = $data['address_country'] ?? null;
        return $self;
    }
}