<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\Event;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sertifikat kegiatan. Diterbitkan setelah kegiatan ditutup (absensi final) untuk yang Hadir/Telat. Menerbitkan
 * ulang menyesuaikan dengan koreksi absensi: menambah yang baru memenuhi syarat dan mencabut yang tidak lagi.
 */
class CertificateService
{
    public const AUTO_REASON = 'Tidak lagi memenuhi syarat kehadiran.';

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; // tanpa huruf yang mirip angka

    public function __construct(private EventService $events) {}

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:60', 'role' => 'required|string|max:60', 'min_sessions' => 'required|integer|min:1|max:100',
            'signer1_name' => 'required|string|max:100', 'signer1_title' => 'required|string|max:100',
            'signer2_name' => 'nullable|string|max:100|required_with:signer2_title', 'signer2_title' => 'nullable|string|max:100|required_with:signer2_name',
        ];
    }

    /** @return array{added: int, revoked: int, restored: int} */
    public function issue(User $by, Event $event, array $d): array
    {
        $this->events->authorize($by, $event);

        return DB::transaction(function () use ($by, $event, $d) {
            $event = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (! $event->closed_at) {
                throw ValidationException::withMessages(['event' => 'Tutup kegiatan dulu; sertifikat diterbitkan setelah absensi final.']);
            }
            if ($d['min_sessions'] > max(1, $event->sessions()->count())) {
                throw ValidationException::withMessages(['min_sessions' => 'Melebihi jumlah sesi kegiatan.']);
            }
            $batch = CertificateBatch::updateOrCreate(['event_id' => $event->id], $d + ['issued_by' => $by->id]);
            $plan = $this->plan($event, $batch->min_sessions);

            $seq = (int) Certificate::where('event_id', $event->id)->max('seq');
            foreach ($plan['add'] as $m) {
                Certificate::create(['event_id' => $event->id, 'member_id' => $m->id, 'seq' => ++$seq, 'code' => $this->newCode(), 'member_name' => $m->name, 'issued_at' => now()]);
            }
            Certificate::whereIn('id', $plan['revoke'])->update(['revoked_at' => now(), 'revoke_reason' => self::AUTO_REASON, 'auto_revoked' => true]);
            Certificate::whereIn('id', $plan['restore'])->update(['revoked_at' => null, 'revoke_reason' => null, 'auto_revoked' => false]);
            $n = ['added' => count($plan['add']), 'revoked' => count($plan['revoke']), 'restored' => count($plan['restore'])];
            AuditLogger::log('certificate.issued', 'event', $event->id, $n, $by->id);

            return $n;
        });
    }

    /** Selisih antara sertifikat yang ada & absensi sekarang. */
    public function plan(Event $event, int $minSessions): array
    {
        $eligible = $this->eligible($event, $minSessions);
        $existing = Certificate::where('event_id', $event->id)->get();

        return [
            'eligible' => $eligible->count(),
            'add' => $eligible->whereNotIn('id', $existing->pluck('member_id'))->values(),
            'revoke' => $existing->whereNull('revoked_at')->whereNotIn('member_id', $eligible->pluck('id'))->pluck('id')->all(),
            'restore' => $existing->where('auto_revoked', true)->whereIn('member_id', $eligible->pluck('id'))->pluck('id')->all(),
        ];
    }

    public function revoke(User $by, Certificate $c, string $reason): void
    {
        $this->events->authorize($by, $c->event);
        abort_if($c->revoked_at && ! $c->auto_revoked, 422, 'Sertifikat ini sudah dicabut.');
        $c->update(['revoked_at' => now(), 'revoke_reason' => $reason, 'auto_revoked' => false]);
        AuditLogger::log('certificate.revoked', 'certificate', $c->id, ['reason' => $reason], $by->id);
    }

    /** Batalkan pencabutan manual; hanya bila masih memenuhi syarat kehadiran. */
    public function restore(User $by, Certificate $c): void
    {
        $this->events->authorize($by, $c->event);
        abort_unless($c->revoked_at, 422, 'Sertifikat ini masih berlaku.');
        abort_if($c->member?->anonymized_at, 422, 'Data pribadi anggota ini sudah dihapus.');
        $batch = CertificateBatch::where('event_id', $c->event_id)->firstOrFail();
        abort_unless($this->eligible($c->event, $batch->min_sessions)->contains('id', $c->member_id), 422, 'Anggota ini tidak memenuhi syarat kehadiran.');
        $c->update(['revoked_at' => null, 'revoke_reason' => null, 'auto_revoked' => false]);
        AuditLogger::log('certificate.restored', 'certificate', $c->id, [], $by->id);
    }

    /** Verifikasi publik: hanya yang tertulis di sertifikatnya. Null = kode tidak dikenal. */
    public function verify(string $code): ?array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($code) !== 16) {
            return null;
        }
        $c = Certificate::withoutGlobalScopes()->where('code', $code)->first();
        $event = $c ? Event::withoutGlobalScopes()->find($c->event_id) : null;
        if (! $event) {
            return null;
        }

        return [
            'valid' => $c->revoked_at === null, 'number' => $this->number($c, $event), 'code' => $c->displayCode(), 'issued_at' => $c->issued_at->toIso8601String(),
            'event' => $event->title, 'date' => $event->starts_at->toIso8601String(), 'organization' => Organization::find($event->organization_id)?->name,
            // Dicabut: nama tidak ditampilkan lagi.
            'name' => $c->revoked_at ? null : $c->member_name,
        ];
    }

    public function number(Certificate $c, Event $event): string
    {
        $slug = Organization::find($event->organization_id)?->slug ?? 'org';

        return sprintf('%03d/%s/%d/%s', $c->seq, Str::upper($slug), $event->id, $event->starts_at->timezone(config('app.timezone'))->format('Y'));
    }

    /** @return array{0: string, 1: string} [isi PDF, nama berkas] */
    public function pdf(Certificate $c): array
    {
        abort_if($c->revoked_at, 410, 'Sertifikat ini sudah dicabut.');
        $event = $c->event()->firstOrFail();
        $batch = CertificateBatch::where('event_id', $event->id)->firstOrFail();
        $url = route('certificates.verify', ['code' => $c->code]);
        $pdf = Pdf::loadView('exports.sertifikat', [
            'c' => $c, 'event' => $event, 'batch' => $batch, 'org' => Organization::find($event->organization_id)?->name,
            'number' => $this->number($c, $event), 'qr' => $this->qr($url), 'url' => $url,
            'date' => $event->starts_at->timezone(config('app.timezone'))->locale('id')->translatedFormat('j F Y'),
            'signers' => array_filter([[$batch->signer1_name, $batch->signer1_title], $batch->signer2_name ? [$batch->signer2_name, $batch->signer2_title] : null]),
        ])->setPaper('a4', 'landscape')->output();

        return [$pdf, 'sertifikat-'.Str::slug($event->title)."-{$c->seq}.pdf"];
    }

    /** Anggota dengan status hadir/telat di minimal N sesi. @return Collection<int, Member> */
    private function eligible(Event $event, int $minSessions): Collection
    {
        $ids = Attendance::where('event_id', $event->id)->whereNull('voided_at')->whereIn('status', [Attendance::HADIR, Attendance::TELAT])
            ->groupBy('member_id')->havingRaw('count(distinct session_id) >= ?', [$minSessions])->pluck('member_id');

        return Member::whereIn('id', $ids)->whereNull('anonymized_at')->orderBy('name')->orderBy('id')->get();
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 16; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Certificate::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    /** QR sebagai PNG data URI (dompdf tidak memuat sumber luar). */
    private function qr(string $text): string
    {
        $m = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        [$n, $px, $quiet] = [$m->getWidth(), 8, 2];
        $size = ($n + 2 * $quiet) * $px;
        $img = imagecreate($size, $size);
        imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m->get($x, $y) === 1) {
                    imagefilledrectangle($img, ($x + $quiet) * $px, ($y + $quiet) * $px, ($x + $quiet + 1) * $px - 1, ($y + $quiet + 1) * $px - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
