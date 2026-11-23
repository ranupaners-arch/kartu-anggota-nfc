import 'package:flutter/services.dart';

const _channel = MethodChannel('kartuanggota/settings');

/// Buka halaman pengaturan NFC di HP (Android). Mengembalikan false bila tidak bisa dibuka.
Future<bool> openNfcSettings() async {
  try {
    return await _channel.invokeMethod<bool>('openNfcSettings') ?? false;
  } on PlatformException {
    return false;
  } on MissingPluginException {
    return false;
  }
}

/// Mode layar QR (Android): [on] = layar tetap menyala & kecerahan maksimal; false = kembali seperti semula.
/// Diabaikan diam-diam di platform lain (mis. web) atau bila gagal — QR tetap tampil.
Future<void> setQrScreenMode(bool on) async {
  try {
    await _channel.invokeMethod<bool>('setQrScreenMode', on);
  } on PlatformException {
    // abaikan
  } on MissingPluginException {
    // abaikan
  }
}
