import 'dart:math';

import 'package:shared_preferences/shared_preferences.dart';

/// UUID v4 acak (kriptografis).
String uuidV4() {
  final r = Random.secure();
  final b = List<int>.generate(16, (_) => r.nextInt(256));
  b[6] = (b[6] & 0x0F) | 0x40;
  b[8] = (b[8] & 0x3F) | 0x80;
  final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
}

/// ID perangkat tetap per instalasi; didaftarkan server saat login.
Future<String> deviceUuid() async {
  final prefs = await SharedPreferences.getInstance();
  return prefs.getString('device_uuid') ?? (await _store(prefs, uuidV4()));
}

Future<String> _store(SharedPreferences p, String v) async {
  await p.setString('device_uuid', v);
  return v;
}
