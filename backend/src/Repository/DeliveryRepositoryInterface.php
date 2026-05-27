<?php

namespace App\Repository;

use App\Entity\Delivery;

interface DeliveryRepositoryInterface extends RepositoryInterface
{
    public function findPending(): array;

    public function findByRouteId(int $routeId): array;

    public function save(Delivery $delivery): Delivery;

    public function clearActiveRouteAssignments(): void;

    public function clearRouteAssignmentsForVehicle(int $vehicleId): void;
}
