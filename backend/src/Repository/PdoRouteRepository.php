<?php

namespace App\Repository;

use App\Entity\Route;
use PDO;
use RuntimeException;

class PdoRouteRepository implements RouteRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function mapRowToEntity(array $row): Route
    {
        if (!isset($row['id'], $row['vehicle_id'], $row['total_distance'], $row['total_duration'], $row['created_at'])) {
            throw new RuntimeException('Database row is missing required fields to map a Route entity.');
        }

        return new Route(
            intval($row['id']),
            intval($row['vehicle_id']),
            floatval($row['total_distance']),
            floatval($row['total_duration']),
            $row['geometry'],
            $row['routing_source'] ?? null,
            $row['traffic_source'] ?? null,
            $row['created_at']
        );
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, vehicle_id, total_distance, total_duration, geometry, routing_source, traffic_source, created_at FROM routes ORDER BY id ASC');
        $routes = [];
        
        while ($row = $stmt->fetch()) {
            $routes[] = $this->mapRowToEntity($row);
        }
        
        return $routes;
    }

    public function findById(int $id): ?Route
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id, vehicle_id, total_distance, total_duration, geometry, routing_source, traffic_source, created_at FROM routes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToEntity($row);
    }

    public function findByVehicleId(int $vehicleId): array
    {
        if ($vehicleId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare('SELECT id, vehicle_id, total_distance, total_duration, geometry, routing_source, traffic_source, created_at FROM routes WHERE vehicle_id = :vehicle_id ORDER BY id ASC');
        $stmt->execute(['vehicle_id' => $vehicleId]);
        $routes = [];
        
        while ($row = $stmt->fetch()) {
            $routes[] = $this->mapRowToEntity($row);
        }
        
        return $routes;
    }

    public function save(Route $route): Route
    {
        if ($route->vehicleId <= 0) {
            throw new RuntimeException('Cannot save route: Invalid Vehicle ID.');
        }

        if ($route->id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO routes (vehicle_id, total_distance, total_duration, geometry, routing_source, traffic_source) 
                 VALUES (:vehicle_id, :total_distance, :total_duration, :geometry, :routing_source, :traffic_source) 
                 RETURNING id, created_at'
            );
            $stmt->execute([
                'vehicle_id' => $route->vehicleId,
                'total_distance' => $route->totalDistance,
                'total_duration' => $route->totalDuration,
                'geometry' => $route->geometry,
                'routing_source' => $route->routingSource,
                'traffic_source' => $route->trafficSource,
            ]);
            
            $result = $stmt->fetch();
            if ($result === false) {
                throw new RuntimeException('Failed to insert route.');
            }

            return new Route(
                intval($result['id']),
                $route->vehicleId,
                $route->totalDistance,
                $route->totalDuration,
                $route->geometry,
                $route->routingSource,
                $route->trafficSource,
                $result['created_at']
            );
        } else {
            $stmt = $this->db->prepare(
                'UPDATE routes 
                 SET vehicle_id = :vehicle_id, total_distance = :total_distance, 
                     total_duration = :total_duration, geometry = :geometry,
                     routing_source = :routing_source, traffic_source = :traffic_source 
                 WHERE id = :id'
            );
            $stmt->execute([
                'vehicle_id' => $route->vehicleId,
                'total_distance' => $route->totalDistance,
                'total_duration' => $route->totalDuration,
                'geometry' => $route->geometry,
                'routing_source' => $route->routingSource,
                'traffic_source' => $route->trafficSource,
                'id' => $route->id,
            ]);

            return $route;
        }
    }

    public function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM routes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        
        return $stmt->rowCount() > 0;
    }

    public function deleteActiveRoutes(): void
    {
        $this->db->query("
            DELETE FROM routes 
            WHERE id NOT IN (
                SELECT DISTINCT route_id FROM deliveries 
                WHERE status IN ('delivered', 'failed') AND route_id IS NOT NULL
            )
        ");
    }

    public function deleteActiveRouteForVehicle(int $vehicleId): void
    {
        if ($vehicleId <= 0) {
            return;
        }

        $stmt = $this->db->prepare("
            DELETE FROM routes 
            WHERE vehicle_id = :vehicle_id 
              AND id NOT IN (
                  SELECT DISTINCT route_id FROM deliveries 
                  WHERE status IN ('delivered', 'failed') AND route_id IS NOT NULL
              )
        ");
        $stmt->execute(['vehicle_id' => $vehicleId]);
    }
}
