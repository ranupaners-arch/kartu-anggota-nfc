import 'dart:async';

import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../app/session.dart';
import '../cards/card_issuer.dart';
import '../nfc/nfc_service.dart';
import '../widgets/ui.dart';

/// Terbitkan kartu NFC untuk anggota (Super Admin): cari → konfirmasi → tulis kartu → tautkan.
class IssueCardScreen extends StatefulWidget {
  const IssueCardScreen({super.key, required this.session, this.api, this.writer, this.nfc});

  final Session session;

  /// Untuk tes.
  final ApiClient? api;
  final CardWriter? writer;
  final NfcService? nfc;

  @override
  State<IssueCardScreen> createState() => _IssueCardScreenState();
}

class _IssueCardScreenState extends State<IssueCardScreen> {
  late final ApiClient _api = widget.api ?? widget.session.client();
  late final NfcService _nfc = widget.nfc ?? NfcService();
  late final CardIssuer _issuer = CardIssuer(_api, widget.writer ?? (init) => _nfc.write(payload: init.payload, pwdHex: init.pwd, packHex: init.pack, protect: true));
  final _query = TextEditingController();
  Timer? _debounce;
  List<MemberHit> _hits = const [];
  MemberHit? _selected;
  IssueStep? _step;
  String? _error;
  bool _searching = false;

  @override
  void dispose() {
    _debounce?.cancel();
    _query.dispose();
    _nfc.stop();
    super.dispose();
  }

  void _onQuery(String q) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () => _search(q));
  }

  Future<void> _search(String q) async {
    if (q.trim().length < 2) return setState(() => _hits = const []);
    setState(() => _searching = true);
    try {
      final hits = await _api.searchMembers(q);
      if (mounted) setState(() => _hits = hits);
    } catch (e) {
      if (mounted) setState(() => _error = e is ApiException ? e.message : 'Server tidak terjangkau.');
    } finally {
      if (mounted) setState(() => _searching = false);
    }
  }

  Future<void> _run(Future<void> Function() op) async {
    setState(() => _error = null);
    try {
      await op();
      HapticFeedback.heavyImpact();
    } on NfcException catch (e) {
      setState(() {
        _step = null;
        _error = 'Kartu belum tertulis: ${e.message} Coba lagi dengan kartu yang sama.';
      });
    } on ApiException catch (e) {
      setState(() {
        _error = _issuer.needsRelink ? 'Kartu sudah tertulis tetapi belum tertaut: ${e.message}' : e.message;
        if (!_issuer.needsRelink) _step = null;
      });
    } catch (_) {
      setState(() {
        _error = _issuer.needsRelink ? 'Kartu sudah tertulis tetapi belum tertaut (server tidak terjangkau).' : 'Server tidak terjangkau.';
        if (!_issuer.needsRelink) _step = null;
      });
    }
  }

  void _setStep(IssueStep s) {
    if (mounted) setState(() => _step = s);
  }

  void _reset() => setState(() {
    _selected = null;
    _step = null;
    _error = null;
    _hits = const [];
    _query.clear();
  });

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return AppPage(
      title: 'Terbitkan Kartu',
      leading: const BackButton(),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_error != null) ...[ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)), const SizedBox(height: 12)],
          if (_selected == null) ..._searchView(theme) else _issueView(theme, _selected!),
        ],
      ),
    );
  }

  List<Widget> _searchView(ShadThemeData theme) => [
    Text('Cari anggota (nama atau nomor anggota)', style: theme.textTheme.small),
    const SizedBox(height: 6),
    ShadInput(controller: _query, onChanged: _onQuery, placeholder: const Text('min. 2 huruf'), leading: const Icon(LucideIcons.search, size: 16)),
    const SizedBox(height: 12),
    if (_searching)
      const Center(
        child: Padding(padding: EdgeInsets.all(16), child: CircularProgressIndicator()),
      ),
    for (final m in _hits)
      Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: GestureDetector(
          onTap: () => setState(() => _selected = m),
          child: ShadCard(
            padding: const EdgeInsets.all(12),
            title: Text(m.name),
            description: Text([m.memberNo, if (m.division != null) m.division!].join(' · ')),
            trailing: m.hasActiveCard ? ShadBadge.secondary(child: const Text('Punya kartu')) : Icon(LucideIcons.chevronRight, color: theme.colorScheme.mutedForeground),
          ),
        ),
      ),
    if (!_searching && _query.text.trim().length >= 2 && _hits.isEmpty) Text('Tidak ada anggota yang cocok.', style: theme.textTheme.muted),
  ];

  Widget _issueView(ShadThemeData theme, MemberHit m) {
    final step = _step;
    final busy = step != null && step != IssueStep.done;
    final (IconData icon, String title, String desc) = switch (step) {
      null => (LucideIcons.creditCard, 'Siap menerbitkan', m.hasActiveCard ? 'Kartu lama anggota ini akan diblokir otomatis.' : 'Siapkan kartu NTAG215 kosong.'),
      IssueStep.preparing => (LucideIcons.loader, 'Menyiapkan kartu…', 'Meminta data kartu ke server.'),
      IssueStep.waitingCard => (LucideIcons.smartphoneNfc, 'Tempelkan kartu kosong', 'Tahan kartu di belakang HP sampai selesai.'),
      IssueStep.linking => (LucideIcons.link, 'Menautkan ke anggota…', 'Kartu sudah tertulis dan terkunci.'),
      IssueStep.done => (LucideIcons.circleCheck, 'Kartu aktif', 'Kartu siap dipakai absen.'),
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      spacing: 12,
      children: [
        ShadCard(title: Text(m.name), description: Text([m.memberNo, if (m.division != null) m.division!].join(' · '))),
        ShadCard(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Column(
              spacing: 8,
              children: [
                Icon(icon, size: 44, color: step == IssueStep.done ? const Color(0xFF047857) : theme.colorScheme.mutedForeground),
                Text(title, style: theme.textTheme.large),
                Text(desc, style: theme.textTheme.muted, textAlign: TextAlign.center),
              ],
            ),
          ),
        ),
        if (step == null)
          FlexButton(onPressed: () => _run(() => _issuer.issue(m, _setStep)), label: 'Terbitkan & tulis kartu')
        else if (_issuer.needsRelink && !busy)
          ShadButton(onPressed: () => _run(() => _issuer.relink(m, _setStep)), child: const Text('Tautkan ulang'))
        else if (step == IssueStep.waitingCard)
          ShadButton.outline(
            onPressed: () {
              _nfc.cancel();
            },
            child: const Text('Batal'),
          ),
        if (step == IssueStep.done) ShadButton.outline(onPressed: _reset, child: const Text('Terbitkan kartu lain')),
        if (step == null) ShadButton.ghost(onPressed: _reset, child: const Text('Pilih anggota lain')),
      ],
    );
  }
}
