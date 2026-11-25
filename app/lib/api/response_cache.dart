import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Jawaban terakhir server untuk layar anggota, agar tetap bisa dilihat tanpa internet. Per server + akun;
/// dihapus saat keluar (Session.clear).
class ResponseCache {
  const ResponseCache(this.owner);

  final String owner;

  static const prefix = 'cache:';

  /// Hanya data baca milik sendiri. QR & sesi sengaja tidak disimpan.
  static const paths = {'/api/me/card', '/api/me/events', '/api/me/history', '/api/me/announcements', '/api/me/notifications', '/api/me/achievements', '/api/me/leaderboard'};

  String _key(String path) => '$prefix$path@$owner';

  Future<void> save(String path, Map<String, dynamic> body) async =>
      (await SharedPreferences.getInstance()).setString(_key(path), jsonEncode({'at': DateTime.now().toUtc().toIso8601String(), 'body': body}));

  Future<({DateTime at, Map<String, dynamic> body})?> load(String path) async {
    try {
      final raw = (await SharedPreferences.getInstance()).getString(_key(path));
      if (raw == null) return null;
      final j = jsonDecode(raw) as Map<String, dynamic>;
      return (at: DateTime.parse(j['at'] as String).toLocal(), body: j['body'] as Map<String, dynamic>);
    } catch (_) {
      return null; // data rusak: anggap tidak ada
    }
  }
}
