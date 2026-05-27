<?php

namespace App\Repository;

use App\Entity\Delivery;
use PDO;
use RuntimeException;

class PdoDeliveryRepository implements DeliveryRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function mapRowToEntity(array $row): Delivery
    {
        if (!isset($row['id'], $row['customer_name'], $row['address'], $row['lat'], $row['lng'], $row['weight'], $row['status'])) {
            throw new RuntimeException('Database row is missing required fields to map a Delivery entity.');
        }

        return new Delivery(
            intval($row['id']),
            $row['customer_name'],
            $row['address'],
            floatval($row['lat']),
            floatval($row['lng']),
            floatval($row['weight']),
            $row['status'],
            $row['route_id'] !== null ? intval($row['route_id']) : null,
            $row['sequence_number'] !== null ? intval($row['sequence_number']) : null
        );
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, customer_name, address, lat, lng, weight, status, route_id, sequence_number FROM deliveries ORDER BY id ASC');
        $deliveries = [];
        
        while ($row = $stmt->fetch()) {
            $deliveries[] = $this->mapRowToEntity($row);
        }
        
        return $deliveries;
    }

    public function findById(int $id): ?Delivery
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id, customer_name, address, lat, lng, weight, status, route_id, sequence_number FROM deliveries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToEntity($row);
    }

    public function findPending(): array
    {
        $stmt = $this->db->query("SELECT id, customer_name, address, lat, lng, weight, status, route_id, sequence_number FROM deliveries WHERE status = 'pending' ORDER BY id ASC");
        $deliveries = [];
        
        while ($row = $stmt->fetch()) {
            $deliveries[] = $this->mapRowToEntity($row);
        }
        
        return $deliveries;
    }

    public function findByRouteId(int $routeId): array
    {
        if ($routeId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare('SELECT id, customer_name, address, lat, lng, weight, status, route_id, sequence_number FROM deliveries WHERE route_id = :route_id ORDER BY sequence_number ASC');
        $stmt->execute(['route_id' => $routeId]);
        $deliveries = [];
        
        while ($row = $stmt->fetch()) {
            $deliveries[] = $this->mapRowToEntity($row);
        }
        
        return $deliveries;
    }

    public function save(Delivery $delivery): Delivery
    {
        if (empty($delivery->customerName)) {
            throw new RuntimeException('Cannot save delivery: Customer Name is empty.');
        }
        if (empty($delivery->address)) {
            throw new RuntimeException('Cannot save delivery: Address is empty.');
        }
        if ($delivery->weight <= 0) {
            throw new RuntimeException('Cannot save delivery: Weight must be greater than zero.');
        }

        if ($delivery->id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO deliveries (customer_name, address, lat, lng, weight, status, route_id, sequence_number) 
                 VALUES (:customer_name, :address, :lat, :lng, :weight, :status, :route_id, :sequence_number) 
                 RETURNING id'
            );
            $stmt->execute([
                'customer_name' => $delivery->customerName,
                'address' => $delivery->address,
                'lat' => $delivery->lat,
                'lng' => $delivery->lng,
                'weight' => $delivery->weight,
                'status' => $delivery->status,
                'route_id' => $delivery->routeId,
                'sequence_number' => $delivery->sequenceNumber,
            ]);
            
            $id = $stmt->fetchColumn();
            if ($id === false) {
                throw new RuntimeException('Failed to insert delivery.');
            }

            return new Delivery(
                intval($id),
                $delivery->customerName,
                $delivery->address,
                $delivery->lat,
                $delivery->lng,
                $delivery->weight,
                $delivery->status,
                $delivery->routeId,
                $delivery->sequenceNumber
            );
        } else {
            $stmt = $this->db->prepare(
                'UPDATE deliveries 
                 SET customer_name = :customer_name, address = :address, lat = :lat, lng = :lng, 
                     weight = :weight, status = :status, route_id = :route_id, sequence_number = :sequence_number 
                 WHERE id = :id'
            );
            $stmt->execute([
                'customer_name' => $delivery->customerName,
                'address' => $delivery->address,
                'lat' => $delivery->lat,
                'lng' => $delivery->lng,
                'weight' => $delivery->weight,
                'status' => $delivery->status,
                'route_id' => $delivery->routeId,
                'sequence_number' => $delivery->sequenceNumber,
                'id' => $delivery->id,
            ]);

            return $delivery;
        }
    }

    public function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM deliveries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        
        return $stmt->rowCount() > 0;
    }

    public function clearActiveRouteAssignments(): void
    {
        $this->db->query("UPDATE deliveries SET route_id = NULL, sequence_number = NULL, status = 'pending' WHERE status NOT IN ('delivered', 'failed')");
    }

    public function clearRouteAssignmentsForVehicle(int $vehicleId): void
    {
        if ($vehicleId <= 0) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE deliveries 
            SET route_id = NULL, sequence_number = NULL, status = 'pending' 
            WHERE status NOT IN ('delivered', 'failed') 
              AND route_id IN (
                  SELECT id FROM routes WHERE vehicle_id = :vehicle_id
              )
        ");
        $stmt->execute(['vehicle_id' => $vehicleId]);
    }
}
