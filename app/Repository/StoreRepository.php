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

    /**
     * @param array{version:int,salt:string,time_cost:int,memory:?int,parallelism:?int} $kdf
     */
    public function create(string $name, string $authHash, array $kdf): int
    {
        $stm = $this->db->prepare(
            'INSERT INTO stores
                (name, auth_hash, kdf_version, kdf_salt, kdf_time_cost, kdf_memory, kdf_parallelism, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stm->execute([
            $name,
            $authHash,
            $kdf['version'],
            $kdf['salt'],
            $kdf['time_cost'],
            $kdf['memory'],
            $kdf['parallelism'],
            Database::now(),
        ]);
        return (int) $this->db->lastInsertId();
    }
}
