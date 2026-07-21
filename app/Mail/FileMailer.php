<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;

/**
 * Default-Transport: schreibt Mails als .eml-Dateien nach var/mail.
 * Für echten Versand INFOSTORE_MAIL=native setzen (PHP mail()/Sendmail).
 */
final class FileMailer implements Mailer
{
    public function send(string $to, string $subject, string $body): bool
    {
        $dir = Config::get('mail_dir');
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $from = Config::get('mail_from');
        $content = "From: $from\r\nTo: $to\r\nSubject: $subject\r\nDate: " . gmdate('r') . "\r\n\r\n$body\r\n";
        $file = $dir . '/' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.eml';
        return file_put_contents($file, $content, LOCK_EX) !== false;
    }
}
