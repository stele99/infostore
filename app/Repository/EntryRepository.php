<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database;
use PDO;

/**
 * Alle Abfragen sind mit store_id gescoped; Mutationen gelten nur als
 * erfolgreich, wenn genau eine Zeile betroffen war.
 */
final class EntryRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function listForStore(int $storeId, int $limit, int $offset): array
    {
        $stm = $this->db->prepare(
            'SELECT entry_uid, crypto_version, title_ct, title_iv, created_at, updated_at
             FROM entries WHERE store_id = :sid
             ORDER BY updated_at DESC, id DESC LIMIT :lim OFFSET :off'
        );
        $stm->bindValue(':sid', $storeId, PDO::PARAM_INT);
        $stm->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stm->bindValue(':off', $offset, PDO::PARAM_INT);
        $stm->execute();
        return $stm->fetchAll();
    }

    public function countForStore(int $storeId): int
    {
        $stm = $this->db->prepare('SELECT COUNT(*) FROM entries WHERE store_id = ?');
        $stm->execute([$storeId]);
        return (int) $stm->fetchColumn();
    }

    public function findForStore(int $storeId, string $entryUid): ?array
    {
        $stm = $this->db->prepare(
            'SELECT entry_uid, crypto_version, title_ct, title_iv, body_ct, body_iv, created_at, updated_at
             FROM entries WHERE store_id = ? AND entry_uid = ?'
        );
        $stm->execute([$storeId, $entryUid]);
        $row = $stm->fetch();
        return $row === false ? null : $row;
    }

    /** Liefert die Besitzer-Store-ID einer UID (für Ownership-Prüfung), sonst null. */
    public function ownerOf(string $entryUid): ?int
    {
        $stm = $this->db->prepare('SELECT store_id FROM entries WHERE entry_uid = ?');
        $stm->execute([$entryUid]);
        $val = $stm->fetchColumn();
        return $val === false ? null : (int) $val;
    }

    public function insert(int $storeId, string $entryUid, int $cryptoVersion, array $f): void
    {
        $now = Database::now();
        $stm = $this->db->prepare(
            'INSERT INTO entries (store_id, entry_uid, crypto_version, title_ct, title_iv, body_ct, body_iv, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stm->execute([$storeId, $entryUid, $cryptoVersion, $f['title_ct'], $f['title_iv'], $f['body_ct'], $f['body_iv'], $now, $now]);
    }

    public function update(int $storeId, string $entryUid, int $cryptoVersion, array $f): bool
    {
        $stm = $this->db->prepare(
            'UPDATE entries SET crypto_version = ?, title_ct = ?, title_iv = ?, body_ct = ?, body_iv = ?, updated_at = ?
             WHERE entry_uid = ? AND store_id = ?'
        );
        $stm->execute([$cryptoVersion, $f['title_ct'], $f['title_iv'], $f['body_ct'], $f['body_iv'], Database::now(), $entryUid, $storeId]);
        return $stm->rowCount() === 1;
    }

    public function delete(int $storeId, string $entryUid): bool
    {
        $stm = $this->db->prepare('DELETE FROM entries WHERE entry_uid = ? AND store_id = ?');
        $stm->execute([$entryUid, $storeId]);
        return $stm->rowCount() === 1;
    }
}
