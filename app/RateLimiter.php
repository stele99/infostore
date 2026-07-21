<?php

declare(strict_types=1);

namespace App;

use App\Http\ApiError;
use PDO;

/**
 * Einfaches Fenster-Rate-Limit auf Datenbankbasis.
 * Subjekte werden gehasht gespeichert (keine Klartext-IPs/Namen im Log).
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $db)
    {
    }

    private function subjectHash(string $subject): string
    {
        return hash_hmac('sha256', $subject, Config::appSecret());
    }

    /** Wirft 429, wenn das Limit im Fenster erreicht ist; zaehlt den Versuch. */
    public function hit(string $kind, string $subject, ?int $max = null, ?int $windowSeconds = null): void
    {
        $max = $max ?? (int) Config::get('login_max_attempts');
        $window = $windowSeconds ?? (int) Config::get('login_window_seconds');
        $subject = $this->subjectHash($subject);
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $window);

        $stm = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE kind = ? AND subject = ? AND created_at > ?'
        );
        $stm->execute([$kind, $subject, $cutoff]);
        if ((int) $stm->fetchColumn() >= $max) {
            throw ApiError::tooMany();
        }

        $stm = $this->db->prepare('INSERT INTO login_attempts (kind, subject, created_at) VALUES (?, ?, ?)');
        $stm->execute([$kind, $subject, Database::now()]);

        // Gelegentliches Aufraeumen alter Eintraege
        if (random_int(0, 50) === 0) {
            $stm = $this->db->prepare('DELETE FROM login_attempts WHERE created_at < ?');
            $stm->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
        }
    }

    /** Erfolgsfall: Fehlversuche fuer das Subjekt zuruecksetzen. */
    public function clear(string $kind, string $subject): void
    {
        $stm = $this->db->prepare('DELETE FROM login_attempts WHERE kind = ? AND subject = ?');
        $stm->execute([$kind, $this->subjectHash($subject)]);
    }
}
