<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Member;
use App\Services\AuditLogger;
use App\Support\CardPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CardController extends Controller
{
    /** Langkah 1: buat kartu `unassigned` + payload untuk ditulis ke NTAG. */
    public function init()
    {
        $card = Card::create([
            'public_id' => (string) Str::ulid(), 'key_id' => config('attendance.card_active_key'),
            'status' => Card::UNASSIGNED,
        ]);

        return response()->json([
            'id' => $card->id, 'public_id' => $card->public_id, 'payload' => CardPayload::make($card),
            'ntag' => CardPayload::ntagPassword($card), // untuk proteksi tulis di kartu
        ], 201);
    }

    /** Langkah 2: dipanggil setelah penulisan ke kartu BERHASIL. Kartu lama anggota diblokir. */
    public function assign(Request $r, Card $card)
    {
        $d = $r->validate(['member_id' => 'required|integer', 'hw_uid' => 'nullable|string|max:64']);
        $member = Member::findOrFail($d['member_id']); // ter-scope ke organisasi
        abort_unless($card->status === Card::UNASSIGNED, 409, 'Kartu sudah dipakai.');

        DB::transaction(function () use ($card, $d, $member) {
            Card::where('member_id', $member->id)->where('status', Card::ACTIVE)
                ->update(['status' => Card::BLOCKED, 'revoked_at' => now()]);
            $card->update([
                'member_id' => $member->id, 'status' => Card::ACTIVE, 'issued_at' => now(),
                'hw_uid_hash' => isset($d['hw_uid']) ? hash('sha256', strtoupper($d['hw_uid'])) : null,
            ]);
            AuditLogger::log('card.issued', 'card', $card->id, ['member_id' => $member->id]);
        });

        return $card->fresh();
    }

    public function block(Request $r, Card $card)
    {
        $d = $r->validate(['status' => 'sometimes|in:blocked,lost']);
        $card->update(['status' => $d['status'] ?? Card::BLOCKED, 'revoked_at' => now()]);
        AuditLogger::log('card.blocked', 'card', $card->id, ['status' => $card->status]);

        return $card->fresh();
    }
}
