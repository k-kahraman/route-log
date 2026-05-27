<?php

namespace App\Controller;

use App\Entity\Vehicle;
use App\Entity\Delivery;
use App\Entity\Route;
use App\Entity\User;
use App\Repository\VehicleRepositoryInterface;
use App\Repository\DeliveryRepositoryInterface;
use App\Repository\RouteRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Repository\SessionRepositoryInterface;
use App\Security\SecurityContext;
use App\Service\SolverService;
use App\Exception\UnauthorizedException;
use App\Exception\ForbiddenException;
use App\Exception\ValidationException;
use Throwable;

class ApiController
{
    private VehicleRepositoryInterface $vehicleRepo;
    private DeliveryRepositoryInterface $deliveryRepo;
    private RouteRepositoryInterface $routeRepo;
    private UserRepositoryInterface $userRepo;
    private SessionRepositoryInterface $sessionRepo;
    private SecurityContext $security;
    private SolverService $solverService;

    public function __construct(
        VehicleRepositoryInterface $vehicleRepo,
        DeliveryRepositoryInterface $deliveryRepo,
        RouteRepositoryInterface $routeRepo,
        UserRepositoryInterface $userRepo,
        SessionRepositoryInterface $sessionRepo,
        SecurityContext $security,
        SolverService $solverService
    ) {
        $this->vehicleRepo = $vehicleRepo;
        $this->deliveryRepo = $deliveryRepo;
        $this->routeRepo = $routeRepo;
        $this->userRepo = $userRepo;
        $this->sessionRepo = $sessionRepo;
        $this->security = $security;
        $this->solverService = $solverService;
    }

    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    private function getJsonPayload(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }
        $data = json_decode($input, true);
        return is_array($data) ? $data : [];
    }

    // Public auth endpoint
    public function login(): void
    {
        $payload = $this->getJsonPayload();
        $username = trim($payload['username'] ?? '');
        $password = trim($payload['password'] ?? '');

        if (empty($username) || empty($password)) {
            throw new ValidationException('Username and password are required.', 400);
        }

        $user = $this->userRepo->findByUsername($username);
        if ($user === null || !password_verify($password, $user->passwordHash)) {
            throw new UnauthorizedException('Invalid username or password.');
        }

        $token = bin2hex(random_bytes(32));

        $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400); // 24 hours expiry
        $this->sessionRepo->createSession($user->id, $token, $expiresAt);

        $this->jsonResponse([
            'token' => $token,
            'user' => $user->toArray()
        ]);
    }

    public function logout(): void
    {
        $authHeader = null;
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }

        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $this->sessionRepo->deleteSession($token);
        }

        $this->jsonResponse(['message' => 'Logged out successfully.']);
    }

    public function me(): void
    {
        $user = $this->security->authenticate();
        $this->jsonResponse($user->toArray());
    }

    // User management (Admin only)
    public function listUsers(): void
    {
        $this->security->requireRole(['admin']);
        $users = $this->userRepo->findAll();
        $this->jsonResponse(array_map(fn(User $u) => $u->toArray(), $users));
    }

    public function createUser(): void
    {
        $this->security->requireRole(['admin']);
        $payload = $this->getJsonPayload();
        $username = trim($payload['username'] ?? '');
        $password = trim($payload['password'] ?? '');
        $role = trim($payload['role'] ?? '');
        $vehicleId = isset($payload['vehicle_id']) && is_numeric($payload['vehicle_id']) ? intval($payload['vehicle_id']) : null;

        if (empty($username) || empty($password) || empty($role)) {
            throw new ValidationException('Username, password, and role are required.', 400);
        }

        if (!in_array($role, ['admin', 'operator', 'driver'], true)) {
            throw new ValidationException('Invalid role. Must be admin, operator, or driver.', 400);
        }

        // F-09: Username length limit
        if (strlen($username) > 50) {
            throw new ValidationException('Username cannot exceed 50 characters.', 400);
        }

        // F-10: Password complexity length check
        if (strlen($password) < 8) {
            throw new ValidationException('Password must be at least 8 characters long.', 400);
        }

        $existing = $this->userRepo->findByUsername($username);
        if ($existing !== null) {
            throw new ValidationException('Username already exists.', 400);
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $newUser = new User(null, $username, $hashed, $role, $vehicleId);
        $saved = $this->userRepo->save($newUser);

        $this->jsonResponse($saved->toArray(), 201);
    }

    public function deleteUser(int $id): void
    {
        $currentUser = $this->security->requireRole(['admin']);

        // F-05: Block Admin self-lockout / self-deletion
        if ($currentUser->id === $id) {
            throw new ValidationException('You cannot delete your own account.', 400);
        }

        $deleted = $this->userRepo->deleteById($id);
        if ($deleted) {
            $this->jsonResponse(['message' => 'User deleted successfully.']);
        } else {
            $this->jsonResponse(['error' => 'User not found.'], 404);
        }
    }

    // Vehicle management
    public function listVehicles(): void
    {
        $user = $this->security->requireRole(['admin', 'operator', 'driver']);

        if ($user->role === 'driver') {
            if ($user->vehicleId !== null) {
                $vehicle = $this->vehicleRepo->findById($user->vehicleId);
                $this->jsonResponse($vehicle !== null ? [$vehicle->toArray()] : []);
            } else {
                $this->jsonResponse([]);
            }
        } else {
            $vehicles = $this->vehicleRepo->findAll();
            $this->jsonResponse(array_map(fn(Vehicle $v) => $v->toArray(), $vehicles));
        }
    }

    public function createVehicle(): void
    {
        $this->security->requireRole(['admin', 'operator']);
        $payload = $this->getJsonPayload();

        if (!isset($payload['name']) || empty($payload['name'])) {
            throw new ValidationException('Field "name" is required.', 400);
        }
        if (!isset($payload['capacity']) || !is_numeric($payload['capacity']) || floatval($payload['capacity']) <= 0) {
            throw new ValidationException('Field "capacity" must be a positive number.', 400);
        }
        if (!isset($payload['start_lat']) || !is_numeric($payload['start_lat'])) {
            throw new ValidationException('Field "start_lat" must be a valid number.', 400);
        }
        if (!isset($payload['start_lng']) || !is_numeric($payload['start_lng'])) {
            throw new ValidationException('Field "start_lng" must be a valid number.', 400);
        }

        // F-04: Coordinate Range Checks
        $startLat = floatval($payload['start_lat']);
        $startLng = floatval($payload['start_lng']);
        if ($startLat < -90.0 || $startLat > 90.0 || $startLng < -180.0 || $startLng > 180.0) {
            throw new ValidationException('Depot coordinates out of valid range (Latitude: -90 to 90, Longitude: -180 to 180).', 400);
        }

        $endLat = isset($payload['end_lat']) && is_numeric($payload['end_lat']) ? floatval($payload['end_lat']) : $startLat;
        $endLng = isset($payload['end_lng']) && is_numeric($payload['end_lng']) ? floatval($payload['end_lng']) : $startLng;
        if ($endLat < -90.0 || $endLat > 90.0 || $endLng < -180.0 || $endLng > 180.0) {
            throw new ValidationException('End coordinates out of valid range (Latitude: -90 to 90, Longitude: -180 to 180).', 400);
        }

        // F-09: Vehicle Name Length Limit
        $name = trim(strval($payload['name']));
        if (strlen($name) > 100) {
            throw new ValidationException('Vehicle name cannot exceed 100 characters.', 400);
        }

        $status = isset($payload['status']) ? strval($payload['status']) : 'active';

        // EV fields with validation and defaults
        $batteryCapacity = 50.0;
        if (isset($payload['battery_capacity'])) {
            if (!is_numeric($payload['battery_capacity']) || floatval($payload['battery_capacity']) <= 0) {
                throw new ValidationException('Field "battery_capacity" must be a positive number.', 400);
            }
            $batteryCapacity = floatval($payload['battery_capacity']);
        }

        $currentBattery = $batteryCapacity;
        if (isset($payload['current_battery'])) {
            if (!is_numeric($payload['current_battery']) || floatval($payload['current_battery']) <= 0) {
                throw new ValidationException('Field "current_battery" must be a positive number.', 400);
            }
            $currentBattery = floatval($payload['current_battery']);
            if ($currentBattery > $batteryCapacity) {
                throw new ValidationException('Field "current_battery" cannot exceed "battery_capacity".', 400);
            }
        }

        $consumptionRate = 0.20;
        if (isset($payload['consumption_rate'])) {
            if (!is_numeric($payload['consumption_rate']) || floatval($payload['consumption_rate']) <= 0) {
                throw new ValidationException('Field "consumption_rate" must be a positive number.', 400);
            }
            $consumptionRate = floatval($payload['consumption_rate']);
        }

        $vehicle = new Vehicle(
            null,
            $name,
            floatval($payload['capacity']),
            $startLat,
            $startLng,
            $endLat,
            $endLng,
            $status,
            $batteryCapacity,
            $currentBattery,
            $consumptionRate
        );

        $saved = $this->vehicleRepo->save($vehicle);
        $this->jsonResponse($saved->toArray(), 201);
    }

    public function deleteVehicle(int $id): void
    {
        $this->security->requireRole(['admin', 'operator']);

        if ($id <= 0) {
            throw new ValidationException('Invalid vehicle ID.', 400);
        }

        $deleted = $this->vehicleRepo->deleteById($id);
        if ($deleted) {
            $this->jsonResponse(['message' => 'Vehicle deleted successfully.']);
        } else {
            $this->jsonResponse(['error' => 'Vehicle not found.'], 404);
        }
    }

    // Delivery management
    public function listDeliveries(): void
    {
        $user = $this->security->requireRole(['admin', 'operator', 'driver']);

        if ($user->role === 'driver') {
            if ($user->vehicleId !== null) {
                // F-02: Iterate and merge deliveries across ALL routes assigned to driver's vehicle
                $routes = $this->routeRepo->findByVehicleId($user->vehicleId);
                $deliveries = [];
                foreach ($routes as $route) {
                    $routeStops = $this->deliveryRepo->findByRouteId($route->id);
                    foreach ($routeStops as $stop) {
                        $deliveries[] = $stop;
                    }
                }
                $this->jsonResponse(array_map(fn(Delivery $d) => $d->toArray(), $deliveries));
            } else {
                $this->jsonResponse([]);
            }
        } else {
            $deliveries = $this->deliveryRepo->findAll();
            $this->jsonResponse(array_map(fn(Delivery $d) => $d->toArray(), $deliveries));
        }
    }

    public function createDelivery(): void
    {
        $this->security->requireRole(['admin', 'operator']);
        $payload = $this->getJsonPayload();

        if (!isset($payload['customer_name']) || empty($payload['customer_name'])) {
            throw new ValidationException('Field "customer_name" is required.', 400);
        }
        if (!isset($payload['address']) || empty($payload['address'])) {
            throw new ValidationException('Field "address" is required.', 400);
        }
        if (!isset($payload['lat']) || !is_numeric($payload['lat'])) {
            throw new ValidationException('Field "lat" must be a valid number.', 400);
        }
        if (!isset($payload['lng']) || !is_numeric($payload['lng'])) {
            throw new ValidationException('Field "lng" must be a valid number.', 400);
        }
        if (!isset($payload['weight']) || !is_numeric($payload['weight']) || floatval($payload['weight']) <= 0) {
            throw new ValidationException('Field "weight" must be a positive number.', 400);
        }

        // F-04: Coordinate Range Checks
        $lat = floatval($payload['lat']);
        $lng = floatval($payload['lng']);
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            throw new ValidationException('Coordinates out of valid range (Latitude: -90 to 90, Longitude: -180 to 180).', 400);
        }

        // F-09: Unbounded String Inputs limit checks
        $custName = trim(strval($payload['customer_name']));
        if (strlen($custName) > 150) {
            throw new ValidationException('Customer name cannot exceed 150 characters.', 400);
        }
        $address = trim(strval($payload['address']));
        if (strlen($address) > 255) {
            throw new ValidationException('Address cannot exceed 255 characters.', 400);
        }

        $delivery = new Delivery(
            null,
            $custName,
            $address,
            $lat,
            $lng,
            floatval($payload['weight']),
            'pending'
        );

        $saved = $this->deliveryRepo->save($delivery);
        $this->jsonResponse($saved->toArray(), 201);
    }

    public function deleteDelivery(int $id): void
    {
        $this->security->requireRole(['admin', 'operator']);

        if ($id <= 0) {
            throw new ValidationException('Invalid delivery ID.', 400);
        }

        $deleted = $this->deliveryRepo->deleteById($id);
        if ($deleted) {
            $this->jsonResponse(['message' => 'Delivery deleted successfully.']);
        } else {
            $this->jsonResponse(['error' => 'Delivery not found.'], 404);
        }
    }

    public function updateDeliveryStatus(int $id): void
    {
        $user = $this->security->requireRole(['admin', 'operator', 'driver']);
        $payload = $this->getJsonPayload();
        $status = trim($payload['status'] ?? '');

        if (!in_array($status, ['pending', 'assigned', 'delivered', 'failed'], true)) {
            throw new ValidationException('Invalid status. Must be pending, assigned, delivered, or failed.', 400);
        }

        $delivery = $this->deliveryRepo->findById($id);
        if ($delivery === null) {
            throw new ValidationException('Delivery not found.', 404);
        }

        // Driver authorization check
        if ($user->role === 'driver') {
            if ($user->vehicleId === null || $delivery->routeId === null) {
                throw new ForbiddenException('Forbidden. This delivery is not assigned to your vehicle.');
            }
            $route = $this->routeRepo->findById($delivery->routeId);
            if ($route === null || $route->vehicleId !== $user->vehicleId) {
                throw new ForbiddenException('Forbidden. This delivery is not assigned to your vehicle.');
            }
        }

        $delivery->status = $status;
        $updated = $this->deliveryRepo->save($delivery);
        $this->jsonResponse($updated->toArray());
    }

    // Routes management
    public function listRoutes(): void
    {
        $user = $this->security->requireRole(['admin', 'operator', 'driver']);

        if ($user->role === 'driver') {
            if ($user->vehicleId !== null) {
                $routes = $this->routeRepo->findByVehicleId($user->vehicleId);
            } else {
                $routes = [];
            }
        } else {
            $routes = $this->routeRepo->findAll();
        }

        $data = [];
        foreach ($routes as $route) {
            $vehicle = $this->vehicleRepo->findById($route->vehicleId);
            $deliveries = $this->deliveryRepo->findByRouteId($route->id);
            $data[] = [
                'route' => $route->toArray(),
                'vehicle' => $vehicle !== null ? $vehicle->toArray() : null,
                'deliveries' => array_map(fn(Delivery $d) => $d->toArray(), $deliveries)
            ];
        }

        $this->jsonResponse($data);
    }

    public function runOptimization(): void
    {
        $this->security->requireRole(['admin', 'operator']);
        $payload = $this->getJsonPayload();
        $vehicleId = isset($payload['vehicle_id']) && is_numeric($payload['vehicle_id']) ? intval($payload['vehicle_id']) : null;

        $result = $this->solverService->optimize($vehicleId);
        if (isset($result['success']) && $result['success'] === false) {
            $this->jsonResponse($result, 400);
        } else {
            $this->jsonResponse($result);
        }
    }

    public function updateDeliveryRoute(int $id): void
    {
        $this->security->requireRole(['admin', 'operator']);
        
        $delivery = $this->deliveryRepo->findById($id);
        if ($delivery === null) {
            throw new ValidationException('Delivery not found.', 404);
        }

        $payload = $this->getJsonPayload();
        $vehicleId = isset($payload['vehicle_id']) && is_numeric($payload['vehicle_id']) ? intval($payload['vehicle_id']) : null;

        // Remember the old route if there was one
        $oldRouteId = $delivery->routeId;
        $oldVehicleId = null;
        if ($oldRouteId !== null) {
            $oldRoute = $this->routeRepo->findById($oldRouteId);
            if ($oldRoute !== null) {
                $oldVehicleId = $oldRoute->vehicleId;
            }
        }

        if ($vehicleId === null) {
            $delivery->routeId = null;
            $delivery->sequenceNumber = null;
            $delivery->status = 'pending';
            $this->deliveryRepo->save($delivery);
        } else {
            $vehicle = $this->vehicleRepo->findById($vehicleId);
            if ($vehicle === null) {
                throw new ValidationException('Vehicle not found.', 404);
            }
            
            $route = $this->solverService->getOrCreateRouteForVehicle($vehicleId);
            
            $existingStops = $this->deliveryRepo->findByRouteId($route->id);
            
            $delivery->routeId = $route->id;
            $delivery->sequenceNumber = count($existingStops) + 1;
            $delivery->status = 'assigned';
            $this->deliveryRepo->save($delivery);
        }

        // Recalculate routes
        if ($vehicleId !== null) {
            $this->solverService->recalculateRouteForVehicle($vehicleId);
        }
        if ($oldVehicleId !== null && $oldVehicleId !== $vehicleId) {
            $this->solverService->recalculateRouteForVehicle($oldVehicleId);
        }

        $refreshed = $this->deliveryRepo->findById($id);
        $this->jsonResponse($refreshed !== null ? $refreshed->toArray() : []);
    }

    public function updateRouteSequence(int $id): void
    {
        $this->security->requireRole(['admin', 'operator']);

        $route = $this->routeRepo->findById($id);
        if ($route === null) {
            throw new ValidationException('Route not found.', 404);
        }

        $payload = $this->getJsonPayload();
        $deliveryIds = $payload['delivery_ids'] ?? [];

        if (!is_array($deliveryIds)) {
            throw new ValidationException('Invalid delivery_ids payload.', 400);
        }

        // Re-sequence all deliveries in the payload
        $sequence = 1;
        foreach ($deliveryIds as $dId) {
            if (!is_numeric($dId)) {
                continue;
            }
            $dIdInt = intval($dId);
            $delivery = $this->deliveryRepo->findById($dIdInt);
            if ($delivery !== null && $delivery->routeId === $route->id) {
                $delivery->sequenceNumber = $sequence++;
                $this->deliveryRepo->save($delivery);
            }
        }

        // Recalculate route for the vehicle associated with this route
        $recalculated = $this->solverService->recalculateRouteForVehicle($route->vehicleId);

        $this->jsonResponse($recalculated !== null ? $recalculated : []);
    }
}
