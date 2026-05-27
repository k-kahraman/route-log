<?php

namespace App;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private ?PDO $connection = null;

    public function getConnection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT');
        $dbName = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $password = getenv('DB_PASSWORD');

        if (empty($host)) {
            throw new RuntimeException('Database host (DB_HOST) is not configured.');
        }
        if (empty($port)) {
            throw new RuntimeException('Database port (DB_PORT) is not configured.');
        }
        if (empty($dbName)) {
            throw new RuntimeException('Database name (DB_NAME) is not configured.');
        }
        if (empty($user)) {
            throw new RuntimeException('Database user (DB_USER) is not configured.');
        }
        if ($password === false) {
            throw new RuntimeException('Database password (DB_PASSWORD) is not configured.');
        }

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbName);

        try {
            $this->connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $this->connection;
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
