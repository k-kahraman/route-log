<?php

date_default_timezone_set('UTC');

$allowedOrigin = getenv('ALLOWED_ORIGIN') ?: 'http://localhost:5173';
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Repository\PdoVehicleRepository;
use App\Repository\PdoDeliveryRepository;
use App\Repository\PdoRouteRepository;
use App\Repository\PdoUserRepository;
use App\Repository\PdoSessionRepository;
use App\Security\SecurityContext;
use App\Service\OrsService;
use App\Service\TrafficService;
use App\Service\SolverService;
use App\Controller\ApiController;
use App\Exception\UnauthorizedException;
use App\Exception\ForbiddenException;
use App\Exception\ValidationException;
use Bramus\Router\Router;

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $vehicleRepo = new PdoVehicleRepository($pdo);
    $deliveryRepo = new PdoDeliveryRepository($pdo);
    $routeRepo = new PdoRouteRepository($pdo);
    $userRepo = new PdoUserRepository($pdo);
    $sessionRepo = new PdoSessionRepository($pdo);

    if (random_int(1, 100) === 1) {
        $sessionRepo->cleanupExpiredSessions();
    }

    $security = new SecurityContext($sessionRepo);

    $orsService = new OrsService();
    $trafficService = new TrafficService();
    $solverService = new SolverService($vehicleRepo, $deliveryRepo, $routeRepo, $orsService, $trafficService);

    $apiController = new ApiController(
        $vehicleRepo,
        $deliveryRepo,
        $routeRepo,
        $userRepo,
        $sessionRepo,
        $security,
        $solverService
    );

    $router = new Router();

    // Centralized Outer Guard: Authenticate all requests except /api/login
    $router->before('GET|POST|DELETE|PATCH', '/api/.*', function () use ($security): void {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        if (str_ends_with($path, '/api/login') || str_ends_with($path, '/login')) {
            return;
        }
        $security->authenticate();
    });

    $router->mount('/api', function () use ($router, $apiController): void {
        // Auth Routes
        $router->post('/login', function () use ($apiController): void {
            $apiController->login();
        });
        $router->post('/logout', function () use ($apiController): void {
            $apiController->logout();
        });
        $router->get('/me', function () use ($apiController): void {
            $apiController->me();
        });

        // User Management Routes (Admin Only)
        $router->get('/users', function () use ($apiController): void {
            $apiController->listUsers();
        });
        $router->post('/users', function () use ($apiController): void {
            $apiController->createUser();
        });
        $router->delete('/users/(\d+)', function (string $id) use ($apiController): void {
            $apiController->deleteUser(intval($id));
        });

        $router->get('/vehicles', function () use ($apiController): void {
            $apiController->listVehicles();
        });
        $router->post('/vehicles', function () use ($apiController): void {
            $apiController->createVehicle();
        });
        $router->delete('/vehicles/(\d+)', function (string $id) use ($apiController): void {
            $apiController->deleteVehicle(intval($id));
        });

        $router->get('/deliveries', function () use ($apiController): void {
            $apiController->listDeliveries();
        });
        $router->post('/deliveries', function () use ($apiController): void {
            $apiController->createDelivery();
        });
        $router->delete('/deliveries/(\d+)', function (string $id) use ($apiController): void {
            $apiController->deleteDelivery(intval($id));
        });
        $router->match('PATCH', '/deliveries/(\d+)/status', function (string $id) use ($apiController): void {
            $apiController->updateDeliveryStatus(intval($id));
        });
        $router->match('PATCH', '/deliveries/(\d+)/route', function (string $id) use ($apiController): void {
            $apiController->updateDeliveryRoute(intval($id));
        });
        $router->match('PATCH', '/routes/(\d+)/sequence', function (string $id) use ($apiController): void {
            $apiController->updateRouteSequence(intval($id));
        });

        $router->get('/routes', function () use ($apiController): void {
            $apiController->listRoutes();
        });
        $router->post('/optimize', function () use ($apiController): void {
            $apiController->runOptimization();
        });
    });

    $router->set404(function (): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'API endpoint not found.']);
    });

    $router->run();

} catch (UnauthorizedException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['error' => $e->getMessage()]);
} catch (ForbiddenException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
} catch (ValidationException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($e->getCode() > 0 ? $e->getCode() : 400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    // Log trace internally to prevent stack leaks to users
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected server error occurred.']);
}
