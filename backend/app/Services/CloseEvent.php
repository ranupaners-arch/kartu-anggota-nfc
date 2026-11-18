<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\ExcuseRequest;
use Illuminate\Support\Facades\DB;

class CloseEvent
{
    /** Tutup kegiatan dan tandai Alpa/Izin/Sakit otomatis untuk yang tidak scan, per sesi. */
    public function handle(Event $event): int
    {
        return DB::transaction(function () use ($event) {
            $created = 0;

            if ($event->is_mandatory) {
                $excuses = ExcuseRequest::where('event_id', $event->id)->where('status', 'approved')->pluck('type', 'member_id');
                $targets = $event->targetMembers()->pluck('id');
                // Per sesi. Sesi yang check-in-nya belum dibuka saat ditutup dianggap tidak terlaksana: tidak ditandai.
                $held = $event->sessions()->orderBy('id')->get()->filter(fn ($s) => $s->checkin_opens_at->lte(now()));

                foreach ($held as $session) {
                    $present = Attendance::where('session_id', $session->id)->pluck('member_id');
                    foreach ($targets->diff($present) as $memberId) {
                        Attendance::create([
                            'event_id' => $event->id, 'session_id' => $session->id, 'member_id' => $memberId,
                            'status' => $excuses[$memberId] ?? Attendance::ALPA, 'source' => 'manual',
                        ]);
                        $created++;
                    }
                }
            }

            $event->update(['closed_at' => now()]);
            AuditLogger::log('event.closed', 'event', $event->id, ['auto_rows' => $created], auth()->id());

            return $created;
        });
    }
}
