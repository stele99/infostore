<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database;
use PDO;

final class StoreRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findByName(string $name): ?array
    {
        $stm = $this->db->prepare('SELECT * FROM stores WHERE name = ?');
        $stm->execute([$name]);
        $row = $stm->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $name, string $authHash, string $kdfSalt, int $kdfIterations, int $kdfVersion): int
    {
        $stm = $this->db->prepare(
            'INSERT INTO stores (name, auth_hash, kdf_salt, kdf_iterations, kdf_version, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stm->execute([$name, $authHash, $kdfSalt, $kdfIterations, $kdfVersion, Database::now()]);
        return (int) $this->db->lastInsertId();
    }
}
