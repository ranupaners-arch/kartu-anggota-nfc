import 'dart:convert';

import 'package:crypto/crypto.dart';

/// Payload kartu: `<prefix>:v1:<key_id>:<public_id>:<hmac>`.
/// Harus sama dengan `App\Support\CardPayload` di backend.
///
/// HMAC tidak bisa diverifikasi di perangkat (secret hanya di server).
/// Verifikasi offline memakai [fingerprint] yang dicocokkan dengan roster.
class CardPayload {
  const CardPayload({
    required this.prefix,
    required this.keyId,
    required this.publicId,
    required this.mac,
  });

  final String prefix;
  final String keyId;
  final String publicId;
  final String mac;

  static const defaultPrefix = 'card';

  static final _ulid = RegExp(r'^[0-9A-HJKMNP-TV-Z]{26}$');
  static final _hex = RegExp(r'^[0-9a-f]+$');

  /// Null bila format tidak cocok. Tidak memverifikasi HMAC.
  static CardPayload? parse(String raw, {String prefix = defaultPrefix}) {
    final p = raw.trim().split(':');
    if (p.length != 5 || p[0] != prefix || p[1] != 'v1') return null;
    if (p[2].isEmpty || !_ulid.hasMatch(p[3]) || !_hex.hasMatch(p[4])) {
      return null;
    }
    return CardPayload(prefix: p[0], keyId: p[2], publicId: p[3], mac: p[4]);
  }

  String encode() => '$prefix:v1:$keyId:$publicId:$mac';

  /// sha256 hex dari payload; sama dengan `payload_sha256` di roster.
  static String fingerprint(String payload) =>
      sha256.convert(utf8.encode(payload.trim())).toString();

  /// Token dari payload QR `<prefix>:qr:<token>`; null bila bukan QR.
  static String? qrToken(String raw, {String prefix = defaultPrefix}) {
    final head = '$prefix:qr:';
    final s = raw.trim();
    if (!s.startsWith(head) || s.length == head.length) return null;
    return s.substring(head.length);
  }
}
