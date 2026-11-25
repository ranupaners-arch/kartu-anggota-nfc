import 'package:material_ui/material_ui.dart';

import '../api/api_client.dart';
import '../api/response_cache.dart';
import '../offline/offline_store.dart';
import '../offline/sync_service.dart';
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

  /// false = layar tersemat di layar lain yang sudah mengecek (hindari cek ganda).
  bool get watchEnabled => true;

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
    if (_checking || !mounted || !watchEnabled) return;
    _checking = true;
    try {
      final me = await watchedApi.me();
      final role = me['role'] as String?;
      final name = me['name'] as String? ?? watchedSession.userName;
      final hasMember = me['member_id'] != null;
      if (role == null || !mounted) return;
      final id = me['id'] as int?;
      if (id != null && watchedSession.userId == null) {
        watchedSession.userId = id; // sesi dari versi app lama: data offline kini terikat ke akun ini
        await watchedSession.save();
        watchedApi.cache ??= ResponseCache(watchedSession.owner!); // simpan untuk offline mulai sekarang
        if (!mounted) return;
      }
      if (role != watchedSession.role || hasMember != watchedSession.hasMember) {
        final next = Session(baseUrl: watchedSession.baseUrl, token: watchedSession.token, userName: name, role: role, hasMember: hasMember, userId: watchedSession.userId);
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

  /// Sebelum keluar karena 401: antrean perangkat yang dicabut dititipkan untuk ditinjau.
  Future<({int held, bool retry})> uploadHeldScans() async {
    try {
      return await SyncService(watchedApi, await OfflineStore.open(owner: watchedSession.owner)).uploadHeld();
    } catch (_) {
      return (held: 0, retry: false); // penyimpanan tidak tersedia
    }
  }

  static String? heldNotice(int held) => held == 0 ? null : 'Akses Anda di perangkat ini berakhir. $held scan offline dikirim untuk ditinjau pengurus.';

  Future<void> _signedOut() async {
    final r = await uploadHeldScans();
    if (r.retry) return; // dicoba lagi saat app aktif berikutnya
    await Session.clear();
    final server = await ServerConfig.load();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(
        builder: (_) => server == null
            ? const ConnectScreen()
            : LoginScreen(server: server, notice: heldNotice(r.held) ?? 'Sesi Anda berakhir — peran atau akses akun mungkin diubah pengurus. Silakan masuk lagi.'),
      ),
      (_) => false,
    );
  }
}
