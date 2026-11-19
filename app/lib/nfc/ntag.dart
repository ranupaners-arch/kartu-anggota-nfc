import 'dart:convert';
import 'dart:typed_data';

import 'package:nfc_manager/ndef_record.dart';

/// Model NTAG21x yang didukung, dari respons GET_VERSION (byte ke-6).
enum NtagModel {
  ntag213(0x0F, cfg0: 0x29, userBytes: 144),
  ntag215(0x11, cfg0: 0x83, userBytes: 504),
  ntag216(0x13, cfg0: 0xE3, userBytes: 888);

  const NtagModel(this.storageCode, {required this.cfg0, required this.userBytes});

  final int storageCode;

  /// Halaman konfigurasi: CFG0 (AUTH0 di byte 3), CFG1 (ACCESS di byte 0),
  /// lalu PWD dan PACK.
  final int cfg0;
  final int userBytes;

  int get cfg1 => cfg0 + 1;
  int get pwdPage => cfg0 + 2;
  int get packPage => cfg0 + 3;

  static NtagModel? fromVersion(Uint8List version) {
    // Vendor NXP (0x04), tipe NTAG (0x04).
    if (version.length < 8 || version[1] != 0x04 || version[2] != 0x04) {
      return null;
    }
    for (final m in values) {
      if (m.storageCode == version[6]) return m;
    }
    return null;
  }
}

/// Perintah NTAG21x mentah (untuk MifareUltralight.transceive).
abstract final class NtagCommand {
  static final getVersion = Uint8List.fromList([0x60]);

  static Uint8List pwdAuth(Uint8List pwd) {
    assert(pwd.length == 4);
    return Uint8List.fromList([0x1B, ...pwd]);
  }
}

/// Halaman awal memori pengguna NTAG21x.
const firstUserPage = 4;

/// NDEF Text record (bahasa "en") berisi [text].
NdefMessage textMessage(String text) {
  final lang = ascii.encode('en');
  final body = utf8.encode(text);
  return NdefMessage(
    records: [
      NdefRecord(
        typeNameFormat: TypeNameFormat.wellKnown,
        type: Uint8List.fromList([0x54]), // "T"
        identifier: Uint8List(0),
        payload: Uint8List.fromList([lang.length, ...lang, ...body]),
      ),
    ],
  );
}

/// Ambil teks dari Text record pertama; null bila tidak ada.
String? firstText(NdefMessage? message) {
  for (final r in message?.records ?? const <NdefRecord>[]) {
    if (r.typeNameFormat == TypeNameFormat.wellKnown && r.type.length == 1 && r.type[0] == 0x54 && r.payload.isNotEmpty) {
      final langLen = r.payload[0] & 0x3F;
      if (r.payload.length < 1 + langLen) return null;
      return utf8.decode(r.payload.sublist(1 + langLen), allowMalformed: true);
    }
  }
  return null;
}

/// Bungkus pesan NDEF dalam TLV Type 2 Tag, dipotong per halaman 4 byte.
List<Uint8List> ndefTlvPages(NdefMessage message, NtagModel model) {
  final ndef = message.encode();
  final bytes = <int>[
    0x03,
    if (ndef.length < 0xFF) ndef.length else ...[0xFF, ndef.length >> 8, ndef.length & 0xFF],
    ...ndef,
    0xFE, // terminator TLV
  ];
  if (bytes.length > model.userBytes) {
    throw StateError('Payload ${bytes.length} byte melebihi kapasitas ${model.name} (${model.userBytes} byte).');
  }
  while (bytes.length % 4 != 0) {
    bytes.add(0x00);
  }
  return [for (var i = 0; i < bytes.length; i += 4) Uint8List.fromList(bytes.sublist(i, i + 4))];
}

/// Apakah kartu sudah diproteksi password (AUTH0 menunjuk ke memori pengguna).
bool isProtected(Uint8List cfgPages, NtagModel model) {
  final auth0 = cfgPages[3];
  return auth0 <= model.packPage;
}

/// CFG1 baru: bit PROT (7) = 0 → hanya TULIS yang butuh password; baca tetap bebas.
Uint8List cfg1WriteProtectOnly(Uint8List cfg1) => Uint8List.fromList([cfg1[0] & 0x7F, cfg1[1], cfg1[2], cfg1[3]]);

/// CFG0 baru dengan AUTH0 = [fromPage] (proteksi mulai halaman itu).
Uint8List cfg0WithAuth0(Uint8List cfg0, int fromPage) => Uint8List.fromList([cfg0[0], cfg0[1], cfg0[2], fromPage]);

Uint8List hexToBytes(String hex, {required int length}) {
  final h = hex.trim().toLowerCase();
  if (h.length != length * 2 || !RegExp(r'^[0-9a-f]+$').hasMatch(h)) {
    throw FormatException('Harus $length byte hex (${length * 2} karakter).');
  }
  return Uint8List.fromList([for (var i = 0; i < h.length; i += 2) int.parse(h.substring(i, i + 2), radix: 16)]);
}

String bytesToHex(List<int> b) => b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();

/// UID disamarkan untuk log: hanya byte pertama + panjang.
String maskUid(List<int> uid) => uid.isEmpty ? '-' : '${bytesToHex(uid.take(1).toList())}${'••' * (uid.length - 1)} (${uid.length} byte)';
