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
import '../offline/photo_cache.dart';
import '../offline/offline_verifier.dart';
import '../offline/roster.dart';
import '../offline/sync_service.dart';
import '../widgets/scan_result_card.dart';
import '../widgets/ui.dart';

enum ScanMode { nfc, qr }

/// Layar scan petugas: kartu NFC dibaca terus selama layar terbuka; QR lewat kamera.
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key, required this.session, required this.event, this.nfc, this.api, this.store, this.clock, this.feedback, this.photos});

  final Session session;
  final EventSummary event;
  final NfcService? nfc;

  /// Untuk tes.
  final ApiClient? api;
  final OfflineStore? store;
  final DateTime Function()? clock;
  final ScanFeedback? feedback;
  final PhotoCache? photos;

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> with WidgetsBindingObserver {
  late final ApiClient _api = widget.api ?? widget.session.client();
  late final NfcService _nfc = widget.nfc ?? NfcService();
  late final ScanFeedback _feedback = widget.feedback ?? ScanFeedback();
  DateTime _now() => (widget.clock ?? DateTime.now)();
  OfflineStore? _store;
  PhotoCache? _photos;
  Roster? _roster;
  int _pending = 0;
  bool _syncing = false;
  String? _syncNote;
  Timer? _syncTimer;
  // Anggota yang sudah tercatat, per sesi kegiatan (cek duplikat offline). Kunci 0 = tanpa sesi.
  final Map<int, Set<int>> _presentBySession = {};
  Set<int> get _present => _presentBySession.putIfAbsent(_sessionId ?? 0, () => {});

  /// Sesi kegiatan yang dicatat (kegiatan multi-sesi). Bawaan: sesi yang sedang membuka check-in.
  int? _sessionId;
  List<SessionInfo> get _sessions => widget.event.sessions.isNotEmpty ? widget.event.sessions : (_roster?.sessions ?? const []);
  SessionInfo? get _session => _sessions.where((s) => s.id == _sessionId).firstOrNull;
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
    _sessionId = _defaultSession(widget.event.sessions)?.id;
    _startNfc();
    _initOffline();
    _feedback.load().then((_) {
      if (mounted) setState(() {});
    });
  }

  /// Sesi yang sedang membuka check-in (menurut server, lalu jam HP), atau sesi utama.
  SessionInfo? _defaultSession(List<SessionInfo> sessions) {
    if (sessions.isEmpty) return null;
    final now = _now();
    return sessions.where((s) => s.id == widget.event.currentSessionId).firstOrNull ?? sessions.where((s) => s.isOpenAt(now)).firstOrNull ?? sessions.first;
  }

  void _selectSession(int id) => setState(() {
    _sessionId = id;
    _last = null;
  });

  /// Pakai roster tersimpan dulu (siap offline seketika), lalu perbarui dari server bila online.
  Future<void> _initOffline() async {
    final store = widget.store ?? await OfflineStore.open(owner: widget.session.owner);
    if (!mounted) return;
    _store = store;
    _useRoster(store.loadRoster(widget.event.id));
    setState(() => _pending = store.pending(eventId: widget.event.id).length);
    _syncTimer = Timer.periodic(const Duration(seconds: 30), (_) => _sync(quiet: true));
    if (_pending > 0) unawaited(_sync(quiet: true));
    try {
      _photos = widget.photos ?? await PhotoCache.open(); // juga saat offline: foto yang sudah tersimpan
    } catch (_) {
      // Penyimpanan tidak tersedia: foto hanya dari server saat online.
    }
    try {
      final roster = Roster.fromApi(await _api.roster(widget.event.id));
      await store.saveRoster(roster);
      if (!mounted) return;
      setState(() => _useRoster(roster));
      unawaited(_cachePhotos(roster)); // di latar belakang; scan tidak menunggu
    } catch (_) {
      // Offline: tetap memakai roster tersimpan.
    }
  }

  void _useRoster(Roster? roster) {
    _roster = roster;
    _sessionId ??= _defaultSession(roster?.sessions ?? const [])?.id; // offline: sesi dari roster tersimpan
  }

  Future<void> _cachePhotos(Roster roster) async {
    try {
      await _photos?.sync(roster, _api.downloadBytes);
    } catch (_) {
      // Penyimpanan penuh/tidak tersedia: foto tetap dimuat dari server saat online.
    }
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
        _syncNote = '${r.sent} scan tersinkron (${r.accepted} diterima, ${r.duplicates} duplikat, ${r.rejected} ditolak).${r.rejected > 0 ? ' Rincian ada di Beranda.' : ''}';
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
      setState(() => _nfcProblem = status == NfcAvailability.disabled ? 'NFC mati. Aktifkan NFC di pengaturan HP, lalu kembali ke sini.' : 'HP ini tidak mendukung NFC. Gunakan mode QR.');
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
      final r = await _api.scan(widget.event.id, payload, source: source, clientUuid: uuid, scannedAt: scannedAt, sessionId: _sessionId);
      if ((r.ok || r.status == 'duplicate') && r.memberId != null) _present.add(r.memberId!);
      _show(r);
      if (_pending > 0) unawaited(_sync(quiet: true)); // sedang online: kirim antrean
    } on ApiException catch (e) {
      if (e.unreachable) return await _offline(payload, source, uuid, scannedAt); // server mati di belakang proxy
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
    final r = OfflineVerifier.check(roster, payload, scannedAt, alreadyPresent: _present, session: _session);
    if (r.ok) {
      await store.enqueue(PendingScan(clientUuid: uuid, eventId: widget.event.id, payload: payload, source: source, scannedAt: scannedAt, sessionId: _sessionId));
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
      child: _mode == m ? FlexButton(icon: icon, onPressed: () {}, label: label) : FlexButton(variant: ShadButtonVariant.outline, icon: icon, onPressed: () => _switchMode(m), label: label),
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
      // Landscape/tablet lebar: dua panel (area scan | hasil). Potret: satu kolom.
      maxContentWidth: 1100,
      child: LayoutBuilder(
        builder: (context, box) {
          final twoPane = box.maxWidth >= 640 && box.maxWidth > box.maxHeight;
          // Kotak kamera persegi, tidak lebih tinggi dari ~70% tinggi layar.
          final cameraSide = twoPane ? (box.maxHeight * 0.7).clamp(200.0, 520.0) : double.infinity;
          final controls = <Widget>[
            if (_sessions.length > 1) ...[
              Text('Sesi', style: theme.textTheme.small),
              const SizedBox(height: 6),
              // Grid dua kolom selebar layar: judul sesi panjang turun baris, tidak meluber.
              LayoutBuilder(
                builder: (context, c) {
                  final w = (c.maxWidth - 8) / 2;
                  return Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final s in _sessions)
                        SizedBox(
                          width: w,
                          child: FlexButton(
                            small: true,
                            variant: s.id == _sessionId ? ShadButtonVariant.primary : ShadButtonVariant.outline,
                            onPressed: s.id == _sessionId ? () {} : () => _selectSession(s.id),
                            label: s.title,
                          ),
                        ),
                    ],
                  );
                },
              ),
              if (_session != null && !_session!.isOpenAt(_now())) ...[
                const SizedBox(height: 8),
                Text('Check-in sesi ini ${_now().isBefore(_session!.opensAt) ? 'belum dibuka' : 'sudah ditutup'}. Pastikan sesi yang dipilih benar.', style: theme.textTheme.muted),
              ],
              const SizedBox(height: 16),
            ],
            Row(spacing: 8, children: [modeButton(ScanMode.nfc, LucideIcons.nfc, 'Kartu NFC'), modeButton(ScanMode.qr, LucideIcons.qrCode, 'QR')]),
            const SizedBox(height: 16),
            if (_mode == ScanMode.nfc)
              _nfcProblem != null
                  ? ShadAlert.destructive(icon: const Icon(LucideIcons.triangleAlert), title: const Text('NFC tidak siap'), description: Text(_nfcProblem!))
                  : ShadCard(
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 24),
                        child: Column(
                          spacing: 8,
                          children: [
                            Icon(LucideIcons.smartphoneNfc, size: 48, color: theme.colorScheme.mutedForeground),
                            Text(_busy ? 'Memproses…' : 'Tempelkan kartu ke belakang HP', style: theme.textTheme.large),
                            Text('Siap membaca kartu berikutnya', style: theme.textTheme.muted),
                          ],
                        ),
                      ),
                    )
            else
              Center(
                child: ConstrainedBox(
                  constraints: BoxConstraints(maxWidth: cameraSide),
                  child: ClipRRect(
                    borderRadius: theme.radius,
                    child: AspectRatio(aspectRatio: 1, child: MobileScanner(onDetect: _onQr)),
                  ),
                ),
              ),
          ];
          final info = <Widget>[
            if (_pending > 0 || _syncNote != null) ...[
              ShadCard(
                padding: const EdgeInsets.all(12),
                child: Row(
                  spacing: 8,
                  children: [
                    Icon(_pending > 0 ? LucideIcons.cloudOff : LucideIcons.cloudCheck, size: 18, color: theme.colorScheme.mutedForeground),
                    Expanded(child: Text(_pending > 0 ? '$_pending scan belum tersinkron${_syncNote != null ? '\n$_syncNote' : ''}' : _syncNote!, style: theme.textTheme.small)),
                    if (_pending > 0) ShadButton.outline(size: ShadButtonSize.sm, enabled: !_syncing, onPressed: _sync, child: Text(_syncing ? 'Mengirim…' : 'Sinkron')),
                  ],
                ),
              ),
              const SizedBox(height: 12),
            ],
            if (_last != null) ScanResultCard(result: _last!, token: widget.session.token, photoFile: _photos?.cached(_last!.memberId, _last!.photoUrl)),
            if (_roster != null) ...[const SizedBox(height: 8), Center(child: Text('Data offline: ${_roster!.members.length} peserta', style: theme.textTheme.muted))],
            const SizedBox(height: 12),
            Center(child: Text(_sessions.length > 1 ? '$_okCount berhasil tercatat sejak layar dibuka' : '$_okCount berhasil tercatat di sesi ini', style: theme.textTheme.muted)),
          ];
          if (!twoPane) {
            return ListView(padding: const EdgeInsets.all(16), children: [...controls, const SizedBox(height: 16), ...info]);
          }
          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: ListView(padding: const EdgeInsets.fromLTRB(16, 16, 8, 16), children: controls),
              ),
              Expanded(
                child: ListView(padding: const EdgeInsets.fromLTRB(8, 16, 16, 16), children: info),
              ),
            ],
          );
        },
      ),
    );
  }
}
