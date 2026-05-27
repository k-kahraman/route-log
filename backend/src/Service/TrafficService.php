<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

class TrafficService
{
    private string $provider;
    private string $apiKey;
    private string $lastMethodUsed = 'Unknown';

    public function __construct()
    {
        $this->provider = strtolower(getenv('TRAFFIC_PROVIDER') ?: 'none');
        $this->apiKey = getenv('TOMTOM_API_KEY') ?: '';
    }

    public function getLastMethodUsed(): string
    {
        return $this->lastMethodUsed;
    }

    public function getAdjustedDurations(array $coordinates, array $baseDurations): array
    {
        // Try TomTom Live Traffic if enabled and key is supplied
        if ($this->provider === 'tomtom' && !empty($this->apiKey)) {
            $tomtomDurations = $this->queryTomTomMatrix($coordinates);
            if ($tomtomDurations !== null) {
                $this->lastMethodUsed = 'TomTom Live Traffic API';
                return $tomtomDurations;
            }
        }

        // Default to simulated peak hours
        $this->lastMethodUsed = 'Time-of-day Peak-Hour Traffic Simulation';
        return $this->applySimulatedTraffic($baseDurations);
    }

    private function queryTomTomMatrix(array $coordinates): ?array
    {
        if (empty($coordinates)) {
            return null;
        }

        $points = [];
        foreach ($coordinates as $coord) {
            if (!isset($coord['lat'], $coord['lng'])) {
                throw new RuntimeException('Invalid coordinate structure in traffic request.');
            }
            $points[] = [
                'point' => [
                    'latitude' => floatval($coord['lat']),
                    'longitude' => floatval($coord['lng'])
                ]
            ];
        }

        $url = 'https://api.tomtom.com/routing/1/matrix/json?key=' . $this->apiKey;
        $payload = [
            'origins' => $points,
            'destinations' => $points,
            'options' => [
                'departAt' => 'now',
                'traffic' => true,
                'travelMode' => 'car'
            ]
        ];

        $response = $this->makePostRequest($url, $payload);

        if ($response === null || !isset($response['matrix'])) {
            return null;
        }

        $n = count($coordinates);
        $durations = array_fill(0, $n, array_fill(0, $n, 0.0));

        foreach ($response['matrix'] as $rowIdx => $row) {
            foreach ($row as $colIdx => $cell) {
                $statusCode = $cell['statusCode'] ?? 500;
                $travelTime = 0.0;
                if ($statusCode === 200 && isset($cell['response']['routeSummary']['travelTimeInSeconds'])) {
                    $travelTime = floatval($cell['response']['routeSummary']['travelTimeInSeconds']);
                } else {
                    return null; // Force fallback if any matrix calculation fails
                }
                $durations[$rowIdx][$colIdx] = $travelTime;
            }
        }

        return $durations;
    }

    private function applySimulatedTraffic(array $baseDurations): array
    {
        // Fetch current hour and minute (UTC timezone is standardized in index.php)
        $hour = intval(date('G'));
        $minute = intval(date('i'));

        $isPeak = false;
        // Peak 1: 08:00 to 09:30 UTC
        if ($hour === 8 || ($hour === 9 && $minute <= 30)) {
            $isPeak = true;
        }
        // Peak 2: 17:00 to 18:30 UTC
        if ($hour === 17 || ($hour === 18 && $minute <= 30)) {
            $isPeak = true;
        }

        $multiplier = $isPeak ? 1.4 : 1.0;
        $adjusted = [];

        foreach ($baseDurations as $rowIdx => $row) {
            $adjusted[$rowIdx] = [];
            foreach ($row as $val) {
                $adjusted[$rowIdx][] = floatval($val) * $multiplier;
            }
        }

        return $adjusted;
    }

    private function makePostRequest(string $url, array $payload): ?array
    {
        $jsonPayload = json_encode($payload);
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/json\r\nAccept: application/json\r\n",
                'content' => $jsonPayload,
                'timeout' => 4.0
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
}
