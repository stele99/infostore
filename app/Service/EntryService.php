<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Http\ApiError;
use App\Repository\EntryRepository;

/**
 * Use-Cases für Einträge. Die store_id kommt immer aus der Session
 * (Aufrufer), nie aus dem Request. Fremde UIDs antworten 404, damit kein
 * Existenz-Orakel entsteht.
 */
final class EntryService
{
    public function __construct(private readonly EntryRepository $entries)
    {
    }

    public function list(int $storeId, int $limit, int $offset): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, min($offset, 100_000));
        return [
            'entries' => $this->entries->listForStore($storeId, $limit, $offset),
            'total'   => $this->entries->countForStore($storeId),
        ];
    }

    public function get(int $storeId, string $entryUid): array
    {
        $row = $this->entries->findForStore($storeId, $entryUid);
        if ($row === null) {
            throw ApiError::notFound('Eintrag nicht gefunden.');
        }
        return $row;
    }

    /**
     * Upsert mit atomarer Eigentumsprüfung und optionaler Konflikterkennung
     * (expected_updated_at vom Client = Stand beim Laden).
     */
    public function save(int $storeId, string $entryUid, int $cryptoVersion, array $fields, ?string $expectedUpdatedAt): array
    {
        $owner = $this->entries->ownerOf($entryUid);
        if ($owner !== null && $owner !== $storeId) {
            throw ApiError::notFound('Eintrag nicht gefunden.');
        }

        if ($owner === null) {
            $max = (int) Config::get('max_entries_per_store');
            if ($this->entries->countForStore($storeId) >= $max) {
                throw ApiError::conflict("Limit von $max Einträgen erreicht.");
            }
            $this->entries->insert($storeId, $entryUid, $cryptoVersion, $fields);
        } else {
            if ($expectedUpdatedAt !== null) {
                $current = $this->entries->findForStore($storeId, $entryUid);
                if ($current !== null && $current['updated_at'] !== $expectedUpdatedAt) {
                    throw ApiError::conflict('Der Eintrag wurde zwischenzeitlich geändert.');
                }
            }
            if (!$this->entries->update($storeId, $entryUid, $cryptoVersion, $fields)) {
                throw ApiError::notFound('Eintrag nicht gefunden.');
            }
        }
        $saved = $this->entries->findForStore($storeId, $entryUid);
        return ['entry_uid' => $entryUid, 'updated_at' => $saved['updated_at'] ?? null];
    }

    public function delete(int $storeId, string $entryUid): void
    {
        if (!$this->entries->delete($storeId, $entryUid)) {
            throw ApiError::notFound('Eintrag nicht gefunden.');
        }
    }
}
