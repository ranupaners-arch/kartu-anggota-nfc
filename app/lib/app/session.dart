import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../offline/offline_store.dart';

/// Sesi login petugas yang disimpan di perangkat.
/// Catatan: token disimpan di SharedPreferences; untuk rilis sebaiknya pakai penyimpanan terenkripsi.
class Session {
  Session({required this.baseUrl, required this.token, required this.userName, required this.role, this.hasMember = false, this.userId});

  final String baseUrl;
  final String token;
  final String userName;
  final String role;

  /// Akun tertaut ke data anggota (anggota biasa, atau anggota yang sedang bertugas sebagai petugas/pengurus).
  final bool hasMember;

  /// Null hanya untuk sesi dari versi app lama, sampai server menjawab (lihat SessionWatch).
  int? userId;

  /// Pemilik data offline di HP ini (server + akun).
  String? get owner => userId == null ? null : '$baseUrl|$userId';

  /// Anggota yang sedang bertugas: tampilan anggota + tab "Tugas".
  bool get isMemberOnDuty => role != 'member' && hasMember;

  ApiClient client() => ApiClient(baseUrl: baseUrl)..token = token;

  // URL server disimpan bersama ServerConfig (kunci 'server_url').
  static const _kUrl = 'server_url', _kToken = 'token', _kName = 'user_name', _kRole = 'user_role', _kHasMember = 'user_has_member', _kUserId = 'user_id';

  static Future<Session?> load() async {
    final p = await SharedPreferences.getInstance();
    final url = p.getString(_kUrl), token = p.getString(_kToken);
    if (url == null || token == null) return null;
    return Session(baseUrl: url, token: token, userName: p.getString(_kName) ?? '', role: p.getString(_kRole) ?? '', hasMember: p.getBool(_kHasMember) ?? false, userId: p.getInt(_kUserId));
  }

  Future<void> save() async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kUrl, baseUrl);
    await p.setString(_kToken, token);
    await p.setString(_kName, userName);
    await p.setString(_kRole, role);
    await p.setBool(_kHasMember, hasMember);
    final id = userId;
    id == null ? await p.remove(_kUserId) : await p.setInt(_kUserId, id);
  }

  /// Hapus token; URL server tetap diingat untuk login berikutnya.
  static Future<void> clear() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kToken);
    await p.remove(_kName);
    await p.remove(_kRole);
    await p.remove(_kHasMember);
    await p.remove(_kUserId);
    for (final k in p.getKeys().where((k) => k.startsWith(OfflineStore.eventsKey)).toList()) {
      await p.remove(k); // kegiatan akun lama tidak tampil untuk akun berikutnya
    }
  }
}
