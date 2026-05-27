<?php

namespace App\Repository;

use App\Entity\Vehicle;

interface VehicleRepositoryInterface extends RepositoryInterface
{
    public function save(Vehicle $vehicle): Vehicle;
}
