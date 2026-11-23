import 'package:material_ui/material_ui.dart';

import '../api/api_client.dart';
import '../screens/connect_screen.dart';
import '../screens/login_screen.dart';
import 'home.dart';
import 'server_config.dart';
import 'session.dart';

/// Cek ulang akun ke server saat layar utama dibuka & setiap app kembali aktif:
/// - sesi dicabut (mis. peran diubah Super Admin, akun dinonaktifkan) → kembali ke layar Masuk dengan pesan;
/// - peran berubah → pindah ke layar utama peran baru tanpa keluar-masuk.
/// Tanpa koneksi / error lain → diam saja (app tetap bisa dipakai offline).
mixin SessionWatch<T extends StatefulWidget> on State<T> {
  late final AppLifecycleListener _lifecycle;
  bool _checking = false;

  Session get watchedSession;
  ApiClient get watchedApi;

  @override
  void initState() {
    super.initState();
    _lifecycle = AppLifecycleListener(onResume: checkSession);
    WidgetsBinding.instance.addPostFrameCallback((_) => checkSession());
  }

  @override
  void dispose() {
    _lifecycle.dispose();
    super.dispose();
  }

  Future<void> checkSession() async {
    if (_checking || !mounted) return;
    _checking = true;
    try {
      final me = await watchedApi.me();
      final role = me['role'] as String?;
      final name = me['name'] as String? ?? watchedSession.userName;
      if (role == null || !mounted) return;
      if (role != watchedSession.role) {
        final next = Session(baseUrl: watchedSession.baseUrl, token: watchedSession.token, userName: name, role: role);
        await next.save();
        if (!mounted) return;
        Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute<void>(builder: (_) => homeFor(next)), (_) => false);
      }
    } on ApiException catch (e) {
      if (e.status == 401 && mounted) await _signedOut();
    } catch (_) {
      // offline / server sibuk: abaikan, dicek lagi saat app aktif berikutnya
    } finally {
      _checking = false;
    }
  }

  Future<void> _signedOut() async {
    await Session.clear();
    final server = await ServerConfig.load();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(
        builder: (_) => server == null ? const ConnectScreen() : LoginScreen(server: server, notice: 'Sesi Anda berakhir — peran atau akses akun mungkin diubah pengurus. Silakan masuk lagi.'),
      ),
      (_) => false,
    );
  }
}
