<?php

namespace App\Repository;

use App\Entity\User;
use PDO;

class PdoSessionRepository implements SessionRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createSession(int $userId, string $token, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_sessions (token, user_id, expires_at) 
             VALUES (:token, :user_id, :expires_at)'
        );
        $stmt->execute([
            'token' => $token,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
        ]);
    }

    public function deleteSession(string $token): void
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE token = :token');
        $stmt->execute(['token' => $token]);
    }

    public function findUserByToken(string $token): ?User
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.username, u.password_hash, u.role, u.vehicle_id, u.created_at 
             FROM user_sessions s
             JOIN users u ON s.user_id = u.id
             WHERE s.token = :token AND s.expires_at > CURRENT_TIMESTAMP'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return new User(
            intval($row['id']),
            $row['username'],
            $row['password_hash'],
            $row['role'],
            $row['vehicle_id'] !== null ? intval($row['vehicle_id']) : null,
            $row['created_at'] ?? ''
        );
    }

    public function cleanupExpiredSessions(): int
    {
        $stmt = $this->db->query('DELETE FROM user_sessions WHERE expires_at < CURRENT_TIMESTAMP');
        return $stmt->rowCount();
    }
}
