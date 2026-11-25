import 'dart:async';

import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../api/response_cache.dart';
import '../offline/offline_store.dart';
import '../notify/phone_notices.dart';
import '../offline/photo_cache.dart';
import 'token_store.dart';

/// Sesi login petugas yang disimpan di perangkat.
/// Token di penyimpanan terenkripsi ([tokens]); sisanya di SharedPreferences.
class Session {
  /// Diganti di tes.
  static TokenStore tokens = const SecureTokenStore();

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

  ApiClient client() => ApiClient(baseUrl: baseUrl)
    ..token = token
    ..cache = owner == null ? null : ResponseCache(owner!);

  // URL server disimpan bersama ServerConfig (kunci 'server_url').
  static const _kUrl = 'server_url', _kToken = 'token', _kName = 'user_name', _kRole = 'user_role', _kHasMember = 'user_has_member', _kUserId = 'user_id';

  static Future<Session?> load() async {
    final p = await SharedPreferences.getInstance();
    final url = p.getString(_kUrl), token = await _loadToken(p);
    if (url == null || token == null || token.isEmpty || !p.containsKey(_kRole)) return null;
    return Session(baseUrl: url, token: token, userName: p.getString(_kName) ?? '', role: p.getString(_kRole) ?? '', hasMember: p.getBool(_kHasMember) ?? false, userId: p.getInt(_kUserId));
  }

  Future<void> save() async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kUrl, baseUrl);
    try {
      await tokens.write(token);
      await p.remove(_kToken);
    } catch (_) {
      // Keystore bermasalah: sesi berlaku sampai app ditutup; token tidak ditulis tanpa enkripsi, dan
      // token akun sebelumnya tidak boleh terpakai dengan identitas akun ini.
      if (p.getString(_kToken) != token) {
        await p.remove(_kToken);
        await p.remove(_kRole);
        return;
      }
    }
    await p.setString(_kName, userName);
    await p.setString(_kRole, role);
    await p.setBool(_kHasMember, hasMember);
    final id = userId;
    id == null ? await p.remove(_kUserId) : await p.setInt(_kUserId, id);
  }

  static Future<String?> _loadToken(SharedPreferences p) async {
    final old = p.getString(_kToken); // versi app lama: tanpa enkripsi
    if (old != null) {
      try {
        await tokens.write(old);
        await p.remove(_kToken);
      } catch (_) {
        // dicoba lagi saat app dibuka berikutnya; jangan mengeluarkan petugas
      }
      return old;
    }
    try {
      return await tokens.read();
    } catch (_) {
      return null; // kunci Keystore hilang: masuk lagi
    }
  }

  /// Hapus token & data pribadi peserta (antrean scan tetap); URL server tetap diingat untuk login berikutnya.
  static Future<void> clear() async {
    final p = await SharedPreferences.getInstance();
    final url = p.getString(_kUrl), id = p.getInt(_kUserId);
    try {
      await tokens.delete();
    } catch (_) {
      // peran yang dihapus di bawah sudah membuat sesi tidak dimuat lagi
    }
    await p.remove(_kToken);
    await p.remove(_kName);
    await p.remove(_kRole);
    await p.remove(_kHasMember);
    await p.remove(_kUserId);
    for (final k in p.getKeys().where((k) => k.startsWith(OfflineStore.eventsKey) || k.startsWith('roster:') || k.startsWith(ResponseCache.prefix)).toList()) {
      await p.remove(k); // kegiatan & peserta akun lama tidak tertinggal untuk akun berikutnya
    }
    unawaited(PhotoCache.clearAll());
    unawaited(PhoneNotices.signedOut(url == null || id == null ? null : '$url|$id'));
  }
}
