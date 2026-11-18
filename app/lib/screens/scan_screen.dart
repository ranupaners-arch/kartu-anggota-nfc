import 'dart:async';

import 'package:material_ui/material_ui.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:nfc_manager/nfc_manager.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../api/ids.dart';
import '../app/session.dart';
import '../feedback/scan_feedback.dart';
import '../nfc/nfc_service.dart';
import '../offline/offline_store.dart';
import '../offline/offline_verifier.dart';
import '../offline/roster.dart';
import '../offline/sync_service.dart';
import '../widgets/scan_result_card.dart';
import '../widgets/ui.dart';

enum ScanMode { nfc, qr }

/// Layar scan petugas: kartu NFC dibaca terus selama layar terbuka; QR lewat kamera.
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key, required this.session, required this.event, this.nfc, this.api, this.store, this.clock, this.feedback});

  final Session session;
  final EventSummary event;
  final NfcService? nfc;

  /// Untuk tes.
  final ApiClient? api;
  final OfflineStore? store;
  final DateTime Function()? clock;
  final ScanFeedback? feedback;

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> with WidgetsBindingObserver {
  late final ApiClient _api = widget.api ?? widget.session.client();
  late final NfcService _nfc = widget.nfc ?? NfcService();
  late final ScanFeedback _feedback = widget.feedback ?? ScanFeedback();
  DateTime _now() => (widget.clock ?? DateTime.now)();
  OfflineStore? _store;
  Roster? _roster;
  int _pending = 0;
  bool _syncing = false;
  String? _syncNote;
  Timer? _syncTimer;
  final Set<int> _present = {}; // anggota yang sudah tercatat di sesi ini (cek duplikat offline)
  ScanMode _mode = ScanMode.nfc;
  ScanResult? _last;
  String? _nfcProblem;
  bool _busy = false;
  int _loop = 0; // generasi loop NFC; berubah = loop lama berhenti
  int _okCount = 0;
  String? _lastQr;
  DateTime _lastQrAt = DateTime.fromMillisecondsSinceEpoch(0);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _startNfc();
    _initOffline();
    _feedback.load().then((_) {
      if (mounted) setState(() {});
    });
  }

  /// Ambil roster terbaru bila online (simpan untuk offline), selain itu pakai yang tersimpan.
  Future<void> _initOffline() async {
    final store = widget.store ?? await OfflineStore.open();
    if (!mounted) return;
    _store = store;
    try {
      final roster = Roster.fromApi(await _api.roster(widget.event.id));
      await store.saveRoster(roster);
      _roster = roster;
    } catch (_) {
      _roster = store.loadRoster(widget.event.id);
    }
    if (!mounted) return;
    setState(() => _pending = store.pending(eventId: widget.event.id).length);
    _syncTimer = Timer.periodic(const Duration(seconds: 30), (_) => _sync(quiet: true));
    if (_pending > 0) _sync(quiet: true);
  }

  Future<void> _sync({bool quiet = false}) async {
    final store = _store;
    if (store == null || _syncing || store.pending(eventId: widget.event.id).isEmpty) return;
    setState(() => _syncing = true);
    final r = await SyncService(_api, store).sync(widget.event.id);
    if (!mounted) return;
    setState(() {
      _syncing = false;
      _pending = r.remaining;
      if (r.sent > 0) {
        _syncNote = '${r.sent} scan tersinkron (${r.accepted} diterima, ${r.duplicates} duplikat, ${r.rejected} ditolak).';
      } else if (!quiet && r.error != null) {
        _syncNote = r.error;
      }
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _syncTimer?.cancel();
    _loop++;
    _nfc.stop();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (_mode != ScanMode.nfc) return;
    if (state == AppLifecycleState.resumed) {
      _startNfc();
    } else if (state == AppLifecycleState.paused) {
      _loop++;
      _nfc.stop();
    }
  }

  Future<void> _startNfc() async {
    final gen = ++_loop;
    final status = await _nfc.availability();
    if (!mounted || gen != _loop) return;
    if (status != NfcAvailability.enabled) {
      setState(() => _nfcProblem = status == NfcAvailability.disabled
          ? 'NFC mati. Aktifkan NFC di pengaturan HP, lalu kembali ke sini.'
          : 'HP ini tidak mendukung NFC. Gunakan mode QR.');
      return;
    }
    setState(() => _nfcProblem = null);
    // Baca kartu berulang: tiap read() menunggu tap berikutnya.
    while (mounted && gen == _loop) {
      try {
        final r = await _nfc.read();
        if (!mounted || gen != _loop) return;
        if (r.payload == null) {
          _show(const ScanResult(status: 'invalid', message: 'Bukan kartu anggota.'));
        } else {
          await _submit(r.text!, 'nfc');
        }
      } on NfcException catch (e) {
        if (!mounted || gen != _loop) return;
        _show(ScanResult(status: 'invalid', message: e.message));
      }
    }
  }

  void _switchMode(ScanMode mode) {
    if (mode == _mode) return;
    setState(() => _mode = mode);
    if (mode == ScanMode.nfc) {
      _startNfc();
    } else {
      _loop++;
      _nfc.stop();
    }
  }

  Future<void> _onQr(BarcodeCapture capture) async {
    final raw = capture.barcodes.firstOrNull?.rawValue;
    if (raw == null || _busy) return;
    // Abaikan QR yang sama dalam 3 detik (kamera mendeteksi berkali-kali).
    if (raw == _lastQr && DateTime.now().difference(_lastQrAt) < const Duration(seconds: 3)) return;
    _lastQr = raw;
    _lastQrAt = DateTime.now();
    await _submit(raw, 'qr');
  }

  Future<void> _submit(String payload, String source) async {
    setState(() => _busy = true);
    final uuid = uuidV4();
    final scannedAt = _now();
    try {
      final r = await _api.scan(widget.event.id, payload, source: source, clientUuid: uuid, scannedAt: scannedAt);
      if ((r.ok || r.status == 'duplicate') && r.memberId != null) _present.add(r.memberId!);
      _show(r);
      if (_pending > 0) unawaited(_sync(quiet: true)); // sedang online: kirim antrean
    } on ApiException catch (e) {
      _show(ScanResult(status: 'error', message: e.status == 401 ? 'Sesi berakhir. Keluar lalu masuk lagi.' : e.message));
    } catch (_) {
      await _offline(payload, source, uuid, scannedAt); // jaringan putus
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Tanpa internet: periksa dengan roster lokal lalu simpan ke antrean.
  Future<void> _offline(String payload, String source, String uuid, DateTime scannedAt) async {
    final roster = _roster, store = _store;
    if (source == 'qr') {
      return _show(const ScanResult(status: 'error', message: 'QR butuh koneksi internet. Gunakan kartu NFC saat offline.', offline: true));
    }
    if (roster == null || store == null) {
      return _show(const ScanResult(status: 'error', message: 'Offline dan belum ada data peserta. Buka layar ini sekali saat online.', offline: true));
    }
    final r = OfflineVerifier.check(roster, payload, scannedAt, alreadyPresent: _present);
    if (r.ok) {
      await store.enqueue(PendingScan(clientUuid: uuid, eventId: widget.event.id, payload: payload, source: source, scannedAt: scannedAt));
      _present.add(r.memberId!);
      if (mounted) setState(() => _pending = store.pending(eventId: widget.event.id).length);
    }
    _show(r);
  }

  void _show(ScanResult r) {
    if (!mounted) return;
    if (r.ok) _okCount++;
    _feedback.play(r);
    setState(() => _last = r);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    Widget modeButton(ScanMode m, IconData icon, String label) => Expanded(
          child: _mode == m
              ? ShadButton(leading: Icon(icon, size: 16), onPressed: () {}, child: Text(label))
              : ShadButton.outline(leading: Icon(icon, size: 16), onPressed: () => _switchMode(m), child: Text(label)),
        );

    return AppPage(
      title: widget.event.title,
      leading: const BackButton(),
      actions: [
        ShadIconButton.ghost(
          icon: Icon(_feedback.muted ? LucideIcons.volumeX : LucideIcons.volume2, size: 18),
          onPressed: () async {
            await _feedback.setMuted(!_feedback.muted);
            if (mounted) setState(() {});
          },
        ),
      ],
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(spacing: 8, children: [modeButton(ScanMode.nfc, LucideIcons.nfc, 'Kartu NFC'), modeButton(ScanMode.qr, LucideIcons.qrCode, 'QR')]),
          const SizedBox(height: 16),
          if (_mode == ScanMode.nfc)
            _nfcProblem != null
                ? ShadAlert.destructive(icon: const Icon(LucideIcons.triangleAlert), title: const Text('NFC tidak siap'), description: Text(_nfcProblem!))
                : ShadCard(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 24),
                      child: Column(spacing: 8, children: [
                        Icon(LucideIcons.smartphoneNfc, size: 48, color: theme.colorScheme.mutedForeground),
                        Text(_busy ? 'Memproses…' : 'Tempelkan kartu ke belakang HP', style: theme.textTheme.large),
                        Text('Siap membaca kartu berikutnya', style: theme.textTheme.muted),
                      ]),
                    ),
                  )
          else
            ClipRRect(
              borderRadius: theme.radius,
              child: AspectRatio(aspectRatio: 1, child: MobileScanner(onDetect: _onQr)),
            ),
          const SizedBox(height: 16),
          if (_pending > 0 || _syncNote != null) ...[
            ShadCard(
              padding: const EdgeInsets.all(12),
              child: Row(spacing: 8, children: [
                Icon(_pending > 0 ? LucideIcons.cloudOff : LucideIcons.cloudCheck, size: 18, color: theme.colorScheme.mutedForeground),
                Expanded(child: Text(_pending > 0 ? '$_pending scan belum tersinkron${_syncNote != null ? '\n$_syncNote' : ''}' : _syncNote!, style: theme.textTheme.small)),
                if (_pending > 0)
                  ShadButton.outline(size: ShadButtonSize.sm, enabled: !_syncing, onPressed: _sync, child: Text(_syncing ? 'Mengirim…' : 'Sinkron')),
              ]),
            ),
            const SizedBox(height: 12),
          ],
          if (_last != null) ScanResultCard(result: _last!, token: widget.session.token),
          if (_roster != null) ...[
            const SizedBox(height: 8),
            Center(child: Text('Data offline: ${_roster!.members.length} peserta', style: theme.textTheme.muted)),
          ],
          const SizedBox(height: 12),
          Center(child: Text('$_okCount berhasil tercatat di sesi ini', style: theme.textTheme.muted)),
        ],
      ),
    );
  }
}
