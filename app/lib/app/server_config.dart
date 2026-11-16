import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

/// Cara app menentukan server, diatur saat build:
/// - `--dart-define=SERVER_MODE=fixed --dart-define=API_URL=https://...` → satu server pusat, tanpa pilihan.
/// - `SERVER_MODE=selectable` (bawaan) → tiap organisasi punya server sendiri; dihubungkan lewat QR/alamat.
enum ServerMode { fixed, selectable }

const _modeDefine = String.fromEnvironment('SERVER_MODE', defaultValue: 'selectable');
const _apiUrlDefine = String.fromEnvironment('API_URL');

ServerMode get serverMode => _modeDefine == 'fixed' && _apiUrlDefine.isNotEmpty ? ServerMode.fixed : ServerMode.selectable;

/// Versi kontrak API minimum yang dibutuhkan app ini.
const minApiVersion = 1;

/// Server tempat app terhubung (alamat + nama dari /api/instance).
class ServerConfig {
  const ServerConfig({required this.url, required this.name});

  final String url;
  final String name;

  static const _kUrl = 'server_url', _kName = 'server_name';

  /// Server bawaan untuk mode fixed.
  static ServerConfig? get fixed => serverMode == ServerMode.fixed ? ServerConfig(url: _apiUrlDefine, name: '') : null;

  static Future<ServerConfig?> load() async {
    if (fixed != null) return fixed;
    final p = await SharedPreferences.getInstance();
    final url = p.getString(_kUrl);
    return url == null ? null : ServerConfig(url: url, name: p.getString(_kName) ?? '');
  }

  Future<void> save() async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kUrl, url);
    await p.setString(_kName, name);
  }

  static Future<void> forget() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kUrl);
    await p.remove(_kName);
  }
}

class ServerConnectException implements Exception {
  ServerConnectException(this.message);
  final String message;
  @override
  String toString() => message;
}

abstract final class ServerAddress {
  /// Rapikan input: buang spasi & garis miring akhir, tambahkan http:// bila tanpa skema.
  static String normalize(String input) {
    var url = input.trim().replaceAll(RegExp(r'/+$'), '');
    if (url.isNotEmpty && !RegExp(r'^[a-zA-Z][a-zA-Z0-9+.-]*://').hasMatch(url)) url = 'http://$url';
    return url;
  }

  /// Null bila valid. APK rilis hanya mau HTTPS (HTTP diblokir Android di build rilis).
  static String? check(String url, {bool release = kReleaseMode}) {
    final https = url.startsWith('https://') && url.length > 'https://'.length;
    final httpOk = url.startsWith('http://') && url.length > 'http://'.length;
    if (https || (httpOk && !release)) return null;
    if (httpOk && release) return 'Server harus memakai https://. Hubungi pengurus organisasi.';
    return 'Alamat server harus diawali http:// atau https://';
  }

  /// Baca QR "Hubungkan app" dari dashboard (kartuanggota://connect?server=...&name=...) atau URL biasa.
  static ({String url, String? name})? parseQr(String raw) {
    final text = raw.trim();
    final uri = Uri.tryParse(text);
    if (uri != null && uri.scheme == 'kartuanggota' && uri.host == 'connect') {
      final server = uri.queryParameters['server'];
      return server == null || server.isEmpty ? null : (url: normalize(server), name: uri.queryParameters['name']);
    }
    if (text.startsWith('http://') || text.startsWith('https://')) return (url: normalize(text), name: null);
    return null;
  }

  /// Pastikan alamat memang server Kartu Anggota yang cocok, lalu kembalikan konfigurasinya.
  static Future<ServerConfig> verify(String url, {http.Client? client}) async {
    final invalid = check(url);
    if (invalid != null) throw ServerConnectException(invalid);
    final c = client ?? http.Client();
    final http.Response res;
    try {
      res = await c.get(Uri.parse('$url/api/instance'), headers: {'Accept': 'application/json'}).timeout(const Duration(seconds: 8));
    } catch (_) {
      throw ServerConnectException('Server tidak terjangkau. Periksa alamat dan koneksi.');
    }
    Map<String, dynamic>? j;
    try {
      j = jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {}
    if (res.statusCode != 200 || j?['app'] != 'kartu-anggota') {
      throw ServerConnectException('Alamat ini bukan server Kartu Anggota.');
    }
    if ((j!['api_version'] as int? ?? 0) < minApiVersion) {
      throw ServerConnectException('Versi server terlalu lama untuk app ini. Minta pengurus memperbarui server.');
    }
    return ServerConfig(url: url, name: j['name'] as String? ?? '');
  }
}
