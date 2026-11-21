<?php

declare(strict_types=1);

namespace Rumbo\Backend;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $database = $this->config->database();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $database['host'],
            $database['port'],
            $database['name'],
        );

        try {
            $pdo = new PDO($dsn, $database['user'], $database['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true,
                PDO::ATTR_PERSISTENT => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $exception) {
            throw new RuntimeException('No se pudo establecer la conexión segura con la base de datos.', 0, $exception);
        }

        $selectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!hash_equals($database['allowedName'], $selectedDatabase)) {
            throw new RuntimeException('La conexión seleccionó una base distinta a la autorizada.');
        }

        $this->connection = $pdo;
        return $this->connection;
    }
}
