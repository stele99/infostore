<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Schlanker SMTP-Client ueber PHP-Streams (kein Composer-Paket, passend zum
 * abhaengigkeitsfreien Ansatz dieser App). Unterstuetzt implizites TLS
 * (i. d. R. Port 465) und STARTTLS (i. d. R. Port 587) sowie AUTH LOGIN.
 *
 * send() wirft nie: Fehler werden geloggt und als false zurueckgegeben,
 * damit ein falsch konfiguriertes Postfach nicht die gesamte Anfrage
 * (z. B. eine unbeteiligte Notfallzugriffs-Anfrage) zum Scheitern bringt.
 * ShareService protokolliert einen Fehlschlag bereits als 'owner_notify_failed'.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // 'tls' (implizit) | 'starttls' | 'none'
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName = '',
        private readonly int $timeout = 10,
    ) {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->host === '') {
            error_log('SmtpMailer: smtp_host ist nicht konfiguriert.');
            return false;
        }
        try {
            $this->deliver($to, $subject, $body);
            return true;
        } catch (\Throwable $e) {
            error_log('SmtpMailer: ' . $e->getMessage());
            return false;
        }
    }

    private function deliver(string $to, string $subject, string $body): void
    {
        $transport = $this->encryption === 'tls' ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($sock === false) {
            throw new RuntimeException("SMTP-Verbindung zu {$this->host}:{$this->port} fehlgeschlagen: $errstr ($errno)");
        }
        stream_set_timeout($sock, $this->timeout);

        try {
            $this->readResponse($sock, 220);
            $this->command($sock, 'EHLO ' . $this->heloName(), 250);

            if ($this->encryption === 'starttls') {
                $this->command($sock, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS-Upgrade fehlgeschlagen.');
                }
                // Nach STARTTLS erneut EHLO senden (RFC 3207).
                $this->command($sock, 'EHLO ' . $this->heloName(), 250);
            }

            if ($this->username !== '') {
                $this->command($sock, 'AUTH LOGIN', 334);
                $this->command($sock, base64_encode($this->username), 334);
                $this->command($sock, base64_encode($this->password), 235);
            }

            $this->command($sock, 'MAIL FROM:<' . $this->fromAddress . '>', 250);
            $this->command($sock, 'RCPT TO:<' . $to . '>', 250);
            $this->command($sock, 'DATA', 354);

            $payload = $this->buildHeaders($to, $subject) . "\r\n" . chunk_split(base64_encode($body)) . ".\r\n";
            $this->write($sock, $payload);
            $this->readResponse($sock, 250);

            $this->write($sock, "QUIT\r\n");
        } finally {
            fclose($sock);
        }
    }

    private function heloName(): string
    {
        $at = strpos($this->fromAddress, '@');
        return $at !== false ? substr($this->fromAddress, $at + 1) : 'localhost';
    }

    private function buildHeaders(string $to, string $subject): string
    {
        $from = $this->fromName !== ''
            ? mb_encode_mimeheader($this->fromName) . " <{$this->fromAddress}>"
            : $this->fromAddress;
        $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $this->heloName());

        $lines = [
            'Message-ID: ' . $messageId,
            'Date: ' . date('r'),
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . mb_encode_mimeheader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return implode("\r\n", $lines) . "\r\n";
    }

    /** Sendet einen Befehl und prüft den erwarteten Antwortcode. */
    private function command($sock, string $line, int $expectedCode): string
    {
        $this->write($sock, $line . "\r\n");
        return $this->readResponse($sock, $expectedCode);
    }

    private function write($sock, string $data): void
    {
        if (@fwrite($sock, $data) === false) {
            throw new RuntimeException('SMTP: Schreiben auf den Socket fehlgeschlagen.');
        }
    }

    /** Liest eine (ggf. mehrzeilige) SMTP-Antwort und prüft den Code. */
    private function readResponse($sock, int $expectedCode): string
    {
        $full = '';
        do {
            $line = fgets($sock, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($sock);
                $reason = $meta['timed_out'] ? 'Zeitüberschreitung' : 'Verbindung geschlossen';
                throw new RuntimeException("SMTP: keine Antwort ($reason).");
            }
            $full .= $line;
            $continues = strlen($line) >= 4 && $line[3] === '-';
        } while ($continues);

        $code = (int) substr($full, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("SMTP-Fehler: erwartet $expectedCode, erhalten: " . trim($full));
        }
        return $full;
    }
}
