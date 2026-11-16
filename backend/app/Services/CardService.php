<?php

namespace App\Services;

use App\Models\Card;

class CardService
{
    /** Blokir kartu (status blocked atau lost). Berlaku seketika untuk scan online. */
    public function block(Card $card, string $status = Card::BLOCKED): Card
    {
        abort_unless(in_array($status, [Card::BLOCKED, Card::LOST], true), 422, 'Status tidak valid.');
        $card->update(['status' => $status, 'revoked_at' => now()]);
        AuditLogger::log('card.blocked', 'card', $card->id, ['status' => $status]);

        return $card->fresh();
    }
}
