<?php

namespace App\Repository;

use App\Entity\User;
use PDO;
use RuntimeException;

class PdoUserRepository implements UserRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function mapRowToEntity(array $row): User
    {
        if (!isset($row['id'], $row['username'], $row['password_hash'], $row['role'])) {
            throw new RuntimeException('Database row is missing required fields to map a User entity.');
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

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->db->prepare('SELECT id, username, password_hash, role, vehicle_id, created_at FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToEntity($row);
    }

    public function findById(int $id): ?User
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id, username, password_hash, role, vehicle_id, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToEntity($row);
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, username, password_hash, role, vehicle_id, created_at FROM users ORDER BY id ASC');
        $users = [];
        while ($row = $stmt->fetch()) {
            $users[] = $this->mapRowToEntity($row);
        }
        return $users;
    }

    public function save(User $user): User
    {
        if (empty($user->username)) {
            throw new RuntimeException('Cannot save user: Username is empty.');
        }
        if (empty($user->passwordHash)) {
            throw new RuntimeException('Cannot save user: Password hash is empty.');
        }
        if (empty($user->role)) {
            throw new RuntimeException('Cannot save user: Role is empty.');
        }

        if ($user->id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO users (username, password_hash, role, vehicle_id) 
                 VALUES (:username, :password_hash, :role, :vehicle_id) 
                 RETURNING id, created_at'
            );
            $stmt->execute([
                'username' => $user->username,
                'password_hash' => $user->passwordHash,
                'role' => $user->role,
                'vehicle_id' => $user->vehicleId,
            ]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new RuntimeException('Failed to insert user.');
            }

            return new User(
                intval($row['id']),
                $user->username,
                $user->passwordHash,
                $user->role,
                $user->vehicleId,
                $row['created_at']
            );
        } else {
            $stmt = $this->db->prepare(
                'UPDATE users 
                 SET username = :username, password_hash = :password_hash, role = :role, vehicle_id = :vehicle_id 
                 WHERE id = :id'
            );
            $stmt->execute([
                'username' => $user->username,
                'password_hash' => $user->passwordHash,
                'role' => $user->role,
                'vehicle_id' => $user->vehicleId,
                'id' => $user->id,
            ]);
            return $user;
        }
    }

    public function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
