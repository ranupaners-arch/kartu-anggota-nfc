<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventSurvey;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Survei kepuasan setelah kegiatan ditutup, hanya untuk yang hadir/telat, sekali isi, anonim. Hasil baru tampil
 * setelah survei selesai dan minimal MIN_RESPONSES jawaban; karena itu survei tidak bisa dibuka lagi.
 */
class SurveyService
{
    public const MIN_RESPONSES = 3;

    public const MAX_QUESTIONS = 5;

    public const MAX_DAYS = 30;

    public function __construct(private EventService $events) {}

    /** @param list<string> $questions */
    public function open(User $by, Event $event, array $questions, int $days): EventSurvey
    {
        $this->events->authorize($by, $event);

        return DB::transaction(function () use ($by, $event, $questions, $days) {
            $event = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (! $event->closed_at) {
                throw ValidationException::withMessages(['questions' => 'Tutup kegiatan dulu, supaya daftar hadirnya sudah final.']);
            }
            if (EventSurvey::where('event_id', $event->id)->exists()) {
                throw ValidationException::withMessages(['questions' => 'Survei kegiatan ini sudah pernah dibuka.']);
            }
            if ($this->eligible($event) < self::MIN_RESPONSES) {
                throw ValidationException::withMessages(['questions' => 'Peserta hadir kurang dari '.self::MIN_RESPONSES.' orang, jadi jawabannya tidak bisa anonim.']);
            }
            $s = EventSurvey::create(['event_id' => $event->id, 'questions' => array_values($questions), 'closes_at' => now()->addDays($days), 'opened_by' => $by->id]);
            AuditLogger::log('survey.opened', 'event', $event->id, ['survey_id' => $s->id, 'days' => $days], $by->id);

            return $s;
        });
    }

    public function close(User $by, EventSurvey $survey): void
    {
        $this->events->authorize($by, $survey->event);
        DB::transaction(function () use ($by, $survey) {
            $s = EventSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->isOpen(), 422, 'Survei sudah selesai.');
            $s->update(['closed_at' => now()]);
            AuditLogger::log('survey.closed', 'event', $s->event_id, ['survey_id' => $s->id], $by->id);
        });
    }

    /** @param list<int> $answers */
    public function respond(EventSurvey $survey, int $memberId, int $rating, array $answers, ?string $comment): void
    {
        abort_unless($this->attended($survey->event_id, $memberId), 403, 'Survei ini untuk peserta yang hadir.');
        DB::transaction(function () use ($survey, $memberId, $rating, $answers, $comment) {
            $s = EventSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->isOpen(), 422, 'Survei sudah ditutup.');
            if (count($answers) !== count($s->questions)) {
                throw ValidationException::withMessages(['answers' => 'Jawab semua pertanyaan.']);
            }
            $new = DB::table('event_survey_respondents')->insertOrIgnore(['survey_id' => $s->id, 'member_id' => $memberId]);
            abort_unless($new, 422, 'Anda sudah mengisi survei ini.');
            DB::table('event_survey_responses')->insert(['id' => (string) Str::uuid(), 'survey_id' => $s->id, 'rating' => $rating, 'answers' => json_encode(array_values($answers)), 'comment' => $comment]);
            // Sengaja tanpa AuditLogger: log bisa menghubungkan jawaban dengan anggota.
        });
    }

    /** Survei terbuka yang belum diisi anggota. */
    public function pendingFor(int $memberId): Collection
    {
        return EventSurvey::open()->with('event:id,title,starts_at')
            ->whereIn('event_id', $this->presence()->where('member_id', $memberId)->select('event_id'))
            ->whereNotIn('id', DB::table('event_survey_respondents')->where('member_id', $memberId)->select('survey_id'))
            ->orderBy('closes_at')->get();
    }

    /** Tab di halaman kegiatan. */
    public function tab(Event $event): array
    {
        $s = EventSurvey::where('event_id', $event->id)->first();
        $n = $s ? DB::table('event_survey_responses')->where('survey_id', $s->id)->count() : 0;

        return [
            'eligible' => $this->eligible($event), 'min' => self::MIN_RESPONSES, 'max_questions' => self::MAX_QUESTIONS, 'max_days' => self::MAX_DAYS,
            'survey' => $s ? ['id' => $s->id, 'open' => $s->isOpen(), 'closes_at' => $s->closes_at, 'closed_at' => $s->closed_at ?? ($s->isOpen() ? null : $s->closes_at), 'questions' => $s->questions, 'responses' => $n] : null,
            'result' => $s && ! $s->isOpen() && $n >= self::MIN_RESPONSES ? $this->result($s) : null,
        ];
    }

    private function result(EventSurvey $s): array
    {
        $rows = DB::table('event_survey_responses')->where('survey_id', $s->id)->orderBy('id')->get(); // uuid acak
        $answers = $rows->map(fn ($r) => json_decode($r->answers, true));
        $stat = fn (string $label, Collection $v) => ['label' => $label, 'average' => round($v->avg(), 2), 'counts' => collect(range(1, 5))->map(fn ($i) => $v->filter(fn ($x) => (int) $x === $i)->count())->all()];

        return [
            'items' => collect([$stat('Kepuasan keseluruhan', $rows->pluck('rating'))])->merge(collect($s->questions)->map(fn ($q, $i) => $stat($q, $answers->map(fn ($a) => $a[$i]))))->values(),
            'comments' => $rows->pluck('comment')->filter()->values(),
        ];
    }

    private function eligible(Event $event): int
    {
        return $this->presence()->where('event_id', $event->id)->distinct()->count('member_id');
    }

    private function attended(int $eventId, int $memberId): bool
    {
        return $this->presence()->where('event_id', $eventId)->where('member_id', $memberId)->exists();
    }

    private function presence()
    {
        return Attendance::whereNull('voided_at')->whereIn('status', ['hadir', 'telat']);
    }

    public static function dueText(Carbon $t): string
    {
        return $t->timezone(config('app.timezone'))->locale('id')->translatedFormat('j F Y H.i');
    }
}
