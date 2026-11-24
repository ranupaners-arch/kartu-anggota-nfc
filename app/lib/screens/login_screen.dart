import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../api/ids.dart';
import '../app/server_config.dart';
import '../app/session.dart';
import 'change_password_screen.dart';
import 'register_screen.dart';
import 'connect_screen.dart';
import '../app/home.dart';
import '../widgets/ui.dart';
import 'splash_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.server, this.notice});

  final ServerConfig server;

  /// Pesan info di atas formulir (mis. sesi dicabut karena peran berubah).
  final String? notice;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _otp = TextEditingController();
  bool _needOtp = false;
  // Tombol "Daftar" hanya bila organisasi membuka pendaftaran akun dari app.
  late final Future<bool> _canRegister = ApiClient(baseUrl: widget.server.url).registrationOpen();
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
      final session = Session(baseUrl: widget.server.url, token: api.token!, userName: user['name'] as String? ?? '', role: user['role'] as String? ?? '', hasMember: user['member_id'] != null, userId: user['id'] as int?);
      // Password sementara dari pengurus: ganti dulu; sesi baru disimpan setelah berhasil.
      if (user['must_change_password'] == true) {
        if (!mounted) return;
        Navigator.of(context).pushReplacement(
          MaterialPageRoute<void>(builder: (_) => ChangePasswordScreen(api: api, session: session, currentPassword: _password.text, server: widget.server)),
        );
        return;
      }
      await session.save();
      if (!mounted) return;
      // Bersihkan tumpukan layar (sambutan/masuk) agar layar utama tidak punya tombol kembali ke login.
      Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute<void>(builder: (_) => homeFor(session)), (_) => false);
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
    final serverLabel = widget.server.name.isNotEmpty ? widget.server.name : Uri.parse(widget.server.url).host;
    return Scaffold(
      backgroundColor: splashBlack,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: AuthLayout(
                child: ShadCard(
                  title: const Text('Masuk'),
                  description: Text(serverMode == ServerMode.selectable ? 'Organisasi: $serverLabel' : 'Gunakan akun anggota, petugas, atau pengurus.'),
                  child: Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      spacing: 12,
                      children: [
                        if (widget.notice != null && _error == null) ShadAlert(icon: const Icon(LucideIcons.info), description: Text(widget.notice!)),
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
                        FutureBuilder<bool>(
                          future: _canRegister,
                          builder: (context, snap) => snap.data == true
                              ? FlexButton(
                                  variant: ShadButtonVariant.outline,
                                  onPressed: _busy ? null : () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => RegisterScreen(server: widget.server))),
                                  label: 'Belum punya akun? Daftar',
                                )
                              : const SizedBox.shrink(),
                        ),
                        FlexButton(variant: ShadButtonVariant.link, small: true, onPressed: _busy ? null : () => showForgotPassword(context, ApiClient(baseUrl: widget.server.url), _email.text.trim()), label: 'Lupa password?'),
                        if (serverMode == ServerMode.selectable) FlexButton(variant: ShadButtonVariant.link, small: true, onPressed: _busy ? null : _changeServer, label: 'Ganti organisasi'),
                      ],
                    ),
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

/// Dialog lupa password: kirim tautan atur ulang ke email. Bila email server belum diatur, pesan
/// server meminta pengguna menghubungi pengurus.
Future<void> showForgotPassword(BuildContext context, ApiClient api, String email) {
  return showShadDialog<void>(context: context, builder: (_) => _ForgotDialog(api: api, email: email));
}

class _ForgotDialog extends StatefulWidget {
  const _ForgotDialog({required this.api, required this.email});

  final ApiClient api;
  final String email;

  @override
  State<_ForgotDialog> createState() => _ForgotDialogState();
}

class _ForgotDialogState extends State<_ForgotDialog> {
  late final _email = TextEditingController(text: widget.email);
  String? _message;
  bool _error = false;
  bool _busy = false;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final email = _email.text.trim();
    if (!email.contains('@')) {
      setState(() {
        _error = true;
        _message = 'Isi email akun Anda.';
      });
      return;
    }
    setState(() => _busy = true);
    try {
      final msg = await widget.api.forgotPassword(email);
      setState(() {
        _error = false;
        _message = msg;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = true;
        _message = e.message;
      });
    } catch (_) {
      setState(() {
        _error = true;
        _message = 'Server tidak terjangkau. Periksa koneksi.';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sent = _message != null && !_error;
    return ShadDialog(
      title: const Text('Lupa password'),
      description: const Text('Kami kirim tautan ke email Anda untuk membuat password baru.'),
      actions: [
        ShadButton.outline(onPressed: () => Navigator.of(context).pop(), child: Text(sent ? 'Selesai' : 'Batal')),
        if (!sent) ShadButton(enabled: !_busy, onPressed: _busy ? null : _send, child: Text(_busy ? 'Mengirim…' : 'Kirim tautan')),
      ],
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          spacing: 12,
          children: [
            if (_message != null)
              _error
                  ? ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_message!))
                  : ShadAlert(icon: const Icon(LucideIcons.mailCheck), description: Text(_message!)),
            if (!sent) ShadInput(controller: _email, keyboardType: TextInputType.emailAddress, placeholder: const Text('nama@organisasi.id'), leading: const Icon(LucideIcons.mail, size: 16)),
          ],
        ),
      ),
    );
  }
}
