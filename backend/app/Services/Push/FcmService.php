<?php

namespace App\Services\Push;

use App\Jobs\WakePhones;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Membangunkan app lewat Firebase dengan sinyal kosong: tanpa judul, isi, atau identitas. App lalu mengambil
 * notifikasinya sendiri dari server. Nonaktif selama FCM_CREDENTIALS_BASE64 kosong.
 */
class FcmService
{
    private const CHUNK = 50;

    public static function enabled(): bool
    {
        return self::credentials() !== null;
    }

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    public static function credentials(): ?array
    {
        $raw = config('services.fcm.credentials');
        $c = filled($raw) ? json_decode((string) base64_decode((string) $raw, true), true) : null;

        return is_array($c) && filled($c['project_id'] ?? null) && filled($c['client_email'] ?? null) && filled($c['private_key'] ?? null) ? $c : null;
    }

    /** Daftarkan HP pada sesi masuk ini. False bila server tanpa Firebase atau proyeknya berbeda dari APK. */
    public function register(User $user, int $accessTokenId, string $token, string $projectId): bool
    {
        if (! hash_equals((string) (self::credentials()['project_id'] ?? ''), $projectId)) {
            return false;
        }
        $hash = hash('sha256', $token);
        DB::transaction(function () use ($user, $accessTokenId, $token, $hash) {
            // HP yang sama bisa berganti akun, dan satu sesi bisa berganti token.
            PushDevice::where('token_hash', $hash)->orWhere('access_token_id', $accessTokenId)->delete();
            PushDevice::create(['user_id' => $user->id, 'access_token_id' => $accessTokenId, 'token' => $token, 'token_hash' => $hash]);
        });

        return true;
    }

    /** Antrekan sinyal sesudah data tersimpan. @param Collection<int, int> $userIds */
    public static function wakeLater(Collection $userIds): void
    {
        if (self::enabled() && $userIds->isNotEmpty()) {
            WakePhones::dispatch($userIds->unique()->values()->all())->afterCommit();
        }
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int> id HP yang gagal sementara dan perlu dicoba lagi
     */
    public function wake(array $userIds): array
    {
        return $this->send(PushDevice::whereIn('user_id', $userIds)->whereHas('user', fn ($q) => $q->whereNull('disabled_at'))->get());
    }

    /** @return list<int> */
    public function retry(array $deviceIds): array
    {
        return $this->send(PushDevice::whereIn('id', $deviceIds)->whereHas('user', fn ($q) => $q->whereNull('disabled_at'))->get());
    }

    private function send(Collection $devices): array
    {
        $c = self::credentials();
        if (! $c || $devices->isEmpty()) {
            return [];
        }
        $bearer = $this->accessToken($c);
        $url = "https://fcm.googleapis.com/v1/projects/{$c['project_id']}/messages:send";
        $failed = [];
        foreach ($devices->chunk(self::CHUNK) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => $chunk->map(fn (PushDevice $d) => $pool->as((string) $d->id)->withToken($bearer)->timeout(10)
                ->post($url, ['message' => ['token' => $d->token, 'data' => ['t' => 'sync'], 'android' => ['priority' => 'HIGH', 'ttl' => '86400s', 'collapse_key' => 'sync']]]))->all());
            foreach ($chunk as $d) {
                $r = $responses[(string) $d->id] ?? null;
                if ($r instanceof Response && $r->successful()) {
                    continue;
                }
                $code = $r instanceof Response ? collect($r->json('error.details') ?? [])->pluck('errorCode')->filter()->first() : null;
                if (in_array($code, ['UNREGISTERED', 'SENDER_ID_MISMATCH'], true)) {
                    $d->delete(); // app dicopot atau token diganti
                } elseif (! $r instanceof Response || $r->status() === 429 || $r->serverError()) {
                    $failed[] = $d->id;
                } elseif ($r->status() === 401) {
                    Cache::forget($this->cacheKey($c));
                    $failed[] = $d->id;
                } else {
                    report(new \RuntimeException("FCM gagal: {$r->status()} ".($code ?? (string) $r->json('error.status'))));
                }
            }
        }

        return $failed;
    }

    private function cacheKey(array $c): string
    {
        return 'fcm:token:'.hash('sha256', $c['client_email'].$c['project_id']);
    }

    private function accessToken(array $c): string
    {
        return Cache::remember($this->cacheKey($c), now()->addMinutes(50), function () use ($c) {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $now = now()->timestamp;
            $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode(['iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
            if (! openssl_sign($jwt, $signature, $c['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Kunci Firebase tidak valid.');
            }

            return Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt.'.'.$b64($signature)])
                ->throw()->json('access_token') ?? throw new \RuntimeException('Google tidak memberi token akses.');
        });
    }
}
