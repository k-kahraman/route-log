<?php

namespace App\Repository;

use App\Entity\Vehicle;
use PDO;
use RuntimeException;

class PdoVehicleRepository implements VehicleRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function mapRowToEntity(array $row): Vehicle
    {
        if (!isset($row['id'], $row['name'], $row['capacity'], $row['start_lat'], $row['start_lng'], $row['end_lat'], $row['end_lng'], $row['status'])) {
            throw new RuntimeException('Database row is missing required fields to map a Vehicle entity.');
        }

        return new Vehicle(
            intval($row['id']),
            $row['name'],
            floatval($row['capacity']),
            floatval($row['start_lat']),
            floatval($row['start_lng']),
            floatval($row['end_lat']),
            floatval($row['end_lng']),
            $row['status'],
            floatval($row['battery_capacity'] ?? 50.0),
            floatval($row['current_battery'] ?? 50.0),
            floatval($row['consumption_rate'] ?? 0.20)
        );
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, name, capacity, start_lat, start_lng, end_lat, end_lng, status, battery_capacity, current_battery, consumption_rate FROM vehicles ORDER BY id ASC');
        $vehicles = [];
        
        while ($row = $stmt->fetch()) {
            $vehicles[] = $this->mapRowToEntity($row);
        }
        
        return $vehicles;
    }

    public function findById(int $id): ?Vehicle
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id, name, capacity, start_lat, start_lng, end_lat, end_lng, status, battery_capacity, current_battery, consumption_rate FROM vehicles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToEntity($row);
    }

    public function save(Vehicle $vehicle): Vehicle
    {
        if (empty($vehicle->name)) {
            throw new RuntimeException('Cannot save vehicle: Name is empty.');
        }
        if ($vehicle->capacity <= 0) {
            throw new RuntimeException('Cannot save vehicle: Capacity must be greater than zero.');
        }

        if ($vehicle->id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO vehicles (name, capacity, start_lat, start_lng, end_lat, end_lng, status, battery_capacity, current_battery, consumption_rate) 
                 VALUES (:name, :capacity, :start_lat, :start_lng, :end_lat, :end_lng, :status, :battery_capacity, :current_battery, :consumption_rate) 
                 RETURNING id'
            );
            $stmt->execute([
                'name' => $vehicle->name,
                'capacity' => $vehicle->capacity,
                'start_lat' => $vehicle->startLat,
                'start_lng' => $vehicle->startLng,
                'end_lat' => $vehicle->endLat,
                'end_lng' => $vehicle->endLng,
                'status' => $vehicle->status,
                'battery_capacity' => $vehicle->batteryCapacity,
                'current_battery' => $vehicle->currentBattery,
                'consumption_rate' => $vehicle->consumptionRate,
            ]);
            
            $id = $stmt->fetchColumn();
            if ($id === false) {
                throw new RuntimeException('Failed to insert vehicle.');
            }

            return new Vehicle(
                intval($id),
                $vehicle->name,
                $vehicle->capacity,
                $vehicle->startLat,
                $vehicle->startLng,
                $vehicle->endLat,
                $vehicle->endLng,
                $vehicle->status,
                $vehicle->batteryCapacity,
                $vehicle->currentBattery,
                $vehicle->consumptionRate
            );
        } else {
            $stmt = $this->db->prepare(
                'UPDATE vehicles 
                 SET name = :name, capacity = :capacity, start_lat = :start_lat, start_lng = :start_lng, 
                     end_lat = :end_lat, end_lng = :end_lng, status = :status,
                     battery_capacity = :battery_capacity, current_battery = :current_battery, consumption_rate = :consumption_rate 
                 WHERE id = :id'
            );
            $stmt->execute([
                'name' => $vehicle->name,
                'capacity' => $vehicle->capacity,
                'start_lat' => $vehicle->startLat,
                'start_lng' => $vehicle->startLng,
                'end_lat' => $vehicle->endLat,
                'end_lng' => $vehicle->endLng,
                'status' => $vehicle->status,
                'battery_capacity' => $vehicle->batteryCapacity,
                'current_battery' => $vehicle->currentBattery,
                'consumption_rate' => $vehicle->consumptionRate,
                'id' => $vehicle->id,
            ]);

            return $vehicle;
        }
    }

    public function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM vehicles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        
        return $stmt->rowCount() > 0;
    }
}
