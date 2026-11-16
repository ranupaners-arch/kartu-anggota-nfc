<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\ExcuseRequest;
use Illuminate\Support\Facades\DB;

class CloseEvent
{
    /** Tutup kegiatan dan tandai Alpa/Izin/Sakit otomatis untuk yang tidak scan. */
    public function handle(Event $event): int
    {
        return DB::transaction(function () use ($event) {
            $session = $event->defaultSession();
            $created = 0;

            if ($event->is_mandatory) {
                $present = Attendance::where('session_id', $session->id)->pluck('member_id');
                $excuses = ExcuseRequest::where('event_id', $event->id)->where('status', 'approved')
                    ->pluck('type', 'member_id');

                $event->targetMembers()->whereNotIn('id', $present)->get()
                    ->each(function ($member) use ($event, $session, $excuses, &$created) {
                        Attendance::create([
                            'event_id' => $event->id, 'session_id' => $session->id, 'member_id' => $member->id,
                            'status' => $excuses[$member->id] ?? Attendance::ALPA, 'source' => 'manual',
                        ]);
                        $created++;
                    });
            }

            $event->update(['closed_at' => now()]);
            AuditLogger::log('event.closed', 'event', $event->id, ['auto_rows' => $created], auth()->id());

            return $created;
        });
    }
}
