<?php

namespace App\Repository;

use App\Entity\Route;

interface RouteRepositoryInterface extends RepositoryInterface
{
    public function findByVehicleId(int $vehicleId): array;

    public function save(Route $route): Route;

    public function deleteActiveRoutes(): void;

    public function deleteActiveRouteForVehicle(int $vehicleId): void;
}
