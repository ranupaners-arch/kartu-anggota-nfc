<?php

namespace App\Support;

use App\Models\Card;

/**
 * Payload NDEF kartu: <prefix>:v1:<key_id>:<card_public_id>:<hmac>
 * Payload QR anggota: <prefix>:qr:<token>
 * Prefix diatur di config/attendance.php (CARD_PAYLOAD_PREFIX).
 */
class CardPayload
{
    public static function prefix(): string
    {
        return config('attendance.payload_prefix');
    }

    public static function hmac(string $keyId, string $publicId): ?string
    {
        $secret = config("attendance.card_keys.$keyId");
        if (! $secret) {
            return null;
        }
        $raw = hash_hmac('sha256', $keyId.$publicId, $secret, true);

        return bin2hex(substr($raw, 0, config('attendance.hmac_bytes')));
    }

    public static function make(Card $card): string
    {
        return sprintf('%s:v1:%s:%s:%s', self::prefix(), $card->key_id, $card->public_id, self::hmac($card->key_id, $card->public_id));
    }

    /** @return array{key_id:string,public_id:string}|null null bila format/HMAC salah */
    public static function verify(string $payload): ?array
    {
        $p = explode(':', trim($payload));
        if (count($p) !== 5 || $p[0] !== self::prefix() || $p[1] !== 'v1') {
            return null;
        }
        [, , $keyId, $publicId, $mac] = $p;
        $expected = self::hmac($keyId, $publicId);

        return $expected && hash_equals($expected, $mac) ? ['key_id' => $keyId, 'public_id' => $publicId] : null;
    }

    public static function makeQr(string $token): string
    {
        return self::prefix().':qr:'.$token;
    }

    /** Ambil token dari payload QR; null bila prefix tidak cocok. */
    public static function qrToken(string $payload): ?string
    {
        $head = self::prefix().':qr:';
        $payload = trim($payload);

        return str_starts_with($payload, $head) ? substr($payload, strlen($head)) ?: null : null;
    }

    /** Hash payload untuk verifikasi offline tanpa membocorkan secret ke perangkat. */
    public static function fingerprint(string $payload): string
    {
        return hash('sha256', $payload);
    }
}
