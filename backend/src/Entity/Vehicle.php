<?php

namespace App\Entity;

class Vehicle
{
    public function __construct(
        public readonly ?int $id,
        public string $name,
        public float $capacity,
        public float $startLat,
        public float $startLng,
        public float $endLat,
        public float $endLng,
        public string $status = 'active',
        public float $batteryCapacity = 50.0,
        public float $currentBattery = 50.0,
        public float $consumptionRate = 0.20
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'start_lat' => $this->startLat,
            'start_lng' => $this->startLng,
            'end_lat' => $this->endLat,
            'end_lng' => $this->endLng,
            'status' => $this->status,
            'battery_capacity' => $this->batteryCapacity,
            'current_battery' => $this->currentBattery,
            'consumption_rate' => $this->consumptionRate,
        ];
    }

    public function estimateRange(): float
    {
        return $this->currentBattery / $this->consumptionRate;
    }
}
