<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ProfileChange;
use App\Services\ProfileChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/** Super Admin meninjau pengajuan perubahan data diri anggota (nama, no. HP, foto). */
class ProfileChangeController extends Controller
{
    public function index()
    {
        $items = ProfileChange::with('member:id,member_no,name,phone,photo_path', 'reviewer:id,name')
            ->orderByRaw("status = 'pending' desc")->latest()->paginate(30);

        return Inertia::render('profile-changes/index', [
            'items' => $items->through(fn (ProfileChange $c) => $c->only('id', 'name', 'phone', 'reason', 'status', 'review_note', 'created_at', 'reviewed_at') + [
                'photo_url' => $c->photo_path ? route('profile-changes.photo', $c) : null,
                'reviewer' => $c->reviewer?->name,
                'member' => $c->member ? [
                    'id' => $c->member->id, 'member_no' => $c->member->member_no, 'name' => $c->member->name, 'phone' => $c->member->phone,
                    'photo_url' => $c->member->photo_path ? route('members.photo', $c->member) : null,
                ] : null,
            ]),
        ]);
    }

    public function photo(ProfileChange $change)
    {
        abort_unless($change->photo_path && Storage::disk('local')->exists($change->photo_path), 404);

        return Storage::disk('local')->response($change->photo_path, headers: ['Cache-Control' => 'private, max-age=600']);
    }

    public function review(Request $r, ProfileChange $change, ProfileChangeService $changes)
    {
        $d = $r->validate(
            ['action' => 'required|in:approve,reject', 'note' => 'nullable|required_if:action,reject|string|max:500'],
            ['note.required_if' => 'Tulis alasan penolakan agar anggota tahu apa yang perlu diperbaiki.'],
        );
        $changes->review($r->user(), $change, $d['action'] === 'approve', $d['note'] ?? null);

        return back()->with('status', $d['action'] === 'approve' ? 'Perubahan data disetujui dan diterapkan.' : 'Perubahan data ditolak.');
    }
}
