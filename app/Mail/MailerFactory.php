<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;

final class MailerFactory
{
    public static function create(): Mailer
    {
        return match (Config::get('mail_transport')) {
            'smtp' => new SmtpMailer(
                host: (string) Config::get('smtp_host'),
                port: (int) Config::get('smtp_port'),
                encryption: (string) Config::get('smtp_encryption'),
                username: (string) Config::get('smtp_username'),
                password: (string) Config::get('smtp_password'),
                fromAddress: (string) Config::get('mail_from'),
                fromName: (string) Config::get('mail_from_name'),
                timeout: (int) Config::get('smtp_timeout'),
            ),
            'native' => new NativeMailer(),
            default => new FileMailer(),
        };
    }
}
