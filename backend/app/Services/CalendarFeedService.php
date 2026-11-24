<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Langganan jadwal (iCalendar, RFC 5545). Tiap akun punya tautan rahasia; aplikasi kalender
 * (Google Calendar, kalender HP) mengambil ulang tautan itu berkala. Anggota: kegiatan yang
 * menargetkannya; pengurus: kegiatan yang boleh dilihatnya. Periode berjalan, mulai 30 hari lalu.
 */
class CalendarFeedService
{
    public function __construct(private EventService $events) {}

    /** URL langganan akun ini; token dibuat bila belum ada, atau diganti bila $reset. */
    public function url(User $user, bool $reset = false): string
    {
        if ($reset || ! $user->calendar_token) {
            $user->forceFill(['calendar_token' => Str::random(48)])->save();
        }

        return route('calendar.feed', ['token' => $user->calendar_token]);
    }

    public function userForToken(string $token): ?User
    {
        return User::where('calendar_token', $token)->whereNull('disabled_at')->first();
    }

    /** Kegiatan untuk akun ini. Dipanggil tanpa sesi login, jadi organisasi dibatasi eksplisit. */
    public function eventsFor(User $user): Collection
    {
        $term = Term::withoutGlobalScopes()->where('organization_id', $user->organization_id)->where('is_current', true)->first();
        if (! $term) {
            return collect();
        }
        $q = ($user->role === User::MEMBER ? Event::query() : $this->events->visibleTo($user))
            ->withoutGlobalScopes()->where('organization_id', $user->organization_id)
            ->where('term_id', $term->id)->where('ends_at', '>=', now()->subDays(30))->orderBy('starts_at');
        if ($user->role === User::MEMBER) {
            if (! $user->member_id) {
                return collect();
            }

            return $q->get()->filter(fn (Event $e) => $e->targetMembers()->withoutGlobalScopes()->whereKey($user->member_id)->exists())->values();
        }

        return $q->get();
    }

    public function ics(User $user, Collection $events): string
    {
        $org = $user->organization()->withoutGlobalScopes()->value('name') ?? 'Kartu Anggota';
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'kartu-anggota';
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Kartu Anggota//Jadwal//ID', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->text("Kegiatan $org"), 'X-WR-TIMEZONE:Asia/Jakarta',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H',
        ];
        $stamp = $this->utc(now());
        foreach ($events as $e) {
            $desc = array_filter([
                $e->is_mandatory ? 'Kegiatan wajib.' : 'Kegiatan tidak wajib.',
                'Check-in '.$e->checkin_opens_at->timezone('Asia/Jakarta')->format('H.i').'–'.$e->checkin_closes_at->timezone('Asia/Jakarta')->format('H.i')
                    .', telat setelah '.$e->late_after->timezone('Asia/Jakarta')->format('H.i').' WIB.',
                $e->closed_at ? 'Kegiatan sudah ditutup.' : null,
            ]);
            $lines = [...$lines,
                'BEGIN:VEVENT',
                "UID:event-{$e->id}@{$host}",
                "DTSTAMP:$stamp",
                'DTSTART:'.$this->utc($e->starts_at),
                'DTEND:'.$this->utc($e->ends_at),
                'SUMMARY:'.$this->text($e->title),
                ...($e->location ? ['LOCATION:'.$this->text($e->location)] : []),
                'DESCRIPTION:'.$this->text(implode("\n", $desc)),
                'END:VEVENT',
            ];
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function utc(Carbon $t): string
    {
        return $t->copy()->utc()->format('Ymd\THis\Z');
    }

    /** Escape nilai TEXT: \ ; , dan baris baru. */
    private function text(string $v): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $v);
    }

    /** Lipat baris > 75 oktet (lanjutan diawali spasi), tanpa memotong karakter UTF-8. */
    private function fold(string $line): string
    {
        $out = '';
        $len = 0;
        foreach (mb_str_split($line) as $ch) {
            $b = strlen($ch);
            if ($len + $b > 75) {
                $out .= "\r\n ";
                $len = 1;
            }
            $out .= $ch;
            $len += $b;
        }

        return $out;
    }
}
