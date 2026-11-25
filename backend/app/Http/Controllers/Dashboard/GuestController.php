<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventGuest;
use App\Services\EventService;
use App\Services\GuestService;
use Illuminate\Http\Request;

class GuestController extends Controller
{
    public function __construct(private GuestService $guests) {}

    public function store(Request $r, Event $event)
    {
        $d = $r->validate(['lines' => 'required|string|max:60000']);
        $n = $this->guests->add($r->user(), $event, $d['lines']);

        return back()->with('status', "$n tamu ditambahkan.");
    }

    public function update(Request $r, EventGuest $guest)
    {
        $d = $r->validate(['revoked' => 'required|boolean']);
        $this->guests->setRevoked($r->user(), $guest, (bool) $d['revoked']);

        return back()->with('status', $d['revoked'] ? 'QR tamu dibatalkan.' : 'QR tamu berlaku lagi.');
    }

    public function destroy(Request $r, EventGuest $guest)
    {
        $this->guests->delete($r->user(), $guest);

        return back()->with('status', 'Tamu dihapus.');
    }

    public function export(Request $r, Event $event, EventService $events)
    {
        $events->authorize($r->user(), $event);
        $safe = fn (?string $v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'$v" : (string) $v;
        $items = $this->guests->tab($event)['items'];

        return response()->streamDownload(function () use ($items, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Nama', 'Instansi', 'QR', 'Sesi', 'Status', 'Waktu'], escape: '');
            foreach ($items as $g) {
                foreach (count($g['checkins']) ? $g['checkins'] : [null] as $c) {
                    fputcsv($out, [$safe($g['name']), $safe($g['institution']), $g['revoked_at'] ? 'Dibatalkan' : 'Berlaku', $safe($c['session'] ?? ''), $c ? ucfirst($c['status']) : 'Belum hadir',
                        $c ? $c['at']->timezone(config('app.timezone'))->format('Y-m-d H:i') : ''], escape: '');
                }
            }
            fclose($out);
        }, "tamu-kegiatan-{$event->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
