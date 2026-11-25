import 'dart:async';

import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../app/format.dart';
import '../app/server_config.dart';
import '../app/session.dart';
import '../app/session_watch.dart';
import '../offline/offline_store.dart';
import '../offline/roster.dart';
import '../offline/sync_service.dart';
import '../widgets/ui.dart';
import 'connect_screen.dart';
import 'issue_card_screen.dart';
import 'login_screen.dart';
import 'nfc_tools_screen.dart';
import 'rejected_scans_screen.dart';
import 'scan_screen.dart';

const _roleLabel = {'scanner': 'Petugas Scan', 'division_admin': 'Admin Divisi', 'super_admin': 'Super Admin'};

/// Beranda petugas & pengurus (gaya sama dengan beranda anggota): sapaan & peran, menu cepat, scan offline
/// yang belum tersinkron, dan kegiatan berlangsung dengan tombol "Mulai scan".
class EventsScreen extends StatefulWidget {
  const EventsScreen({super.key, required this.session, this.api, this.offlineStore, this.embedded = false});

  final Session session;

  /// Untuk tes.
  final ApiClient? api;
  final Future<OfflineStore> Function()? offlineStore;

  /// Tersemat sebagai tab "Tugas" di layar anggota yang bertugas: judul "Tugas", tanpa tombol keluar
  /// (sudah ada di bilah atas anggota) & tanpa cek sesi ganda.
  final bool embedded;

  @override
  State<EventsScreen> createState() => _EventsScreenState();
}

class _EventsScreenState extends State<EventsScreen> with SessionWatch<EventsScreen> {
  late final ApiClient _api = widget.api ?? widget.session.client();

  @override
  Session get watchedSession => widget.session;

  @override
  ApiClient get watchedApi => _api;

  @override
  bool get watchEnabled => !widget.embedded;

  /// Null = belum ada apa pun untuk ditampilkan. Data tersimpan tampil dulu, lalu diganti dari server.
  List<EventSummary>? _events;
  Object? _error;
  bool _loading = true;

  /// Kesiapan offline per kegiatan: jumlah peserta tersimpan, atau pesan galat unduhannya.
  final Map<int, int> _ready = {};
  final Map<int, String> _notReady = {};
  String? _org;
  int _pendingOffline = 0;
  int _rejected = 0;
  int _othersPending = 0;
  bool _offline = false;
  bool _syncing = false;
  String? _syncNote;
  Timer? _syncTimer;
  late final AppLifecycleListener _resume;

  @override
  void initState() {
    super.initState();
    ServerConfig.load().then((s) {
      if (mounted && s?.name != null) setState(() => _org = s!.name);
    }, onError: (_) {});
    _showSaved().then((_) => _load());
    _sync();
    // Juga untuk kegiatan yang sudah tutup (tidak bisa dibuka lagi di layar Scan).
    _syncTimer = Timer.periodic(const Duration(seconds: 30), (_) => _sync());
    _resume = AppLifecycleListener(onResume: _sync);
  }

  @override
  void dispose() {
    _syncTimer?.cancel();
    _resume.dispose();
    super.dispose();
  }

  /// Kirim semua scan tertunda. Saat layar lain di atas (mis. Scan) dilewati: layar itu punya sinkron sendiri.
  Future<void> _sync() async {
    if (_syncing) return;
    _syncing = true;
    try {
      final store = await _openStore();
      if (!mounted) return;
      if (store.pending().isNotEmpty && (ModalRoute.of(context)?.isCurrent ?? true)) {
        final r = await SyncService(_api, store).syncAll();
        if (!mounted) return;
        if (r.sent > 0) _syncNote = '${r.sent} scan tersinkron (${r.accepted} diterima, ${r.duplicates} duplikat, ${r.rejected} ditolak).';
      }
      await _countOffline();
    } catch (_) {
      // penyimpanan tidak tersedia: dicoba lagi nanti
    } finally {
      _syncing = false;
    }
  }

  Future<void> _countOffline() async {
    try {
      final store = await _openStore();
      if (mounted) {
        setState(() {
          _pendingOffline = store.pending().length;
          _rejected = store.rejected().length;
          _othersPending = store.othersPending();
        });
      }
    } catch (_) {
      // penyimpanan tidak tersedia: abaikan
    }
  }

  /// Tampilkan kegiatan tersimpan seketika; server (bisa lambat/tidak terjangkau) menyusul.
  Future<void> _showSaved() async {
    try {
      final store = await _openStore();
      final saved = store.loadEvents(DateTime.now());
      if (!mounted || saved.isEmpty) return;
      setState(() {
        _events = saved;
        for (final e in saved) {
          final n = store.loadRoster(e.id)?.members.length;
          if (n != null) _ready[e.id] = n;
        }
      });
    } catch (_) {
      // penyimpanan tidak tersedia: tunggu server
    }
  }

  Future<void> _load() async {
    if (!mounted) return;
    setState(() => _loading = true);
    Object? error;
    try {
      final events = await _api.ongoingEvents();
      if (!mounted) return;
      setState(() {
        _offline = false;
        _events = events;
      });
      unawaited(_prepareOffline(events)); // di latar belakang; daftar tidak menunggu
    } on ApiException catch (e) {
      if (e.status == 401 && mounted) await _logout(expired: true);
      error = e;
    } catch (e) {
      error = e;
    }
    if (!mounted) return;
    final down = error != null && (error is! ApiException || error.unreachable);
    if (down) await _showSaved();
    if (!mounted) return;
    setState(() {
      _loading = false;
      _offline = down && (_events?.isNotEmpty ?? false);
      _error = _offline ? null : error;
      if (error != null && !down) _events = null;
    });
  }

  /// Simpan daftar kegiatan (juga yang segera dibuka) & data pesertanya selagi online, agar scan jalan walau
  /// sinyal hilang kemudian.
  Future<void> _prepareOffline(List<EventSummary> ongoing) async {
    try {
      final store = await _openStore();
      var upcoming = <EventSummary>[];
      try {
        upcoming = await _api.upcomingEvents();
      } catch (_) {
        // daftar yang akan datang bisa menyusul; yang berlangsung tetap disimpan
      }
      if (!mounted) return; // sudah keluar akun: jangan tulis ulang data yang baru dihapus
      final all = [...ongoing, ...upcoming.where((u) => !ongoing.any((o) => o.id == u.id))];
      await store.saveEvents(all);
      for (final e in all) {
        String? problem;
        try {
          final roster = Roster.fromApi(await _api.roster(e.id));
          if (!mounted) return;
          await store.saveRoster(roster);
          _ready[e.id] = roster.members.length;
        } on ApiException catch (x) {
          problem = x.message;
        } catch (_) {
          problem = 'Data peserta tidak bisa diunduh.';
        }
        if (!mounted) return;
        setState(() => problem == null ? _notReady.remove(e.id) : _notReady[e.id] = problem);
      }
    } catch (_) {
      // penyimpanan tidak tersedia: abaikan
    }
  }

  Future<OfflineStore> _openStore() => widget.offlineStore?.call() ?? OfflineStore.open(owner: widget.session.owner);

  Future<void> _refresh() async {
    _sync();
    await _load();
  }

  Future<void> _logout({bool expired = false}) async {
    String? notice;
    if (expired) {
      final r = await uploadHeldScans();
      if (r.retry) return; // token masih diperlukan untuk menitipkan scan; dicoba lagi nanti
      notice = SessionWatch.heldNotice(r.held);
    } else {
      await _api.logout();
    }
    await Session.clear();
    if (!mounted) return;
    final server = await ServerConfig.load();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(
        builder: (_) => server == null ? const ConnectScreen() : LoginScreen(server: server, notice: notice),
      ),
      (_) => false,
    );
  }

  Future<void> _confirmLogout() async {
    final ok = await showShadDialog<bool>(
      context: context,
      builder: (context) => ShadDialog(
        title: const Text('Keluar dari akun?'),
        description: const Text('Scan yang belum terkirim tetap tersimpan sampai akun ini masuk lagi.'),
        actions: [
          ShadButton.outline(onPressed: () => Navigator.of(context).pop(false), child: const Text('Batal')),
          ShadButton.destructive(onPressed: () => Navigator.of(context).pop(true), child: const Text('Keluar')),
        ],
      ),
    );
    if (ok == true) await _logout();
  }

  Future<void> _scan(EventSummary e) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => ScanScreen(session: widget.session, event: e),
      ),
    );
    if (mounted) _refresh();
  }

  /// Menu "Pindai": satu kegiatan → langsung scan; beberapa → pilih dulu.
  Future<void> _quickScan() async {
    final events = _events ?? const <EventSummary>[];
    if (events.isEmpty) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(const SnackBar(content: Text('Belum ada kegiatan yang jendela check-in-nya dibuka.')));
      return;
    }
    if (events.length == 1) return _scan(events.single);
    final picked = await showModalBottomSheet<EventSummary>(
      context: context,
      showDragHandle: true,
      builder: (sheet) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          children: [
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text('Pilih kegiatan', style: ShadTheme.of(sheet).textTheme.large),
            ),
            for (final e in events)
              ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(LucideIcons.calendarDays), title: Text(e.title), subtitle: Text(_describe(e)), onTap: () => Navigator.of(sheet).pop(e)),
          ],
        ),
      ),
    );
    if (picked != null) await _scan(picked);
  }

  /// Lokasi + sesi berjalan (multi-sesi) + jam tutup check-in sesi itu.
  String _describe(EventSummary e) {
    final current = e.sessions.where((s) => s.id == e.currentSessionId).firstOrNull;
    final closes = current?.closesAt.toLocal() ?? e.checkinClosesAt;
    return [
      if (e.location != null) e.location!,
      if (e.sessions.length > 1 && current != null) 'Sesi ${current.title}',
      if (closes != null && e.isOpenAt(DateTime.now())) 'check-in ditutup ${_hhmm(closes)}',
      if (!e.isOpenAt(DateTime.now()) && e.checkinOpensAt != null) 'check-in dibuka ${fmtDateTime(e.checkinOpensAt!)}',
    ].join(' · ');
  }

  String? _readiness(EventSummary e) {
    final n = _ready[e.id], problem = _notReady[e.id];
    if (problem != null) return n == null ? 'Belum siap offline: $problem' : 'Siap offline · $n peserta (pembaruan gagal: $problem)';
    return n == null ? null : 'Siap offline · $n peserta';
  }

  String _hhmm(DateTime t) => '${t.hour.toString().padLeft(2, '0')}.${t.minute.toString().padLeft(2, '0')}';

  String get _firstName {
    final parts = widget.session.userName.trim().split(RegExp(r'\s+'));
    return parts.isEmpty || parts.first.isEmpty ? 'Petugas' : parts.first;
  }

  void _push(Widget screen) => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => screen)).then((_) => _refresh());

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final superAdmin = widget.session.role == 'super_admin';
    return AppPage(
      title: widget.embedded ? 'Tugas' : 'Beranda',
      subtitle: _org,
      brand: true,
      maxContentWidth: kWideContentWidth,
      actions: [if (!widget.embedded) ShadIconButton.ghost(icon: const Icon(LucideIcons.logOut, size: 18), onPressed: _confirmLogout)],
      child: RefreshIndicator(
        onRefresh: _refresh,
        child: Builder(
          builder: (context) {
            final events = _events;
            final left = <Widget>[
              Text('Halo, $_firstName 👋', style: theme.textTheme.h3, maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 4),
              Text('Masuk sebagai ${_roleLabel[widget.session.role] ?? widget.session.role}', style: theme.textTheme.muted),
              const SizedBox(height: 20),
              // Menu cepat (sama dengan beranda anggota).
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: QuickAction(icon: LucideIcons.scanLine, label: 'Pindai', onTap: _quickScan),
                  ),
                  if (superAdmin)
                    Expanded(
                      child: QuickAction(
                        icon: LucideIcons.creditCard,
                        label: 'Terbitkan Kartu',
                        onTap: () => _push(IssueCardScreen(session: widget.session)),
                      ),
                    ),
                  Expanded(
                    child: QuickAction(icon: LucideIcons.wrench, label: 'Alat NFC', onTap: () => _push(const NfcToolsScreen())),
                  ),
                  Expanded(
                    child: QuickAction(
                      icon: LucideIcons.circleHelp,
                      label: 'Panduan',
                      onTap: () => _push(
                        const Scaffold(
                          body: AppPage(title: 'Panduan petugas', maxContentWidth: 640, child: OfficerHelp()),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 20),
              if (_pendingOffline > 0) ...[
                ShadAlert(
                  icon: const Icon(LucideIcons.cloudUpload),
                  title: Text('$_pendingOffline scan offline belum tersinkron'),
                  description: const Text('Tersimpan aman di HP ini dan terkirim otomatis saat ada sinyal.'),
                ),
                const SizedBox(height: 20),
              ],
              if (_othersPending > 0) ...[
                ShadAlert(
                  icon: const Icon(LucideIcons.users),
                  title: Text('$_othersPending scan milik akun lain'),
                  description: const Text('Tersimpan di HP ini dan terkirim setelah akun pemiliknya masuk lagi.'),
                ),
                const SizedBox(height: 20),
              ],
              if (_rejected > 0) ...[
                SectionCard(
                  title: '$_rejected scan ditolak server',
                  icon: LucideIcons.circleX,
                  description: 'Scan offline ini tidak tercatat sebagai hadir.',
                  child: FlexButton(
                    label: 'Lihat rincian',
                    variant: ShadButtonVariant.outline,
                    onPressed: () async {
                      final store = await _openStore();
                      if (mounted) _push(RejectedScansScreen(store: store));
                    },
                  ),
                ),
                const SizedBox(height: 20),
              ],
              if (_syncNote != null) ...[
                ShadAlert(icon: const Icon(LucideIcons.cloudCheck), title: Text(_syncNote!)),
                const SizedBox(height: 20),
              ],
            ];
            final right = <Widget>[Text('Kegiatan berlangsung', style: theme.textTheme.large), const SizedBox(height: 8)];
            if (events == null && _loading) {
              right.add(
                const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: CircularProgressIndicator()),
                ),
              );
            } else if (events == null || (_error != null && events.isEmpty)) {
              final error = _error;
              right.add(ErrorBanner(error is ApiException ? error.message : 'Server tidak terjangkau dan belum ada kegiatan tersimpan di HP ini. Buka Beranda ini sekali saat online.'));
              right.add(const SizedBox(height: 8));
              right.add(FlexButton(label: 'Coba lagi', icon: LucideIcons.refreshCw, variant: ShadButtonVariant.outline, onPressed: _refresh));
            } else if (events.isEmpty) {
              right.add(
                SectionCard(
                  title: 'Tidak ada kegiatan berlangsung',
                  icon: LucideIcons.calendarX,
                  description: 'Kegiatan muncul di sini saat jendela check-in dibuka.',
                  child: FlexButton(label: 'Muat ulang', icon: LucideIcons.refreshCw, variant: ShadButtonVariant.outline, onPressed: _refresh),
                ),
              );
            } else {
              if (_offline) {
                right.addAll(const [
                  ShadAlert(
                    icon: Icon(LucideIcons.cloudOff),
                    title: Text('Mode offline'),
                    description: Text('Menampilkan data tersimpan. Scan kartu NFC tetap bisa.'),
                  ),
                  SizedBox(height: 12),
                ]);
              }
              // HP: satu kolom. Layar lebar: kartu kegiatan berdampingan, sama tinggi.
              right.add(
                ResponsiveGrid(
                  minItemWidth: 340,
                  children: [
                    for (final e in events)
                      SectionCard(
                        title: e.title,
                        icon: LucideIcons.calendarDays,
                        description: _describe(e),
                        onTap: () => _scan(e),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          spacing: 8,
                          children: [
                            FlexButton(label: 'Mulai scan', icon: LucideIcons.scanLine, onPressed: () => _scan(e)),
                            if (_readiness(e) case final text?) Text(text, style: theme.textTheme.muted.copyWith(fontSize: 12)),
                          ],
                        ),
                      ),
                  ],
                ),
              );
            }
            // HP: satu kolom. Tablet/laptop: sapaan & menu di kiri, kegiatan di kanan.
            final children = <Widget>[ResponsiveColumns(leftFlex: 2, rightFlex: 3, left: left, right: right)];
            return ListView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), children: children);
          },
        ),
      ),
    );
  }
}

/// Panduan singkat petugas scan.
class OfficerHelp extends StatelessWidget {
  const OfficerHelp({super.key});

  static const _faq = [
    (
      'Cara memindai kartu',
      'Buka kegiatan, Mulai scan, lalu tempelkan kartu ke belakang HP. Hijau tercatat, kuning sudah tercatat, merah ditolak.',
    ),
    (
      'Kartu tertinggal',
      'Pindah ke mode QR, lalu pindai QR Saya di HP anggota. Absen dengan QR bisa dikenai denda.',
    ),
    ('Tidak ada sinyal', 'Scan disimpan di HP dan dikirim otomatis saat online.'),
    ('Kartu ditolak', 'Kartu diblokir/hilang, bukan kartu organisasi ini, atau anggota bukan peserta kegiatan. Arahkan anggota ke pengurus; jangan dicatat manual.'),
    ('Kegiatan beberapa sesi', 'Layar scan memakai sesi yang sedang dibuka; tiap sesi dicatat terpisah.'),
    ('NFC tidak membaca', 'Nyalakan NFC, lepas casing tebal, tahan kartu 1-2 detik. Uji kartu di Alat NFC.'),
  ];

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        ShadAccordion<int>(
          children: [
            for (var i = 0; i < _faq.length; i++)
              ShadAccordionItem<int>(
                value: i,
                title: Text(_faq[i].$1),
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(_faq[i].$2, style: theme.textTheme.muted),
                ),
              ),
          ],
        ),
      ],
    );
  }
}
