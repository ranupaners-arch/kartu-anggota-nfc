import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../api/ids.dart';
import '../app/server_config.dart';
import '../app/session.dart';
import 'connect_screen.dart';
import 'events_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.server});

  final ServerConfig server;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ApiClient(baseUrl: widget.server.url);
      final user = await api.login(_email.text.trim(), _password.text, deviceUuid: await deviceUuid());
      final session = Session(baseUrl: widget.server.url, token: api.token!, userName: user['name'] as String? ?? '', role: user['role'] as String? ?? '');
      await session.save();
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute<void>(builder: (_) => EventsScreen(session: session)));
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Server tidak terjangkau. Periksa koneksi.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _changeServer() async {
    await ServerConfig.forget();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute<void>(builder: (_) => const ConnectScreen()));
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final serverLabel = widget.server.name.isNotEmpty ? widget.server.name : Uri.parse(widget.server.url).host;
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
                description: Text(serverMode == ServerMode.selectable ? 'Organisasi: $serverLabel' : 'Gunakan akun petugas atau pengurus.'),
                child: Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    spacing: 12,
                    children: [
                      if (_error != null) ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)),
                      _field('Email', ShadInput(controller: _email, keyboardType: TextInputType.emailAddress, placeholder: const Text('nama@organisasi.id'),
                          leading: const Icon(LucideIcons.mail, size: 16))),
                      _field('Password', ShadInput(controller: _password, obscureText: true, leading: const Icon(LucideIcons.lock, size: 16),
                          onSubmitted: (_) => _busy ? null : _login())),
                      const SizedBox(height: 4),
                      ShadButton(onPressed: _busy ? null : _login, child: Text(_busy ? 'Memproses…' : 'Masuk')),
                      if (serverMode == ServerMode.selectable)
                        ShadButton.link(size: ShadButtonSize.sm, onPressed: _busy ? null : _changeServer, child: const Text('Ganti organisasi')),
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
