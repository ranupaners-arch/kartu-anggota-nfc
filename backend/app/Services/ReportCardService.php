<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Rapor kehadiran satu anggota untuk satu periode (PDF), untuk dashboard & app anggota. */
class ReportCardService
{
    public const STATUSES = ['hadir' => 'Hadir', 'telat' => 'Telat', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa'];

    public function data(Member $member, Term $term): array
    {
        abort_if($member->anonymized_at, 422, 'Data pribadi anggota ini sudah dihapus.');
        $membership = Membership::with('division:id,name')->where('member_id', $member->id)->where('term_id', $term->id)->first();
        $rows = Attendance::query()->whereNull('attendances.voided_at')->where('attendances.member_id', $member->id)
            ->join('events', 'events.id', '=', 'attendances.event_id')->where('events.term_id', $term->id)
            ->join('event_sessions', 'event_sessions.id', '=', 'attendances.session_id')
            ->orderBy('events.starts_at')->orderBy('attendances.session_id')
            ->get(['events.title', 'events.starts_at', 'event_sessions.title as session_title', 'attendances.status', 'attendances.scanned_at']);
        $multi = $rows->groupBy('title')->map->count();

        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($rows as $r) {
            $counts[$r->status] = ($counts[$r->status] ?? 0) + 1;
        }
        $total = array_sum($counts);

        return [
            'org' => Organization::find($member->organization_id),
            'term' => $term, 'member' => $member, 'division' => $membership?->division?->name, 'membershipStatus' => $membership?->status,
            'counts' => $counts, 'total' => $total,
            'rate' => $total ? round(($counts['hadir'] + $counts['telat']) / $total * 100, 1) : null,
            'rows' => $rows->map(fn ($r) => [
                'event' => $r->title.(($multi[$r->title] ?? 1) > 1 && $r->session_title !== $r->title ? " — {$r->session_title}" : ''),
                'date' => Carbon::parse($r->starts_at)->timezone(config('app.timezone')),
                'status' => self::STATUSES[$r->status] ?? $r->status,
                'time' => $r->scanned_at ? Carbon::parse($r->scanned_at)->timezone(config('app.timezone'))->format('H.i') : '—',
            ])->all(),
            'statusLabels' => self::STATUSES,
        ];
    }

    /** @return array{0: string, 1: string} [isi PDF, nama berkas] */
    public function pdf(Member $member, Term $term): array
    {
        $data = $this->data($member, $term);
        $name = 'rapor-'.Str::slug($member->member_no.'-'.$term->name).'.pdf';

        return [Pdf::loadView('exports.rapor', $data)->setPaper('a4')->output(), $name];
    }
}
