<?php

namespace App\Repository;

use App\Entity\User;

interface SessionRepositoryInterface
{
    public function createSession(int $userId, string $token, string $expiresAt): void;
    public function deleteSession(string $token): void;
    public function findUserByToken(string $token): ?User;
    public function cleanupExpiredSessions(): int;
}
