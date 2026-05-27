<?php

namespace App\Entity;

class User
{
    public function __construct(
        public ?int $id,
        public string $username,
        public string $passwordHash,
        public string $role,
        public ?int $vehicleId = null,
        public string $createdAt = ''
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'role' => $this->role,
            'vehicle_id' => $this->vehicleId,
            'created_at' => $this->createdAt,
        ];
    }
}
