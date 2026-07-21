<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database;
use PDO;

final class ShareRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function countForStore(int $storeId): int
    {
        $stm = $this->db->prepare('SELECT COUNT(*) FROM shares WHERE store_id = ?');
        $stm->execute([$storeId]);
        return (int) $stm->fetchColumn();
    }

    public function insert(int $storeId, array $f): int
    {
        $stm = $this->db->prepare(
            'INSERT INTO shares (share_uid, store_id, crypto_version, wrapped_key, wrap_iv, kdf_salt, kdf_iterations,
                                 seed_auth_hash, owner_mail, delay_hours, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
        );
        $stm->execute([
            $f['share_uid'], $storeId, $f['crypto_version'], $f['wrapped_key'], $f['wrap_iv'],
            $f['kdf_salt'], $f['kdf_iterations'], $f['seed_auth_hash'], $f['owner_mail'],
            $f['delay_hours'], Database::now(),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Owner-Sicht: keine Key-Pakete, nur Status und Metadaten. */
    public function listForOwner(int $storeId): array
    {
        $stm = $this->db->prepare(
            'SELECT share_uid, owner_mail, delay_hours, status, requested_at, available_at,
                    decided_at, revoked_at, created_at
             FROM shares WHERE store_id = ? ORDER BY created_at DESC'
        );
        $stm->execute([$storeId]);
        return $stm->fetchAll();
    }

    public function findForStoreByUid(int $storeId, string $shareUid): ?array
    {
        $stm = $this->db->prepare('SELECT * FROM shares WHERE store_id = ? AND share_uid = ?');
        $stm->execute([$storeId, $shareUid]);
        $row = $stm->fetch();
        return $row === false ? null : $row;
    }

    /** Aktive KDF-Parameter fuer einen Store-Namen (Recipient-Flow, vor Auth). */
    public function saltsForStoreName(string $storeName): array
    {
        $stm = $this->db->prepare(
            'SELECT s.share_uid, s.kdf_salt, s.kdf_iterations, s.crypto_version
             FROM shares s JOIN stores st ON st.id = s.store_id
             WHERE st.name = ? AND s.status IN (\'active\', \'requested\', \'granted\')
             ORDER BY s.created_at DESC LIMIT 10'
        );
        $stm->execute([$storeName]);
        return $stm->fetchAll();
    }

    public function findByUidAndStoreName(string $shareUid, string $storeName): ?array
    {
        $stm = $this->db->prepare(
            'SELECT s.*, st.name AS store_name FROM shares s JOIN stores st ON st.id = s.store_id
             WHERE s.share_uid = ? AND st.name = ?'
        );
        $stm->execute([$shareUid, $storeName]);
        $row = $stm->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Atomarer Statusuebergang: schreibt nur, wenn der Ist-Status noch stimmt.
     * @param array $set zusaetzliche Spalten (nur interne Allowlist)
     */
    public function transition(int $shareId, string $fromStatus, string $toStatus, array $set = []): bool
    {
        $allowed = ['requested_at', 'available_at', 'decided_at', 'revoked_at'];
        $cols = 'status = :to, updated_at = :now';
        $params = [':to' => $toStatus, ':now' => Database::now(), ':id' => $shareId, ':from' => $fromStatus];
        foreach ($set as $col => $val) {
            if (!in_array($col, $allowed, true)) {
                throw new \InvalidArgumentException("Spalte nicht erlaubt: $col");
            }
            $cols .= ", $col = :$col";
            $params[":$col"] = $val;
        }
        $stm = $this->db->prepare("UPDATE shares SET $cols WHERE id = :id AND status = :from");
        $stm->execute($params);
        return $stm->rowCount() === 1;
    }

    public function delete(int $storeId, string $shareUid): bool
    {
        $stm = $this->db->prepare('DELETE FROM shares WHERE store_id = ? AND share_uid = ?');
        $stm->execute([$storeId, $shareUid]);
        return $stm->rowCount() === 1;
    }

    public function logEvent(int $shareId, string $event, string $detail = ''): void
    {
        $stm = $this->db->prepare('INSERT INTO share_events (share_id, event, detail, created_at) VALUES (?, ?, ?, ?)');
        $stm->execute([$shareId, $event, $detail, Database::now()]);
    }

    public function eventsForShare(int $shareId): array
    {
        $stm = $this->db->prepare('SELECT event, detail, created_at FROM share_events WHERE share_id = ? ORDER BY id');
        $stm->execute([$shareId]);
        return $stm->fetchAll();
    }
}
