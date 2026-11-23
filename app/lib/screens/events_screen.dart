import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../app/server_config.dart';
import '../app/session.dart';
import '../app/session_watch.dart';
import '../offline/offline_store.dart';
import '../widgets/ui.dart';
import 'connect_screen.dart';
import 'issue_card_screen.dart';
import 'login_screen.dart';
import 'nfc_tools_screen.dart';
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
  late Future<List<EventSummary>> _events = _load();
  String? _org;
  int _pendingOffline = 0;

  @override
  void initState() {
    super.initState();
    ServerConfig.load().then((s) {
      if (mounted && s?.name != null) setState(() => _org = s!.name);
    }, onError: (_) {});
    _countOffline();
  }

  Future<void> _countOffline() async {
    try {
      final store = await (widget.offlineStore ?? OfflineStore.open)();
      if (mounted) setState(() => _pendingOffline = store.pending().length);
    } catch (_) {
      // penyimpanan tidak tersedia: abaikan
    }
  }

  Future<List<EventSummary>> _load() async {
    try {
      return await _api.ongoingEvents();
    } on ApiException catch (e) {
      if (e.status == 401 && mounted) await _logout(expired: true);
      rethrow;
    }
  }

  Future<void> _refresh() async {
    setState(() => _events = _load());
    _countOffline();
    await _events.catchError((_) => <EventSummary>[]);
  }

  Future<void> _logout({bool expired = false}) async {
    if (!expired) await _api.logout();
    await Session.clear();
    if (!mounted) return;
    final server = await ServerConfig.load();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute<void>(builder: (_) => server == null ? const ConnectScreen() : LoginScreen(server: server)), (_) => false);
  }

  Future<void> _confirmLogout() async {
    final ok = await showShadDialog<bool>(
      context: context,
      builder: (context) => ShadDialog(
        title: const Text('Keluar dari akun?'),
        description: const Text('Scan offline yang belum tersinkron tetap tersimpan di HP ini.'),
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
    final events = await _events.catchError((_) => <EventSummary>[]);
    if (!mounted) return;
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
    return [if (e.location != null) e.location!, if (e.sessions.length > 1 && current != null) 'Sesi ${current.title}', if (closes != null) 'check-in ditutup ${_hhmm(closes)}'].join(' · ');
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
        child: FutureBuilder<List<EventSummary>>(
          future: _events,
          builder: (context, snap) {
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
                    child: _Action(icon: LucideIcons.scanLine, label: 'Pindai', onTap: _quickScan),
                  ),
                  if (superAdmin)
                    Expanded(
                      child: _Action(
                        icon: LucideIcons.creditCard,
                        label: 'Terbitkan Kartu',
                        onTap: () => _push(IssueCardScreen(session: widget.session)),
                      ),
                    ),
                  Expanded(
                    child: _Action(icon: LucideIcons.wrench, label: 'Alat NFC', onTap: () => _push(const NfcToolsScreen())),
                  ),
                  Expanded(
                    child: _Action(
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
                  description: const Text('Tersimpan aman di HP ini. Buka kegiatannya saat ada sinyal agar terkirim ke server.'),
                ),
                const SizedBox(height: 20),
              ],
            ];
            final right = <Widget>[Text('Kegiatan berlangsung', style: theme.textTheme.large), const SizedBox(height: 8)];
            if (snap.connectionState != ConnectionState.done) {
              right.add(
                const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: CircularProgressIndicator()),
                ),
              );
            } else if (snap.hasError) {
              right.add(ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Server tidak terjangkau. Tarik ke bawah untuk mencoba lagi.'));
            } else if (snap.data!.isEmpty) {
              right.add(
                SectionCard(
                  title: 'Tidak ada kegiatan berlangsung',
                  icon: LucideIcons.calendarX,
                  description: 'Kegiatan muncul di sini saat jendela check-in dibuka.',
                  child: FlexButton(label: 'Muat ulang', icon: LucideIcons.refreshCw, variant: ShadButtonVariant.outline, onPressed: _refresh),
                ),
              );
            } else {
              // HP: satu kolom. Layar lebar: kartu kegiatan berdampingan, sama tinggi.
              right.add(
                ResponsiveGrid(
                  minItemWidth: 340,
                  children: [
                    for (final e in snap.data!)
                      SectionCard(
                        title: e.title,
                        icon: LucideIcons.calendarDays,
                        description: _describe(e),
                        onTap: () => _scan(e),
                        child: FlexButton(label: 'Mulai scan', icon: LucideIcons.scanLine, onPressed: () => _scan(e)),
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
      'Buka kegiatan → Mulai scan → minta anggota menempelkan kartu ke bagian belakang HP (dekat kamera). Bunyi & getar menandai hasil: hijau tercatat, kuning sudah tercatat, merah ditolak.',
    ),
    (
      'Kartu tertinggal',
      'Pindah ke mode QR di layar scan, lalu pindai QR Saya di HP anggota. QR berganti tiap menit, jadi tangkapan layar ditolak. Absen dengan QR bisa dikenai denda sesuai kebijakan.',
    ),
    ('Tidak ada sinyal', 'Scan tetap jalan: hasil disimpan di HP (mode offline) dan dikirim otomatis saat online. Jumlah yang belum terkirim tampil di Beranda.'),
    ('Kartu ditolak', 'Kartu diblokir/hilang, bukan kartu organisasi ini, atau anggota bukan peserta kegiatan. Arahkan anggota ke pengurus; jangan dicatat manual.'),
    ('Kegiatan beberapa sesi', 'Layar scan memakai sesi yang sedang dibuka; tiap sesi dicatat terpisah.'),
    ('NFC tidak membaca', 'Pastikan NFC HP menyala, lepas casing tebal, dan tahan kartu 1–2 detik. Gunakan Alat NFC untuk menguji kartu.'),
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

/// Tombol menu cepat (gaya sama dengan menu cepat beranda anggota): ikon dalam kotak indigo + label.
// TODO(ui): satukan dengan QuickAction bersama setelah PR menu Kartu (#59) masuk.
class _Action extends StatelessWidget {
  const _Action({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final accent = theme.brightness == Brightness.dark ? const Color(0xFFA5B4FC) : const Color(0xFF4F46E5);
    return Semantics(
      button: true,
      label: label,
      excludeSemantics: true,
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
          child: Column(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(color: accent.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(14)),
                child: Icon(icon, size: 24, color: accent),
              ),
              const SizedBox(height: 6),
              Text(label, style: theme.textTheme.small, maxLines: 2, overflow: TextOverflow.ellipsis, textAlign: TextAlign.center),
            ],
          ),
        ),
      ),
    );
  }
}
