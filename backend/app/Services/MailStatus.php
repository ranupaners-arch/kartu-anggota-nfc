<?php

namespace App\Services;

/** Apakah server ini bisa mengirim email sungguhan (bukan driver log/array bawaan). */
class MailStatus
{
    public static function enabled(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null, ''], true);
    }

    public static function mailer(): string
    {
        return (string) config('mail.default');
    }
}
