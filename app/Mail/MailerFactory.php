<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;

final class MailerFactory
{
    public static function create(): Mailer
    {
        return Config::get('mail_transport') === 'native' ? new NativeMailer() : new FileMailer();
    }
}
