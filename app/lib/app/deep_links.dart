import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../screens/connect_screen.dart';
import 'server_config.dart';
import 'session.dart';

/// Menangani tautan kartuanggota://connect?server=… (QR penghubung dipindai dari kamera HP).
class DeepLinkHandler {
  DeepLinkHandler(this.navigator, {this._links, Future<Session?> Function()? session}) : _session = session ?? Session.load;

  final GlobalKey<NavigatorState> navigator;
  final Stream<Uri>? _links;
  final Future<Session?> Function() _session;
  StreamSubscription<Uri>? _sub;

  void start() => _sub = (_links ?? AppLinks().uriLinkStream).listen(handle, onError: (_) {});

  void dispose() => _sub?.cancel();

  Future<void> handle(Uri uri) async {
    if (serverMode == ServerMode.fixed) return; // build kunci tetap: alamat tidak bisa diganti
    final parsed = ServerAddress.parseQr(uri.toString());
    if (parsed == null || !uri.isScheme('kartuanggota')) return;

    final session = await _session();
    final nav = navigator.currentState;
    final context = navigator.currentContext;
    if (nav == null || context == null || !context.mounted) return;
    if (session != null) {
      if (ServerAddress.normalize(session.baseUrl) == parsed.url) return; // sudah terhubung ke organisasi ini
      // Jangan memutus sesi yang sedang dipakai tanpa sepengetahuan pengguna.
      await showShadDialog<void>(
        context: context,
        builder: (context) => ShadDialog.alert(
          title: const Text('Sedang masuk ke organisasi lain'),
          description: Text('Keluar dulu dari akun saat ini, lalu pindai QR penghubung lagi untuk menghubungkan ke ${parsed.name ?? parsed.url}.'),
          actions: [ShadButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Mengerti'))],
        ),
      );
      return;
    }
    nav.pushAndRemoveUntil(MaterialPageRoute<void>(builder: (_) => ConnectScreen(initialUrl: parsed.url)), (_) => false);
  }
}
