<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\SessionRepositoryInterface;
use App\Exception\UnauthorizedException;
use App\Exception\ForbiddenException;

class SecurityContext
{
    private ?User $currentUser = null;
    private bool $resolved = false;
    private SessionRepositoryInterface $sessionRepo;

    public function __construct(SessionRepositoryInterface $sessionRepo)
    {
        $this->sessionRepo = $sessionRepo;
    }

    public function authenticate(): User
    {
        if ($this->resolved) {
            if ($this->currentUser === null) {
                throw new UnauthorizedException('Unauthorized.');
            }
            return $this->currentUser;
        }

        $this->resolved = true;
        $authHeader = null;
        $headers = getallheaders();

        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if ($authHeader === null || !str_starts_with($authHeader, 'Bearer ')) {
            throw new UnauthorizedException('Unauthorized.');
        }

        $token = substr($authHeader, 7);
        if (empty($token)) {
            throw new UnauthorizedException('Unauthorized.');
        }

        $user = $this->sessionRepo->findUserByToken($token);
        if ($user === null) {
            throw new UnauthorizedException('Unauthorized.');
        }

        $this->currentUser = $user;
        return $user;
    }

    public function requireRole(array $allowedRoles): User
    {
        $user = $this->authenticate();
        // Fail-closed role checks
        if (!in_array($user->role, $allowedRoles, true)) {
            throw new ForbiddenException('Forbidden.');
        }
        return $user;
    }

    public function getCurrentUser(): ?User
    {
        try {
            return $this->authenticate();
        } catch (UnauthorizedException) {
            return null;
        }
    }
}
