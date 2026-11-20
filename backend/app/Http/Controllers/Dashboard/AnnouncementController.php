<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Division;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Pengumuman untuk anggota. Admin Divisi: hanya untuk divisinya (dan mengelola miliknya). */
class AnnouncementController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $items = Announcement::with(['division:id,name', 'author:id,name'])
            ->when($u->role === User::DIVISION_ADMIN, fn ($q) => $q->forDivision($u->division_id))
            ->orderByDesc('pinned')->latest()->paginate(30);

        return Inertia::render('announcements/index', [
            'items' => $items->through(fn (Announcement $a) => $a->only('id', 'title', 'body', 'pinned', 'expires_at', 'created_at', 'division_id')
                + ['division' => $a->division?->name, 'author' => $a->author?->name, 'expired' => $a->expires_at?->isPast() ?? false, 'canEdit' => $this->canManage($u, $a)]),
            'divisions' => $u->isSuperAdmin() ? Division::orderBy('name')->get(['id', 'name']) : Division::whereKey($u->division_id)->get(['id', 'name']),
            'canTargetAll' => $u->isSuperAdmin(),
        ]);
    }

    public function store(Request $r)
    {
        $a = Announcement::create($this->input($r) + ['created_by' => $r->user()->id]);
        AuditLogger::log('announcement.created', 'announcement', $a->id);
        // Notifikasi push ke HP anggota (portal web) — di antrean agar tidak memperlambat halaman.
        $id = $a->id;
        dispatch(fn () => app(\App\Services\Push\PushService::class)->announcement(\App\Models\Announcement::withoutGlobalScopes()->findOrFail($id)));

        return back()->with('status', 'Pengumuman diterbitkan.');
    }

    public function update(Request $r, Announcement $announcement)
    {
        abort_unless($this->canManage($r->user(), $announcement), 403);
        $announcement->update($this->input($r));
        AuditLogger::log('announcement.updated', 'announcement', $announcement->id);

        return back()->with('status', 'Pengumuman diperbarui.');
    }

    public function destroy(Request $r, Announcement $announcement)
    {
        abort_unless($this->canManage($r->user(), $announcement), 403);
        $announcement->delete();
        AuditLogger::log('announcement.deleted', 'announcement', $announcement->id);

        return back()->with('status', 'Pengumuman dihapus.');
    }

    private function input(Request $r): array
    {
        $u = $r->user();
        $d = $r->validate([
            'title' => 'required|string|max:150', 'body' => 'required|string|max:5000',
            'division_id' => 'nullable|integer', 'pinned' => 'boolean', 'expires_at' => 'nullable|date|after:now',
        ]);
        if ($u->role === User::DIVISION_ADMIN) {
            $d['division_id'] = $u->division_id; // Admin Divisi hanya untuk divisinya
        } elseif (! empty($d['division_id'])) {
            Division::findOrFail($d['division_id']);
        }

        return ['division_id' => ($d['division_id'] ?? null) ?: null, 'pinned' => (bool) ($d['pinned'] ?? false), 'expires_at' => $d['expires_at'] ?? null] + $d;
    }

    private function canManage(User $u, Announcement $a): bool
    {
        return $u->isSuperAdmin() || ($u->role === User::DIVISION_ADMIN && $a->division_id === $u->division_id);
    }
}
