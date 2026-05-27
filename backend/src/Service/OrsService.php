<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

class OrsService
{
    private string $baseUrl;
    private string $apiKey;
    private string $lastMethodUsed = 'Unknown';

    public function __construct()
    {
        $this->baseUrl = rtrim(getenv('ORS_BASE_URL') ?: 'https://api.openrouteservice.org', '/');
        $this->apiKey = getenv('ORS_API_KEY') ?: '';
    }

    public function getLastMethodUsed(): string
    {
        return $this->lastMethodUsed;
    }

    public function getDistanceMatrix(array $coordinates): array
    {
        if (empty($coordinates)) {
            return ['distances' => [], 'durations' => []];
        }

        // If no API key is provided, try the public OSRM router first
        if (empty($this->apiKey)) {
            $osrmResult = $this->queryOsrmMatrix($coordinates);
            if ($osrmResult !== null) {
                $this->lastMethodUsed = 'Public OSRM API (Keyless)';
                return $osrmResult;
            }
            $this->lastMethodUsed = 'Haversine Mathematical Fallback';
            return $this->calculateHaversineMatrix($coordinates);
        }

        $locations = [];
        foreach ($coordinates as $coord) {
            if (!isset($coord['lat'], $coord['lng'])) {
                throw new RuntimeException('Invalid coordinate structure in matrix request.');
            }
            $locations[] = [floatval($coord['lng']), floatval($coord['lat'])];
        }

        $url = $this->baseUrl . '/v2/matrix/driving-car';
        $payload = [
            'locations' => $locations,
            'metrics' => ['distance', 'duration']
        ];

        $response = $this->makePostRequest($url, $payload);

        if ($response === null || !isset($response['distances']) || !isset($response['durations'])) {
            // Try OSRM as a secondary fallback if ORS request fails
            $osrmResult = $this->queryOsrmMatrix($coordinates);
            if ($osrmResult !== null) {
                $this->lastMethodUsed = 'Public OSRM API (Keyless) (ORS Fallback)';
                return $osrmResult;
            }
            $this->lastMethodUsed = 'Haversine Mathematical Fallback (ORS Fallback)';
            return $this->calculateHaversineMatrix($coordinates);
        }

        $distances = [];
        $durations = [];

        foreach ($response['distances'] as $rowIdx => $row) {
            $distances[$rowIdx] = [];
            foreach ($row as $val) {
                $distances[$rowIdx][] = $val !== null ? floatval($val) : 9999999.0;
            }
        }

        foreach ($response['durations'] as $rowIdx => $row) {
            $durations[$rowIdx] = [];
            foreach ($row as $val) {
                $durations[$rowIdx][] = $val !== null ? floatval($val) : 9999999.0;
            }
        }

        $this->lastMethodUsed = 'OpenRouteService API';
        return [
            'distances' => $distances,
            'durations' => $durations,
        ];
    }

    public function getRouteDetails(array $coordinates): array
    {
        if (count($coordinates) < 2) {
            return [
                'geometry' => json_encode([
                    'type' => 'LineString',
                    'coordinates' => array_map(fn($c) => [floatval($c['lng']), floatval($c['lat'])], $coordinates)
                ]),
                'distance' => 0.0,
                'duration' => 0.0
            ];
        }

        // If no API key is provided, try the public OSRM router first
        if (empty($this->apiKey)) {
            $osrmResult = $this->queryOsrmRoute($coordinates);
            if ($osrmResult !== null) {
                $this->lastMethodUsed = 'Public OSRM API (Keyless)';
                return $osrmResult;
            }
            $this->lastMethodUsed = 'Haversine Mathematical Fallback';
            return $this->calculateHaversineRoute($coordinates);
        }

        $coords = [];
        foreach ($coordinates as $coord) {
            if (!isset($coord['lat'], $coord['lng'])) {
                throw new RuntimeException('Invalid coordinate structure in route details request.');
            }
            $coords[] = [floatval($coord['lng']), floatval($coord['lat'])];
        }

        $url = $this->baseUrl . '/v2/directions/driving-car/geojson';
        $payload = [
            'coordinates' => $coords,
            'elevation' => true
        ];

        $response = $this->makePostRequest($url, $payload);

        if ($response === null || empty($response['features'])) {
            // Try OSRM as a secondary fallback if ORS request fails
            $osrmResult = $this->queryOsrmRoute($coordinates);
            if ($osrmResult !== null) {
                $this->lastMethodUsed = 'Public OSRM API (Keyless) (ORS Fallback)';
                return $osrmResult;
            }
            $this->lastMethodUsed = 'Haversine Mathematical Fallback (ORS Fallback)';
            return $this->calculateHaversineRoute($coordinates);
        }

        $feature = $response['features'][0];
        $properties = $feature['properties'] ?? [];
        $summary = $properties['summary'] ?? [];
        $geometry = $feature['geometry'] ?? [];

        if (empty($geometry) || !isset($summary['distance'], $summary['duration'])) {
            // Try OSRM as a tertiary fallback
            $osrmResult = $this->queryOsrmRoute($coordinates);
            if ($osrmResult !== null) {
                $this->lastMethodUsed = 'Public OSRM API (Keyless) (ORS Fallback)';
                return $osrmResult;
            }
            $this->lastMethodUsed = 'Haversine Mathematical Fallback (ORS Fallback)';
            return $this->calculateHaversineRoute($coordinates);
        }

        $this->lastMethodUsed = 'OpenRouteService API';
        return [
            'geometry' => json_encode($geometry),
            'distance' => floatval($summary['distance']),
            'duration' => floatval($summary['duration']),
        ];
    }

    private function queryOsrmMatrix(array $coordinates): ?array
    {
        $coordStrings = [];
        foreach ($coordinates as $coord) {
            $coordStrings[] = sprintf('%f,%f', floatval($coord['lng']), floatval($coord['lat']));
        }

        $url = sprintf(
            'https://router.project-osrm.org/table/v1/driving/%s?annotations=distance,duration',
            implode(';', $coordStrings)
        );

        $response = $this->makeGetRequest($url);

        if ($response === null || !isset($response['code']) || $response['code'] !== 'Ok') {
            return null;
        }

        $distances = [];
        $durations = [];

        foreach (($response['distances'] ?? []) as $rowIdx => $row) {
            $distances[$rowIdx] = [];
            foreach ($row as $val) {
                $distances[$rowIdx][] = $val !== null ? floatval($val) : 9999999.0;
            }
        }

        foreach (($response['durations'] ?? []) as $rowIdx => $row) {
            $durations[$rowIdx] = [];
            foreach ($row as $val) {
                $durations[$rowIdx][] = $val !== null ? floatval($val) : 9999999.0;
            }
        }

        return [
            'distances' => $distances,
            'durations' => $durations,
        ];
    }

    private function queryOsrmRoute(array $coordinates): ?array
    {
        $coordStrings = [];
        foreach ($coordinates as $coord) {
            $coordStrings[] = sprintf('%f,%f', floatval($coord['lng']), floatval($coord['lat']));
        }

        $url = sprintf(
            'https://router.project-osrm.org/route/v1/driving/%s?overview=full&geometries=geojson',
            implode(';', $coordStrings)
        );

        $response = $this->makeGetRequest($url);

        if ($response === null || !isset($response['code']) || $response['code'] !== 'Ok' || empty($response['routes'])) {
            return null;
        }

        $route = $response['routes'][0];
        if (!isset($route['geometry'], $route['distance'], $route['duration'])) {
            return null;
        }

        return [
            'geometry' => json_encode($route['geometry']),
            'distance' => floatval($route['distance']),
            'duration' => floatval($route['duration']),
        ];
    }

    private function makePostRequest(string $url, array $payload): ?array
    {
        $jsonPayload = json_encode($payload);
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/json\r\n" .
                            "Authorization: " . $this->apiKey . "\r\n" .
                            "User-Agent: RouteLogPoC/1.0\r\n",
                'content' => $jsonPayload,
                'timeout' => 5.0
            ]
        ];

        $context = stream_context_create($opts);

        try {
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                return null;
            }
            return json_decode($result, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function makeGetRequest(string $url): ?array
    {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: RouteLogPoC/1.0\r\nAccept: application/json\r\n",
                'timeout' => 5.0
            ]
        ];

        $context = stream_context_create($opts);

        try {
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                return null;
            }
            return json_decode($result, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function calculateHaversineMatrix(array $coordinates): array
    {
        $n = count($coordinates);
        $distances = array_fill(0, $n, array_fill(0, $n, 0.0));
        $durations = array_fill(0, $n, array_fill(0, $n, 0.0));
        $averageSpeed = 13.88;

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }
                $dist = $this->haversineDistance(
                    floatval($coordinates[$i]['lat']),
                    floatval($coordinates[$i]['lng']),
                    floatval($coordinates[$j]['lat']),
                    floatval($coordinates[$j]['lng'])
                );
                $distances[$i][$j] = $dist;
                $durations[$i][$j] = $dist / $averageSpeed;
            }
        }

        return [
            'distances' => $distances,
            'durations' => $durations,
        ];
    }

    private function calculateHaversineRoute(array $coordinates): array
    {
        $distance = 0.0;
        $averageSpeed = 13.88;

        for ($i = 0; $i < count($coordinates) - 1; $i++) {
            $distance += $this->haversineDistance(
                floatval($coordinates[$i]['lat']),
                floatval($coordinates[$i]['lng']),
                floatval($coordinates[$i + 1]['lat']),
                floatval($coordinates[$i + 1]['lng'])
            );
        }

        $geoJson = [
            'type' => 'LineString',
            'coordinates' => array_map(fn($c) => [floatval($c['lng']), floatval($c['lat'])], $coordinates)
        ];

        return [
            'geometry' => json_encode($geoJson),
            'distance' => $distance,
            'duration' => $distance / $averageSpeed
        ];
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
