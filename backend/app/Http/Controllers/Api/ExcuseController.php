<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Services\AuditLogger;
use App\Services\ExcuseService;
use Illuminate\Http\Request;

class ExcuseController extends Controller
{
    public function store(Request $r)
    {
        $d = $r->validate([
            'event_id' => 'required|integer', 'type' => 'required|in:izin,sakit',
            'reason' => 'required|string|max:1000', 'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'member_id' => 'sometimes|integer',
        ]);
        $user = $r->user();
        $event = Event::findOrFail($d['event_id']);

        // Anggota hanya untuk dirinya; admin boleh atas nama anggota (retroaktif setelah ditutup).
        $memberId = $user->isAdmin() && isset($d['member_id'])
            ? Member::findOrFail($d['member_id'])->id // ter-scope ke organisasi
            : $user->member_id;
        abort_unless($memberId, 422, 'Akun belum terhubung ke anggota.');
        abort_if($event->closed_at && ! $user->isAdmin(), 422, 'Kegiatan sudah ditutup; hubungi admin.');

        $excuse = ExcuseRequest::updateOrCreate(
            ['event_id' => $event->id, 'member_id' => $memberId],
            ['type' => $d['type'], 'reason' => $d['reason'], 'status' => 'pending',
                'attachment_path' => $r->hasFile('attachment') ? $r->file('attachment')->store('excuses') : null],
        );
        if ($event->closed_at) {
            AuditLogger::log('excuse.retroactive', 'excuse', $excuse->id, [], $user->id);
        }

        return response()->json($excuse, 201);
    }

    public function review(Request $r, ExcuseRequest $excuse)
    {
        $d = $r->validate(['status' => 'required|in:approved,rejected', 'note' => 'nullable|string|max:500']);

        return app(ExcuseService::class)->review($r->user(), $excuse, $d['status'], $d['note'] ?? null);
    }
}
