<?php

namespace App\Entity;

class Delivery
{
    public function __construct(
        public readonly ?int $id,
        public string $customerName,
        public string $address,
        public float $lat,
        public float $lng,
        public float $weight,
        public string $status = 'pending',
        public ?int $routeId = null,
        public ?int $sequenceNumber = null
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_name' => $this->customerName,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'weight' => $this->weight,
            'status' => $this->status,
            'route_id' => $this->routeId,
            'sequence_number' => $this->sequenceNumber,
        ];
    }
}
