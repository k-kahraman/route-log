<?php



require_once __DIR__ . '/../vendor/autoload.php';

use App\Entity\Vehicle;
use App\Entity\Delivery;
use App\Entity\Route;
use App\Repository\VehicleRepositoryInterface;
use App\Repository\DeliveryRepositoryInterface;
use App\Repository\RouteRepositoryInterface;
use App\Service\OrsService;
use App\Service\TrafficService;
use App\Service\SolverService;

class MockVehicleRepository implements VehicleRepositoryInterface {
    public array $vehicles = [];
    public function findAll(): array { return array_values($this->vehicles); }
    public function findById(int $id): ?Vehicle { return $this->vehicles[$id] ?? null; }
    public function save(Vehicle $vehicle): Vehicle {
        if ($vehicle->id === null) {
            $id = count($this->vehicles) + 1;
            $vehicle = new Vehicle(
                $id,
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
        }
        $this->vehicles[$vehicle->id] = $vehicle;
        return $vehicle;
    }
    public function deleteById(int $id): bool { unset($this->vehicles[$id]); return true; }
}

class MockDeliveryRepository implements DeliveryRepositoryInterface {
    public array $deliveries = [];
    public function findAll(): array { return array_values($this->deliveries); }
    public function findById(int $id): ?Delivery { return $this->deliveries[$id] ?? null; }
    public function findPending(): array {
        return array_values(array_filter($this->deliveries, fn(Delivery $d): bool => $d->status === 'pending'));
    }
    public function findByRouteId(int $routeId): array {
        $filtered = array_filter($this->deliveries, fn(Delivery $d): bool => $d->routeId === $routeId);
        usort($filtered, fn(Delivery $a, Delivery $b): int => ($a->sequenceNumber ?? 0) <=> ($b->sequenceNumber ?? 0));
        return array_values($filtered);
    }
    public function save(Delivery $delivery): Delivery {
        if ($delivery->id === null) {
            $id = count($this->deliveries) + 1;
            $delivery = new Delivery($id, $delivery->customerName, $delivery->address, $delivery->lat, $delivery->lng, $delivery->weight, $delivery->status, $delivery->routeId, $delivery->sequenceNumber);
        }
        $this->deliveries[$delivery->id] = $delivery;
        return $delivery;
    }
    public function deleteById(int $id): bool { unset($this->deliveries[$id]); return true; }
    public function clearActiveRouteAssignments(): void {
        foreach ($this->deliveries as $d) {
            if ($d->status !== 'delivered' && $d->status !== 'failed') {
                $d->routeId = null;
                $d->sequenceNumber = null;
                $d->status = 'pending';
            }
        }
    }
    public function clearRouteAssignmentsForVehicle(int $vehicleId): void {
        foreach ($this->deliveries as $d) {
            if ($d->routeId !== null && isset(MockRouteRepository::$allRoutesStatic[$d->routeId])) {
                $route = MockRouteRepository::$allRoutesStatic[$d->routeId];
                if ($route->vehicleId === $vehicleId && $d->status !== 'delivered' && $d->status !== 'failed') {
                    $d->routeId = null;
                    $d->sequenceNumber = null;
                    $d->status = 'pending';
                }
            }
        }
    }
}

class MockRouteRepository implements RouteRepositoryInterface {
    public static array $allRoutesStatic = [];
    public array $routes = [];
    public function findAll(): array { return array_values($this->routes); }
    public function findById(int $id): ?Route { return $this->routes[$id] ?? null; }
    public function findByVehicleId(int $vehicleId): array {
        return array_values(array_filter($this->routes, fn(Route $r): bool => $r->vehicleId === $vehicleId));
    }
    public function save(Route $route): Route {
        if ($route->id === null) {
            $id = count($this->routes) + 1;
            $route = new Route(
                $id,
                $route->vehicleId,
                $route->totalDistance,
                $route->totalDuration,
                $route->geometry,
                $route->routingSource,
                $route->trafficSource,
                date('Y-m-d H:i:s')
            );
        }
        $this->routes[$route->id] = $route;
        self::$allRoutesStatic[$route->id] = $route;
        return $route;
    }
    public function deleteById(int $id): bool { 
        unset($this->routes[$id]); 
        unset(self::$allRoutesStatic[$id]);
        return true; 
    }
    public function deleteActiveRoutes(): void {
        $this->routes = [];
        self::$allRoutesStatic = [];
    }
    public function deleteActiveRouteForVehicle(int $vehicleId): void {
        foreach ($this->routes as $id => $r) {
            if ($r->vehicleId === $vehicleId) {
                unset($this->routes[$id]);
                unset(self::$allRoutesStatic[$id]);
            }
        }
    }
}

// ========================================================================
// TEST 1: Basic cargo capacity optimization (existing test, updated for EV)
// ========================================================================
echo "=== ROUTELOG EV SOLVER TEST RUN ===\n\n";

echo "--- Test 1: Cargo Capacity Constraint ---\n";

$vehicleRepo = new MockVehicleRepository();
$deliveryRepo = new MockDeliveryRepository();
$routeRepo = new MockRouteRepository();
$orsService = new OrsService();
$trafficService = new TrafficService();
$solver = new SolverService($vehicleRepo, $deliveryRepo, $routeRepo, $orsService, $trafficService);

// Vehicles with ample battery (no range constraint should trigger)
$vehicleRepo->save(new Vehicle(null, 'Test Van A', 100.0, 52.52, 13.40, 52.52, 13.40, 'active', 60.0, 58.0, 0.22));
$vehicleRepo->save(new Vehicle(null, 'Test Van B', 50.0, 52.52, 13.40, 52.52, 13.40, 'active', 40.0, 38.0, 0.18));

$deliveryRepo->save(new Delivery(null, 'Stop A', 'Address A', 52.51, 13.37, 60.0));
$deliveryRepo->save(new Delivery(null, 'Stop B', 'Address B', 52.53, 13.42, 30.0));
$deliveryRepo->save(new Delivery(null, 'Stop C', 'Address C', 52.48, 13.43, 45.0));
$deliveryRepo->save(new Delivery(null, 'Stop D', 'Address D', 52.50, 13.33, 15.0));

echo "Running optimization...\n";
$result = $solver->optimize();

if (!$result['success']) {
    echo "FAIL: Solver optimization returned failure: " . $result['message'] . "\n";
    exit(1);
}

$summary = $result['summary'];
echo "Success! Optimization completed.\n";
echo "Vehicles used: " . $summary['vehicles_used'] . " (Expected: 2)\n";
echo "Unassigned deliveries: " . $summary['unassigned_deliveries'] . " (Expected: 1)\n";

$routes = $routeRepo->findAll();
if (count($routes) !== 2) {
    echo "FAIL: Expected 2 routes generated, got " . count($routes) . "\n";
    exit(1);
}

$assignedStopsVanA = $deliveryRepo->findByRouteId(1);
$assignedStopsVanB = $deliveryRepo->findByRouteId(2);

echo "Van A assigned stops: " . count($assignedStopsVanA) . " (Expected: 2)\n";
echo "Van B assigned stops: " . count($assignedStopsVanB) . " (Expected: 1)\n";

$vanAWeight = array_reduce($assignedStopsVanA, fn(float $sum, Delivery $s): float => $sum + $s->weight, 0.0);
$vanBWeight = array_reduce($assignedStopsVanB, fn(float $sum, Delivery $s): float => $sum + $s->weight, 0.0);

echo "Van A total load: " . $vanAWeight . " (Expected: 90.0, capacity 100)\n";
echo "Van B total load: " . $vanBWeight . " (Expected: 45.0, capacity 50)\n";

if ($vanAWeight > 100.0 || $vanBWeight > 50.0) {
    echo "FAIL: Capacity limit violated!\n";
    exit(1);
}

// Verify EV summary fields exist
if (!isset($summary['total_energy_consumed'])) {
    echo "FAIL: Missing total_energy_consumed in summary.\n";
    exit(1);
}
if (!isset($summary['total_co2_offset'])) {
    echo "FAIL: Missing total_co2_offset in summary.\n";
    exit(1);
}
echo "Energy consumed: " . $summary['total_energy_consumed'] . " kWh\n";
echo "CO2 offset: " . $summary['total_co2_offset'] . " kg\n";

echo "--- Test 1 PASSED ---\n\n";

// ========================================================================
// TEST 2: Battery range constraint
// ========================================================================
echo "--- Test 2: Battery Range Constraint ---\n";

$vehicleRepo2 = new MockVehicleRepository();
$deliveryRepo2 = new MockDeliveryRepository();
$routeRepo2 = new MockRouteRepository();
$solver2 = new SolverService($vehicleRepo2, $deliveryRepo2, $routeRepo2, $orsService, $trafficService);

// Vehicle with very low battery — should not be able to reach distant deliveries
// 1.0 kWh remaining at 0.20 kWh/km = 5 km range
$vehicleRepo2->save(new Vehicle(null, 'Low Battery Van', 200.0, 52.52, 13.40, 52.52, 13.40, 'active', 60.0, 1.0, 0.20));

// A nearby delivery (~1 km away) — should be assignable
$deliveryRepo2->save(new Delivery(null, 'Nearby Stop', 'Nearby Address', 52.525, 13.405, 10.0));

// A distant delivery (~10 km away) — should NOT be assignable due to range
$deliveryRepo2->save(new Delivery(null, 'Far Stop', 'Far Address', 52.60, 13.50, 10.0));

echo "Running optimization with limited battery...\n";
$result2 = $solver2->optimize();

if (!$result2['success']) {
    echo "FAIL: Solver returned failure for battery constraint test: " . $result2['message'] . "\n";
    exit(1);
}

$summary2 = $result2['summary'];
echo "Vehicles used: " . $summary2['vehicles_used'] . "\n";
echo "Unassigned deliveries: " . $summary2['unassigned_deliveries'] . "\n";

// The distant delivery should remain unassigned
if ($summary2['unassigned_deliveries'] < 1) {
    echo "FAIL: Expected at least 1 unassigned delivery due to range constraint, got " . $summary2['unassigned_deliveries'] . "\n";
    exit(1);
}

echo "--- Test 2 PASSED ---\n\n";

// ========================================================================
// TEST 3: Vehicle entity EV methods
// ========================================================================
echo "--- Test 3: Vehicle Entity EV Properties ---\n";

$testVehicle = new Vehicle(1, 'Test EV', 150.0, 52.52, 13.40, 52.52, 13.40, 'active', 60.0, 45.0, 0.25);

$range = $testVehicle->estimateRange();
$expectedRange = 45.0 / 0.25; // 180 km

if (abs($range - $expectedRange) > 0.01) {
    echo "FAIL: estimateRange() returned " . $range . ", expected " . $expectedRange . "\n";
    exit(1);
}
echo "estimateRange(): " . $range . " km (Expected: " . $expectedRange . ") ✓\n";

$arr = $testVehicle->toArray();
if (!isset($arr['battery_capacity']) || !isset($arr['current_battery']) || !isset($arr['consumption_rate'])) {
    echo "FAIL: toArray() missing EV fields.\n";
    exit(1);
}
echo "toArray() includes battery_capacity: " . $arr['battery_capacity'] . " ✓\n";
echo "toArray() includes current_battery: " . $arr['current_battery'] . " ✓\n";
echo "toArray() includes consumption_rate: " . $arr['consumption_rate'] . " ✓\n";

echo "--- Test 3 PASSED ---\n\n";

// ========================================================================
// TEST 4: Manual route assignment and recalculation
// ========================================================================
echo "--- Test 4: Manual Route Assignment & Recalculation ---\n";

$vehicleRepo3 = new MockVehicleRepository();
$deliveryRepo3 = new MockDeliveryRepository();
$routeRepo3 = new MockRouteRepository();
$solver3 = new SolverService($vehicleRepo3, $deliveryRepo3, $routeRepo3, $orsService, $trafficService);

// 1. Create a vehicle
$vehicle = $vehicleRepo3->save(new Vehicle(null, 'Test Van C', 100.0, 39.8654, 32.7350, 39.8654, 32.7350, 'active', 50.0, 50.0, 0.20));

// 2. Create deliveries
$d1 = $deliveryRepo3->save(new Delivery(null, 'Stop C1', 'Address C1', 39.8700, 32.7400, 10.0));
$d2 = $deliveryRepo3->save(new Delivery(null, 'Stop C2', 'Address C2', 39.8800, 32.7500, 20.0));

// 3. Create route manually
$route = $solver3->getOrCreateRouteForVehicle($vehicle->id);
if ($route === null || $route->vehicleId !== $vehicle->id) {
    echo "FAIL: getOrCreateRouteForVehicle failed.\n";
    exit(1);
}

// 4. Assign deliveries to route
$d1->routeId = $route->id;
$d1->sequenceNumber = 2; // out of order first
$d1->status = 'assigned';
$deliveryRepo3->save($d1);

$d2->routeId = $route->id;
$d2->sequenceNumber = 1;
$d2->status = 'assigned';
$deliveryRepo3->save($d2);

// 5. Recalculate
echo "Recalculating manually assigned route...\n";
$recalc = $solver3->recalculateRouteForVehicle($vehicle->id);

if ($recalc === null) {
    echo "FAIL: recalculateRouteForVehicle returned null.\n";
    exit(1);
}

// Verify sequence number normalization
$recalcDeliveries = $recalc['deliveries'];
if (count($recalcDeliveries) !== 2) {
    echo "FAIL: Expected 2 deliveries in recalculated route, got " . count($recalcDeliveries) . "\n";
    exit(1);
}

// Order should be Stop C2 (seq 1) then Stop C1 (seq 2)
if ($recalcDeliveries[0]['customer_name'] !== 'Stop C2' || $recalcDeliveries[0]['sequence_number'] !== 1) {
    echo "FAIL: Re-sequencing or ordering failed.\n";
    exit(1);
}
if ($recalcDeliveries[1]['customer_name'] !== 'Stop C1' || $recalcDeliveries[1]['sequence_number'] !== 2) {
    echo "FAIL: Re-sequencing or ordering failed.\n";
    exit(1);
}

echo "Recalculated route distance: " . $recalc['route']['total_distance'] . " m\n";
echo "Recalculated route duration: " . $recalc['route']['total_duration'] . " s\n";

// 6. Test clearing all deliveries (should delete route)
$d1->routeId = null;
$d1->sequenceNumber = null;
$d1->status = 'pending';
$deliveryRepo3->save($d1);

$d2->routeId = null;
$d2->sequenceNumber = null;
$d2->status = 'pending';
$deliveryRepo3->save($d2);

$solver3->recalculateRouteForVehicle($vehicle->id);
if (count($routeRepo3->findAll()) !== 0) {
    echo "FAIL: Route was not deleted after all stops were unassigned.\n";
    exit(1);
}

echo "--- Test 4 PASSED ---\n\n";

echo "=== ALL TESTS PASSED SUCCESSFULLY ===\n";
exit(0);
