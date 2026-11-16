import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../api/ids.dart';
import '../app/session.dart';
import 'events_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _url = TextEditingController(text: 'http://');
  final _email = TextEditingController();
  final _password = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    Session.lastServerUrl().then((u) {
      if (u != null && mounted) setState(() => _url.text = u);
    });
  }

  @override
  void dispose() {
    _url.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    final url = _url.text.trim().replaceAll(RegExp(r'/+$'), '');
    final invalid = LoginScreenValidation.check(url);
    if (invalid != null) return setState(() => _error = invalid);

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ApiClient(baseUrl: url);
      final user = await api.login(_email.text.trim(), _password.text, deviceUuid: await deviceUuid());
      final session = Session(baseUrl: url, token: api.token!, userName: user['name'] as String? ?? '', role: user['role'] as String? ?? '');
      await session.save();
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute<void>(builder: (_) => EventsScreen(session: session)));
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'Server tidak terjangkau. Periksa URL dan koneksi (Tailscale/Wi-Fi).');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Scaffold(
      backgroundColor: theme.colorScheme.muted,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: ShadCard(
                title: const Text('Masuk Petugas'),
                description: const Text('Gunakan akun petugas atau pengurus.'),
                child: Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    spacing: 12,
                    children: [
                      if (_error != null) ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)),
                      _field('URL server', ShadInput(controller: _url, keyboardType: TextInputType.url, leading: const Icon(LucideIcons.server, size: 16))),
                      _field('Email', ShadInput(controller: _email, keyboardType: TextInputType.emailAddress, placeholder: const Text('nama@organisasi.id'),
                          leading: const Icon(LucideIcons.mail, size: 16))),
                      _field('Password', ShadInput(controller: _password, obscureText: true, leading: const Icon(LucideIcons.lock, size: 16),
                          onSubmitted: (_) => _busy ? null : _login())),
                      const SizedBox(height: 4),
                      ShadButton(onPressed: _busy ? null : _login, child: Text(_busy ? 'Memproses…' : 'Masuk')),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _field(String label, Widget input) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        spacing: 6,
        children: [Text(label, style: ShadTheme.of(context).textTheme.small), input],
      );
}

/// Validasi URL server (dipisah agar bisa dites).
abstract final class LoginScreenValidation {
  /// Null bila valid; selain itu pesan kesalahan.
  static String? check(String url) {
    final ok = (url.startsWith('http://') && url.length > 'http://'.length) || (url.startsWith('https://') && url.length > 'https://'.length);
    return ok ? null : 'URL server harus diawali http:// atau https://, contoh http://100.84.251.14:8000';
  }
}
