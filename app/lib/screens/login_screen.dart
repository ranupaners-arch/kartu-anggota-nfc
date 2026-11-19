import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../api/ids.dart';
import '../app/server_config.dart';
import '../app/session.dart';
import 'connect_screen.dart';
import '../app/home.dart';
import '../widgets/ui.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.server});

  final ServerConfig server;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _otp = TextEditingController();
  bool _needOtp = false;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _otp.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ApiClient(baseUrl: widget.server.url);
      final user = await api.login(_email.text.trim(), _password.text, deviceUuid: await deviceUuid(), otp: _needOtp ? _otp.text.trim() : null);
      final session = Session(baseUrl: widget.server.url, token: api.token!, userName: user['name'] as String? ?? '', role: user['role'] as String? ?? '');
      await session.save();
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute<void>(builder: (_) => homeFor(session)));
    } on ApiException catch (e) {
      setState(() {
        // Kolom kode 2FA muncul saat pertama diminta; pesan hanya ditampilkan bila kode sudah diisi.
        final firstAsk = e.otpRequired && !_needOtp;
        _needOtp = _needOtp || e.otpRequired;
        _error = firstAsk ? null : e.message;
        if (e.otpRequired) _otp.clear();
      });
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
                title: const Text('Masuk'),
                description: Text(serverMode == ServerMode.selectable ? 'Organisasi: $serverLabel' : 'Gunakan akun anggota, petugas, atau pengurus.'),
                child: Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    spacing: 12,
                    children: [
                      if (_error != null) ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)),
                      _field(
                        'Email',
                        ShadInput(controller: _email, keyboardType: TextInputType.emailAddress, placeholder: const Text('nama@organisasi.id'), leading: const Icon(LucideIcons.mail, size: 16)),
                      ),
                      _field('Password', ShadInput(controller: _password, obscureText: true, leading: const Icon(LucideIcons.lock, size: 16), onSubmitted: (_) => _busy ? null : _login())),
                      if (_needOtp)
                        _field(
                          'Kode 2FA',
                          ShadInput(
                            controller: _otp,
                            keyboardType: TextInputType.visiblePassword,
                            autofocus: true,
                            placeholder: const Text('6 digit dari aplikasi authenticator, atau kode cadangan'),
                            leading: const Icon(LucideIcons.shieldCheck, size: 16),
                            onSubmitted: (_) => _busy ? null : _login(),
                          ),
                        ),
                      const SizedBox(height: 4),
                      FlexButton(onPressed: _busy ? null : _login, label: _busy ? 'Memproses…' : 'Masuk'),
                      if (serverMode == ServerMode.selectable) FlexButton(variant: ShadButtonVariant.link, small: true, onPressed: _busy ? null : _changeServer, label: 'Ganti organisasi'),
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
    children: [
      Text(label, style: ShadTheme.of(context).textTheme.small),
      input,
    ],
  );
}
