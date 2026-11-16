import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';

/// Sesi login petugas yang disimpan di perangkat.
/// Catatan: token disimpan di SharedPreferences; untuk rilis sebaiknya pakai penyimpanan terenkripsi.
class Session {
  Session({required this.baseUrl, required this.token, required this.userName, required this.role});

  final String baseUrl;
  final String token;
  final String userName;
  final String role;

  ApiClient client() => ApiClient(baseUrl: baseUrl)..token = token;

  static const _kUrl = 'server_url', _kToken = 'token', _kName = 'user_name', _kRole = 'user_role';

  static Future<Session?> load() async {
    final p = await SharedPreferences.getInstance();
    final url = p.getString(_kUrl), token = p.getString(_kToken);
    if (url == null || token == null) return null;
    return Session(baseUrl: url, token: token, userName: p.getString(_kName) ?? '', role: p.getString(_kRole) ?? '');
  }

  static Future<String?> lastServerUrl() async => (await SharedPreferences.getInstance()).getString(_kUrl);

  Future<void> save() async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kUrl, baseUrl);
    await p.setString(_kToken, token);
    await p.setString(_kName, userName);
    await p.setString(_kRole, role);
  }

  /// Hapus token; URL server tetap diingat untuk login berikutnya.
  static Future<void> clear() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kToken);
    await p.remove(_kName);
    await p.remove(_kRole);
  }
}
