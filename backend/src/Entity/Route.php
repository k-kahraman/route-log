<?php

namespace App\Entity;

class Route
{
    public function __construct(
        public readonly ?int $id,
        public int $vehicleId,
        public float $totalDistance = 0.0,
        public float $totalDuration = 0.0,
        public ?string $geometry = null,
        public ?string $routingSource = null,
        public ?string $trafficSource = null,
        public ?string $createdAt = null
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'total_distance' => $this->totalDistance,
            'total_duration' => $this->totalDuration,
            'geometry' => $this->geometry,
            'routing_source' => $this->routingSource,
            'traffic_source' => $this->trafficSource,
            'created_at' => $this->createdAt,
        ];
    }
}
