<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Vehicle;
use App\Entity\Delivery;
use App\Entity\Route;
use App\Repository\VehicleRepositoryInterface;
use App\Repository\DeliveryRepositoryInterface;
use App\Repository\RouteRepositoryInterface;

class SolverService
{
    private const DIESEL_CO2_PER_KM = 0.12;

    private VehicleRepositoryInterface $vehicleRepo;
    private DeliveryRepositoryInterface $deliveryRepo;
    private RouteRepositoryInterface $routeRepo;
    private OrsService $orsService;
    private TrafficService $trafficService;

    public function __construct(
        VehicleRepositoryInterface $vehicleRepo,
        DeliveryRepositoryInterface $deliveryRepo,
        RouteRepositoryInterface $routeRepo,
        OrsService $orsService,
        TrafficService $trafficService
    ) {
        $this->vehicleRepo = $vehicleRepo;
        $this->deliveryRepo = $deliveryRepo;
        $this->routeRepo = $routeRepo;
        $this->orsService = $orsService;
        $this->trafficService = $trafficService;
    }

    public function optimize(?int $vehicleId = null): array
    {
        if ($vehicleId !== null && $vehicleId > 0) {
            $vehicle = $this->vehicleRepo->findById($vehicleId);
            if ($vehicle === null || $vehicle->status !== 'active') {
                return [
                    'success' => false,
                    'message' => 'Requested vehicle is not found or is inactive.'
                ];
            }
            $vehicles = [$vehicle];

            // Safe cleanup for just this vehicle
            $this->routeRepo->deleteActiveRouteForVehicle($vehicleId);
            $this->deliveryRepo->clearRouteAssignmentsForVehicle($vehicleId);
        } else {
            $vehicles = array_filter(
                $this->vehicleRepo->findAll(),
                fn(Vehicle $v) => $v->status === 'active'
            );
            if (empty($vehicles)) {
                return [
                    'success' => false,
                    'message' => 'No active vehicles found to perform routing.'
                ];
            }

            // Safe cleanup: delete only routes without completed stops and clear assignments for non-completed stops
            $this->routeRepo->deleteActiveRoutes();
            $this->deliveryRepo->clearActiveRouteAssignments();
        }

        $deliveries = $this->deliveryRepo->findPending();

        if (empty($deliveries)) {
            return [
                'success' => false,
                'message' => 'No pending deliveries found to optimize.'
            ];
        }

        $locations = [];
        foreach ($vehicles as $vehicle) {
            $locations[] = ['lat' => $vehicle->startLat, 'lng' => $vehicle->startLng];
            $locations[] = ['lat' => $vehicle->endLat, 'lng' => $vehicle->endLng];
        }
        foreach ($deliveries as $delivery) {
            $locations[] = ['lat' => $delivery->lat, 'lng' => $delivery->lng];
        }

        $matrix = $this->orsService->getDistanceMatrix($locations);
        $distances = $matrix['distances'] ?? [];
        $baseDurations = $matrix['durations'] ?? [];
        $durations = $this->trafficService->getAdjustedDurations($locations, $baseDurations);

        $getDistance = function (array $c1, array $c2) use ($locations, $distances): float {
            $idx1 = $this->findLocationIndex($locations, $c1);
            $idx2 = $this->findLocationIndex($locations, $c2);
            if ($idx1 !== null && $idx2 !== null && isset($distances[$idx1][$idx2])) {
                return floatval($distances[$idx1][$idx2]);
            }
            return $this->haversineDistance($c1['lat'], $c1['lng'], $c2['lat'], $c2['lng']);
        };

        $getDuration = function (array $c1, array $c2) use ($locations, $durations): float {
            $idx1 = $this->findLocationIndex($locations, $c1);
            $idx2 = $this->findLocationIndex($locations, $c2);
            if ($idx1 !== null && $idx2 !== null && isset($durations[$idx1][$idx2])) {
                return floatval($durations[$idx1][$idx2]);
            }
            $dist = $this->haversineDistance($c1['lat'], $c1['lng'], $c2['lat'], $c2['lng']);
            return $dist / 13.88;
        };

        $unassignedDeliveries = $deliveries;
        $vehicleRoutes = [];
        $vehicleEnergyConsumed = [];

        foreach ($vehicles as $vehicle) {
            $routeStops = [];
            $currentCapacity = $vehicle->capacity;
            $remainingBattery = $vehicle->currentBattery;
            $currentCoords = ['lat' => $vehicle->startLat, 'lng' => $vehicle->startLng];
            $depotCoords = ['lat' => $vehicle->endLat, 'lng' => $vehicle->endLng];

            while (!empty($unassignedDeliveries)) {
                $bestNextIndex = null;
                $minDist = INF;

                foreach ($unassignedDeliveries as $idx => $delivery) {
                    if ($delivery->weight > $currentCapacity) {
                        continue;
                    }

                    $deliveryCoords = ['lat' => $delivery->lat, 'lng' => $delivery->lng];
                    $distToDelivery = $getDistance($currentCoords, $deliveryCoords);
                    $distToDepot = $getDistance($deliveryCoords, $depotCoords);

                    // Convert meters to km for energy calculation
                    $distToDeliveryKm = $distToDelivery / 1000.0;
                    $distToDepotKm = $distToDepot / 1000.0;

                    // Dynamic traffic-adjusted durations for idle auxiliary draw
                    $durToDeliveryHours = $getDuration($currentCoords, $deliveryCoords) / 3600.0;
                    $durToDepotHours = $getDuration($deliveryCoords, $depotCoords) / 3600.0;
                    $auxDraw = (strpos(strtolower($vehicle->name), 'bike') !== false) ? 0.1 : 2.0;

                    // Check battery: enough to reach delivery AND return to depot (with aux draw)
                    $energyNeeded = (($distToDeliveryKm + $distToDepotKm) * $vehicle->consumptionRate) +
                                    (($durToDeliveryHours + $durToDepotHours) * $auxDraw);
                    if ($energyNeeded > $remainingBattery) {
                        continue;
                    }

                    if ($distToDelivery < $minDist) {
                        $minDist = $distToDelivery;
                        $bestNextIndex = $idx;
                    }
                }

                if ($bestNextIndex === null) {
                    break;
                }

                $assignedDelivery = $unassignedDeliveries[$bestNextIndex];
                unset($unassignedDeliveries[$bestNextIndex]);
                $unassignedDeliveries = array_values($unassignedDeliveries);

                // Deduct energy for the leg to this delivery (including auxiliary draw)
                $legDistKm = $minDist / 1000.0;
                $legDurationHours = $getDuration($currentCoords, $deliveryCoords) / 3600.0;
                $auxDraw = (strpos(strtolower($vehicle->name), 'bike') !== false) ? 0.1 : 2.0;
                $legEnergy = ($legDistKm * $vehicle->consumptionRate) + ($legDurationHours * $auxDraw);
                $remainingBattery -= $legEnergy;

                $routeStops[] = $assignedDelivery;
                $currentCapacity -= $assignedDelivery->weight;
                $currentCoords = ['lat' => $assignedDelivery->lat, 'lng' => $assignedDelivery->lng];
            }

            if (!empty($routeStops)) {
                // Add energy for return to depot (including auxiliary draw)
                $returnDistKm = $getDistance($currentCoords, $depotCoords) / 1000.0;
                $returnDurationHours = $getDuration($currentCoords, $depotCoords) / 3600.0;
                $auxDraw = (strpos(strtolower($vehicle->name), 'bike') !== false) ? 0.1 : 2.0;
                $returnEnergy = ($returnDistKm * $vehicle->consumptionRate) + ($returnDurationHours * $auxDraw);

                $totalVehicleEnergy = ($vehicle->currentBattery - $remainingBattery) + $returnEnergy;

                $vehicleRoutes[$vehicle->id] = [
                    'vehicle' => $vehicle,
                    'stops' => $routeStops
                ];
                $vehicleEnergyConsumed[$vehicle->id] = $totalVehicleEnergy;
            }
        }

        $savedRoutes = [];
        $totalDistance = 0.0;
        $totalDuration = 0.0;
        $totalEnergyConsumed = 0.0;
        $totalDistanceKm = 0.0;

        foreach ($vehicleRoutes as $vehicleId => $routeData) {
            /** @var Vehicle $vehicle */
            $vehicle = $routeData['vehicle'];
            /** @var Delivery[] $stops */
            $stops = $routeData['stops'];

            $startCoords = ['lat' => $vehicle->startLat, 'lng' => $vehicle->startLng];
            $endCoords = ['lat' => $vehicle->endLat, 'lng' => $vehicle->endLng];

            $orderedStops = [];
            $remainingStops = $stops;
            $currentCoords = $startCoords;

            while (!empty($remainingStops)) {
                $nearestIndex = null;
                $minDist = INF;

                foreach ($remainingStops as $idx => $stop) {
                    $stopCoords = ['lat' => $stop->lat, 'lng' => $stop->lng];
                    $dist = $getDistance($currentCoords, $stopCoords);
                    if ($dist < $minDist) {
                        $minDist = $dist;
                        $nearestIndex = $idx;
                    }
                }

                $orderedStops[] = $remainingStops[$nearestIndex];
                $currentCoords = ['lat' => $remainingStops[$nearestIndex]->lat, 'lng' => $remainingStops[$nearestIndex]->lng];
                unset($remainingStops[$nearestIndex]);
                $remainingStops = array_values($remainingStops);
            }

            $orderedStops = $this->optimizeTwoOpt($orderedStops, $startCoords, $endCoords, $getDistance);

            $routeCoords = [$startCoords];
            foreach ($orderedStops as $stop) {
                $routeCoords[] = ['lat' => $stop->lat, 'lng' => $stop->lng];
            }
            $routeCoords[] = $endCoords;

            $details = $this->orsService->getRouteDetails($routeCoords);

            // Compute final traffic-adjusted duration based on route sequence
            $routeDuration = 0.0;
            for ($k = 0; $k < count($routeCoords) - 1; $k++) {
                $routeDuration += $getDuration($routeCoords[$k], $routeCoords[$k + 1]);
            }

            $routeEntity = new Route(
                null,
                $vehicle->id,
                $details['distance'],
                $routeDuration,
                $details['geometry'],
                $this->orsService->getLastMethodUsed(),
                $this->trafficService->getLastMethodUsed()
            );
            $savedRoute = $this->routeRepo->save($routeEntity);

            $sequence = 1;
            foreach ($orderedStops as $stop) {
                $stop->routeId = $savedRoute->id;
                $stop->sequenceNumber = $sequence++;
                $stop->status = 'assigned';
                $this->deliveryRepo->save($stop);
            }

            // Recalculate energy based on traffic-adjusted duration and final OSRM distance
            $routeDistanceKm = $details['distance'] / 1000.0;
            $routeDurationHours = $routeDuration / 3600.0;
            $auxDraw = (strpos(strtolower($vehicle->name), 'bike') !== false) ? 0.1 : 2.0;
            $routeEnergy = ($routeDistanceKm * $vehicle->consumptionRate) + ($routeDurationHours * $auxDraw);

            $savedRoutes[] = [
                'route' => $savedRoute->toArray(),
                'vehicle' => $vehicle->toArray(),
                'deliveries' => array_map(fn($d) => $d->toArray(), $orderedStops)
            ];

            $totalDistance += $details['distance'];
            $totalDuration += $routeDuration;
            $totalDistanceKm += $routeDistanceKm;
            $totalEnergyConsumed += $routeEnergy;
        }

        $totalCo2Offset = $totalDistanceKm * self::DIESEL_CO2_PER_KM;

        return [
            'success' => true,
            'summary' => [
                'total_distance' => $totalDistance,
                'total_duration' => $totalDuration,
                'total_energy_consumed' => round($totalEnergyConsumed, 2),
                'total_co2_offset' => round($totalCo2Offset, 2),
                'vehicles_used' => count($savedRoutes),
                'unassigned_deliveries' => count($unassignedDeliveries),
                'routing_calculation' => $this->orsService->getLastMethodUsed(),
                'traffic_calculation' => $this->trafficService->getLastMethodUsed(),
            ],
            'routes' => $savedRoutes
        ];
    }

    public function getOrCreateRouteForVehicle(int $vehicleId): Route
    {
        $routes = $this->routeRepo->findByVehicleId($vehicleId);
        if (!empty($routes)) {
            return $routes[0];
        }

        $routeEntity = new Route(
            null,
            $vehicleId,
            0.0,
            0.0,
            null,
            null,
            null
        );
        return $this->routeRepo->save($routeEntity);
    }

    public function recalculateRouteForVehicle(int $vehicleId): ?array
    {
        $vehicle = $this->vehicleRepo->findById($vehicleId);
        if ($vehicle === null) {
            return null;
        }

        $routes = $this->routeRepo->findByVehicleId($vehicleId);
        if (empty($routes)) {
            return null;
        }
        $route = $routes[0];

        $deliveries = $this->deliveryRepo->findByRouteId($route->id);

        if (empty($deliveries)) {
            $this->routeRepo->deleteById($route->id);
            return null;
        }

        // Normalize sequence numbers and save
        $sequence = 1;
        foreach ($deliveries as $d) {
            $d->sequenceNumber = $sequence++;
            $d->status = 'assigned';
            $this->deliveryRepo->save($d);
        }

        $startCoords = ['lat' => $vehicle->startLat, 'lng' => $vehicle->startLng];
        $endCoords = ['lat' => $vehicle->endLat, 'lng' => $vehicle->endLng];

        $routeCoords = [$startCoords];
        foreach ($deliveries as $d) {
            $routeCoords[] = ['lat' => $d->lat, 'lng' => $d->lng];
        }
        $routeCoords[] = $endCoords;

        $details = $this->orsService->getRouteDetails($routeCoords);

        $matrix = $this->orsService->getDistanceMatrix($routeCoords);
        $baseDurations = $matrix['durations'] ?? [];
        $durations = $this->trafficService->getAdjustedDurations($routeCoords, $baseDurations);

        $getDuration = function (array $c1, array $c2) use ($routeCoords, $durations): float {
            $idx1 = $this->findLocationIndex($routeCoords, $c1);
            $idx2 = $this->findLocationIndex($routeCoords, $c2);
            if ($idx1 !== null && $idx2 !== null && isset($durations[$idx1][$idx2])) {
                return floatval($durations[$idx1][$idx2]);
            }
            $dist = $this->haversineDistance($c1['lat'], $c1['lng'], $c2['lat'], $c2['lng']);
            return $dist / 13.88;
        };

        $routeDuration = 0.0;
        for ($k = 0; $k < count($routeCoords) - 1; $k++) {
            $routeDuration += $getDuration($routeCoords[$k], $routeCoords[$k + 1]);
        }

        $route->totalDistance = floatval($details['distance']);
        $route->totalDuration = $routeDuration;
        $route->geometry = $details['geometry'];
        $route->routingSource = $this->orsService->getLastMethodUsed();
        $route->trafficSource = $this->trafficService->getLastMethodUsed();

        $savedRoute = $this->routeRepo->save($route);

        return [
            'route' => $savedRoute->toArray(),
            'vehicle' => $vehicle->toArray(),
            'deliveries' => array_map(fn($d) => $d->toArray(), $deliveries)
        ];
    }

    private function optimizeTwoOpt(array $stops, array $start, array $end, callable $getDistance): array
    {
        $n = count($stops);
        if ($n < 2) {
            return $stops;
        }

        $improved = true;
        $bestSequence = $stops;

        $calculatePathLen = function (array $seq) use ($start, $end, $getDistance): float {
            $len = 0.0;
            $current = $start;
            foreach ($seq as $stop) {
                $stopCoords = ['lat' => $stop->lat, 'lng' => $stop->lng];
                $len += $getDistance($current, $stopCoords);
                $current = $stopCoords;
            }
            $len += $getDistance($current, $end);
            return $len;
        };

        $bestDist = $calculatePathLen($bestSequence);
        $limit = 100;
        $iterations = 0;

        while ($improved && $iterations < $limit) {
            $improved = false;
            $iterations++;

            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $newSequence = $bestSequence;
                    $sub = array_reverse(array_slice($newSequence, $i, $j - $i + 1));
                    array_splice($newSequence, $i, $j - $i + 1, $sub);

                    $newDist = $calculatePathLen($newSequence);
                    if ($newDist < $bestDist - 0.01) {
                        $bestSequence = $newSequence;
                        $bestDist = $newDist;
                        $improved = true;
                        break 2;
                    }
                }
            }
        }

        return $bestSequence;
    }

    private function findLocationIndex(array $locations, array $target): ?int
    {
        foreach ($locations as $idx => $loc) {
            if (abs($loc['lat'] - $target['lat']) < 0.000001 && abs($loc['lng'] - $target['lng']) < 0.000001) {
                return $idx;
            }
        }
        return null;
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
