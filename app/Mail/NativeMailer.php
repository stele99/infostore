<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;

final class NativeMailer implements Mailer
{
    public function send(string $to, string $subject, string $body): bool
    {
        $from = Config::get('mail_from');
        $headers = "From: $from\r\nContent-Type: text/plain; charset=utf-8";
        return mail($to, $subject, $body, $headers);
    }
}
