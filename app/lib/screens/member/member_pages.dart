import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../app/pdf.dart';
import '../../widgets/attendance_chart.dart';
import '../../widgets/ui.dart';
import '../change_password_screen.dart' show passwordProblem;
import 'excuse_screen.dart';
import 'my_events_tab.dart';
import 'profile_change_page.dart';

/// Buka halaman anggota sendiri (bilah judul + tombol kembali), bukan pindah tab.
Future<T?> pushMemberPage<T>(BuildContext context, String title, Widget child) => Navigator.of(context).push<T>(
  MaterialPageRoute(
    builder: (_) => Scaffold(
      body: AppPage(title: title, maxContentWidth: 640, child: child),
    ),
  ),
);

/// Kerangka isi halaman: tarik untuk muat ulang + daftar ber-padding.
Widget _page({required Future<void> Function() onRefresh, required List<Widget> children}) => RefreshIndicator(
  onRefresh: onRefresh,
  child: ListView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), children: children),
);

String _apiError(Object? e, String fallback) => e is ApiException ? e.message : fallback;

/// Baris label–nilai (data keanggotaan, tentang aplikasi).
class InfoRow extends StatelessWidget {
  const InfoRow(this.label, this.value, {super.key});

  final String label, value;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: theme.textTheme.muted.copyWith(fontSize: 12)),
          const SizedBox(height: 2),
          Text(value, style: theme.textTheme.p),
        ],
      ),
    );
  }
}

/// Kartu berisi beberapa baris, dengan garis pemisah.
Widget _group(BuildContext context, List<Widget> rows) {
  final theme = ShadTheme.of(context);
  return Container(
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
    decoration: BoxDecoration(
      color: theme.colorScheme.card,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: theme.colorScheme.border),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < rows.length; i++) ...[if (i > 0) Divider(height: 1, color: theme.colorScheme.border), rows[i]],
      ],
    ),
  );
}

// ───────────────────────────── Data keanggotaan ─────────────────────────────

class MembershipPage extends StatefulWidget {
  const MembershipPage({super.key, required this.api});

  final ApiClient api;

  @override
  State<MembershipPage> createState() => _MembershipPageState();
}

class _MembershipPageState extends State<MembershipPage> {
  late Future<MemberCardInfo> _card = widget.api.myCard();

  static const _status = {'active': 'Aktif', 'alumni': 'Alumni', 'inactive': 'Tidak aktif', 'applicant': 'Menunggu pengesahan'};

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<MemberCardInfo>(
      future: _card,
      builder: (context, snap) => _page(
        onRefresh: () async {
          setState(() { _card = widget.api.myCard(); });
          await _card.catchError((_) => MemberCardInfo(state: CardState.none));
        },
        children: [
          if (snap.connectionState != ConnectionState.done)
            const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (snap.hasError)
            ErrorBanner(_apiError(snap.error, 'Tidak bisa memuat data. Tarik untuk mencoba lagi.'))
          else ...[
            _group(context, [
              InfoRow('Nama lengkap', snap.data!.name ?? '—'),
              InfoRow('Nomor anggota (NIM)', snap.data!.memberNo ?? '—'),
              InfoRow('Divisi', snap.data!.division ?? '—'),
              InfoRow('Angkatan', snap.data!.cohort ?? '—'),
            ]),
            const SizedBox(height: 12),
            _group(context, [
              InfoRow('Organisasi', snap.data!.organization ?? '—'),
              InfoRow('Periode', snap.data!.term ?? '—'),
              InfoRow('Status keanggotaan', _status[snap.data!.status] ?? (snap.data!.state == CardState.pending ? 'Menunggu pengesahan' : '—')),
              InfoRow('Berlaku sampai', snap.data!.validUntil == null ? '—' : fmtDate(snap.data!.validUntil!)),
              InfoRow('Kartu NFC', snap.data!.cardLast4 == null ? 'Belum terdaftar' : 'Aktif (…${snap.data!.cardLast4})'),
            ]),
            const SizedBox(height: 12),
            Text('Ada data yang keliru? Ajukan perubahan nama, no. HP, atau foto — berlaku setelah disetujui pengurus.', style: theme.textTheme.muted),
            const SizedBox(height: 12),
            FlexButton(
              label: 'Ajukan perubahan data',
              icon: LucideIcons.userPen,
              variant: ShadButtonVariant.outline,
              onPressed: () => pushMemberPage<void>(context, 'Ubah data diri', ProfileChangePage(api: widget.api)),
            ),
          ],
        ],
      ),
    );
  }
}

// ───────────────────────────── Rapor kehadiran ─────────────────────────────

class ReportPage extends StatefulWidget {
  const ReportPage({super.key, required this.api, this.openPdf});

  final ApiClient api;
  final Future<String?> Function(List<int> bytes)? openPdf;

  @override
  State<ReportPage> createState() => _ReportPageState();
}

class _ReportPageState extends State<ReportPage> {
  late Future<MemberHistory> _history = widget.api.myHistory();
  bool _downloading = false;
  String? _error;

  Future<void> _download() async {
    setState(() {
      _downloading = true;
      _error = null;
    });
    try {
      final bytes = await widget.api.downloadBytes('${widget.api.baseUrl}/api/me/report-card');
      final err = await (widget.openPdf ?? (b) => saveAndOpenPdf(b, fileName: 'rapor-kehadiran.pdf'))(bytes);
      if (mounted) setState(() => _error = err);
    } catch (e) {
      if (mounted) setState(() => _error = _apiError(e, 'Gagal mengunduh rapor. Periksa koneksi.'));
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<MemberHistory>(
      future: _history,
      builder: (context, snap) {
        final h = snap.data;
        return _page(
          onRefresh: () async {
            setState(() { _history = widget.api.myHistory(); });
            await _history.catchError((_) => const MemberHistory(byStatus: {}, total: 0));
          },
          children: [
            if (snap.connectionState != ConnectionState.done)
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (snap.hasError)
              ErrorBanner(_apiError(snap.error, 'Tidak bisa memuat rapor. Tarik untuk mencoba lagi.'))
            else ...[
              Text('Periode ${h!.term ?? 'berjalan'}', style: theme.textTheme.muted),
              const SizedBox(height: 4),
              Text(h.rate == null ? '–' : '${h.rate!.toStringAsFixed(h.rate! % 1 == 0 ? 0 : 1)}%', style: theme.textTheme.h1),
              Text('tingkat kehadiran dari ${h.total} kegiatan', style: theme.textTheme.muted),
              const SizedBox(height: 16),
              _group(context, [
                for (final s in ['hadir', 'telat', 'izin', 'sakit', 'alpa'])
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Row(
                      children: [
                        Expanded(child: Text(statusLabel[s]!, style: theme.textTheme.p)),
                        Text('${h.byStatus[s] ?? 0}', style: theme.textTheme.large),
                      ],
                    ),
                  ),
              ]),
              if (h.monthly.isNotEmpty) ...[
                const SizedBox(height: 16),
                SectionCard(title: 'Kehadiran per bulan', icon: LucideIcons.chartColumn, child: MonthlyAttendanceChart(months: h.monthly)),
              ],
              const SizedBox(height: 16),
              FlexButton(label: _downloading ? 'Mengunduh rapor…' : 'Unduh rapor PDF', icon: LucideIcons.fileDown, enabled: !_downloading, onPressed: _download),
              if (_error != null) ...[const SizedBox(height: 8), ErrorBanner(_error!)],
              const SizedBox(height: 8),
              Text('Rapor resmi bertanda organisasi, bisa dicetak atau dikirim sebagai bukti keaktifan.', style: theme.textTheme.muted, textAlign: TextAlign.center),
            ],
          ],
        );
      },
    );
  }
}

// ───────────────────────────── Denda ─────────────────────────────

class FinesPage extends StatefulWidget {
  const FinesPage({super.key, required this.api});

  final ApiClient api;

  @override
  State<FinesPage> createState() => _FinesPageState();
}

class _FinesPageState extends State<FinesPage> {
  late Future<MemberHistory> _history = widget.api.myHistory();

  static const _label = {'unpaid': 'Belum lunas', 'paid': 'Lunas', 'waived': 'Dibebaskan', 'free': 'Gratis'};

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<MemberHistory>(
      future: _history,
      builder: (context, snap) => _page(
        onRefresh: () async {
          setState(() { _history = widget.api.myHistory(); });
          await _history.catchError((_) => const MemberHistory(byStatus: {}, total: 0));
        },
        children: [
          if (snap.connectionState != ConnectionState.done)
            const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (snap.hasError)
            ErrorBanner(_apiError(snap.error, 'Tidak bisa memuat denda. Tarik untuk mencoba lagi.'))
          else ...[
            Text('Belum lunas', style: theme.textTheme.muted),
            Text(rupiah(snap.data!.finesUnpaid), style: theme.textTheme.h1),
            const SizedBox(height: 4),
            Text('Denda dikenakan saat absen memakai QR karena kartu tidak dibawa. Bayar ke pengurus; status diperbarui oleh pengurus.', style: theme.textTheme.muted),
            const SizedBox(height: 16),
            if (snap.data!.fines.isEmpty)
              const ShadAlert(icon: Icon(LucideIcons.circleCheck), title: Text('Tidak ada denda'), description: Text('Terus bawa kartu anggota saat kegiatan.'))
            else
              _group(context, [
                for (final f in snap.data!.fines)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(f.event, style: theme.textTheme.p, maxLines: 2, overflow: TextOverflow.ellipsis),
                              if (f.date != null) Text(fmtDate(f.date!), style: theme.textTheme.muted),
                            ],
                          ),
                        ),
                        const SizedBox(width: 8),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            Text(rupiah(f.amount), style: theme.textTheme.small.copyWith(fontWeight: FontWeight.w600)),
                            const SizedBox(height: 4),
                            ShadBadge.secondary(child: Text(_label[f.status] ?? f.status)),
                          ],
                        ),
                      ],
                    ),
                  ),
              ]),
          ],
        ],
      ),
    );
  }
}

// ───────────────────────────── Ajukan izin ─────────────────────────────

/// Daftar kegiatan yang masih bisa diajukan izin/sakit, lalu formulir izin.
class ExcuseListPage extends StatefulWidget {
  const ExcuseListPage({super.key, required this.api});

  final ApiClient api;

  @override
  State<ExcuseListPage> createState() => _ExcuseListPageState();
}

class _ExcuseListPageState extends State<ExcuseListPage> {
  late Future<List<MemberEvent>> _events = widget.api.myEvents();

  Future<void> _refresh() async {
    setState(() { _events = widget.api.myEvents(); });
    await _events.catchError((_) => <MemberEvent>[]);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<List<MemberEvent>>(
      future: _events,
      builder: (context, snap) {
        final open = [...?snap.data?.where((e) => e.canRequestExcuse)];
        final done = [...?snap.data?.where((e) => !e.canRequestExcuse && e.excuseStatus != null)];
        return _page(
          onRefresh: _refresh,
          children: [
            if (snap.connectionState != ConnectionState.done)
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (snap.hasError)
              ErrorBanner(_apiError(snap.error, 'Tidak bisa memuat kegiatan. Tarik untuk mencoba lagi.'))
            else ...[
              Text('Berhalangan hadir? Ajukan izin atau sakit sebelum kegiatan ditutup, sertakan bukti bila ada.', style: theme.textTheme.muted),
              const SizedBox(height: 12),
              if (open.isEmpty) const ShadAlert(icon: Icon(LucideIcons.calendarCheck), title: Text('Tidak ada kegiatan yang bisa diajukan izin')),
              for (final e in open)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: ShadCard(
                    title: Text(e.title),
                    description: Text([fmtDateTime(e.startsAt), if (e.location != null) e.location!].join(' · ')),
                    child: Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Row(
                        children: [
                          if (e.excuseStatus != null) ShadBadge.secondary(child: Text(MyEventsTabStatus.of(e))),
                          const Spacer(),
                          ShadButton(
                            size: ShadButtonSize.sm,
                            onPressed: () async {
                              final sent = await Navigator.of(context).push<bool>(
                                MaterialPageRoute(
                                  builder: (_) => ExcuseScreen(api: widget.api, event: e),
                                ),
                              );
                              if (sent == true) _refresh();
                            },
                            child: Text(e.excuseStatus == 'pending' ? 'Ubah izin' : 'Ajukan izin'),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              if (done.isNotEmpty) ...[
                const SizedBox(height: 8),
                Text('Riwayat pengajuan', style: theme.textTheme.large),
                const SizedBox(height: 6),
                for (final e in done)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: ShadCard(
                      padding: const EdgeInsets.all(12),
                      title: Text(e.title),
                      description: Text([fmtDate(e.startsAt), if (e.excuseNote != null) e.excuseNote!].join(' · ')),
                      trailing: ShadBadge.outline(child: Text(MyEventsTabStatus.of(e))),
                    ),
                  ),
              ],
            ],
          ],
        );
      },
    );
  }
}

// ───────────────────────────── Ganti password ─────────────────────────────

class ChangeOwnPasswordPage extends StatefulWidget {
  const ChangeOwnPasswordPage({super.key, required this.api});

  final ApiClient api;

  @override
  State<ChangeOwnPasswordPage> createState() => _ChangeOwnPasswordPageState();
}

class _ChangeOwnPasswordPageState extends State<ChangeOwnPasswordPage> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _repeat = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _repeat.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final problem = _current.text.isEmpty ? 'Isi password saat ini.' : passwordProblem(_next.text, _repeat.text);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.api.changePassword(_current.text, _next.text);
      if (!mounted) return;
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(const SnackBar(content: Text('Password berhasil diganti.')));
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) setState(() => _error = _apiError(e, 'Server tidak terjangkau.'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final fields = <Widget>[
      Text('Minimal 10 karakter, berisi huruf dan angka. Jangan pakai password yang sama dengan akun lain.', style: theme.textTheme.muted),
      const SizedBox(height: 16),
      if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
      ShadInputFormField(controller: _current, label: const Text('Password saat ini'), obscureText: true, autofillHints: const [AutofillHints.password]),
      const SizedBox(height: 12),
      ShadInputFormField(controller: _next, label: const Text('Password baru'), obscureText: true, autofillHints: const [AutofillHints.newPassword]),
      const SizedBox(height: 12),
      ShadInputFormField(controller: _repeat, label: const Text('Ulangi password baru'), obscureText: true, autofillHints: const [AutofillHints.newPassword], onSubmitted: (_) => _save()),
      const SizedBox(height: 20),
      FlexButton(label: _busy ? 'Menyimpan…' : 'Simpan password', icon: LucideIcons.keyRound, enabled: !_busy, onPressed: _save),
    ];
    return ListView(padding: const EdgeInsets.all(16), children: fields);
  }
}

// ───────────────────────────── Panduan ─────────────────────────────

class HelpPage extends StatelessWidget {
  const HelpPage({super.key});

  static const _faq = [
    ('Bagaimana cara absen?', 'Tempelkan kartu anggota NFC ke HP petugas scan saat kegiatan. Status Hadir/Telat dihitung dari jam scan.'),
    (
      'Kartu tertinggal?',
      'Buka Beranda → ketuk kartu atau menu QR Saya, lalu tunjukkan QR ke petugas. QR berganti tiap menit, jadi tangkapan layar tidak berlaku. Absen dengan QR bisa dikenai denda sesuai kebijakan organisasi.',
    ),
    ('Berhalangan hadir?', 'Menu Izin → pilih kegiatan → ajukan izin/sakit beserta bukti. Pengurus menyetujui atau menolak; hasilnya tampil di sini.'),
    ('Kartu hilang atau rusak?', 'Menu Kartu → Lapor kartu. Kartu hilang langsung diblokir agar tidak disalahgunakan; pengurus menindaklanjuti penggantinya.'),
    ('HP tanpa NFC?', 'Tidak masalah — absen tetap bisa dengan kartu fisik ditempel ke HP petugas. Untuk mendaftarkan kartu sendiri, minta pengurus lewat menu Kartu.'),
    ('Kehadiran saya rendah?', 'Lihat menu Rapor. Bila tercatat Alpa padahal hadir/izin, hubungi pengurus untuk koreksi.'),
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
        const SizedBox(height: 16),
        Text('Masih ada pertanyaan? Hubungi pengurus organisasi Anda.', style: theme.textTheme.muted, textAlign: TextAlign.center),
      ],
    );
  }
}

// ───────────────────────────── Tentang ─────────────────────────────

class AboutPage extends StatelessWidget {
  const AboutPage({super.key, required this.server});

  final String server;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Center(child: Image.asset('assets/brand/logo-2d.webp', width: 72, height: 72)),
        const SizedBox(height: 12),
        Center(child: Text('Kartu Anggota', style: theme.textTheme.h3)),
        Center(
          child: Text('Absensi kegiatan dengan kartu anggota NFC', style: theme.textTheme.muted, textAlign: TextAlign.center),
        ),
        const SizedBox(height: 20),
        _group(context, [InfoRow('Server', server), const InfoRow('Lisensi', 'GNU GPL versi 3 atau lebih baru'), const InfoRow('Hak cipta', '© 2026 Ranupane Rimba')]),
        const SizedBox(height: 12),
        ShadButton.outline(
          onPressed: () => showLicensePage(context: context, applicationName: 'Kartu Anggota', applicationLegalese: '© 2026 Ranupane Rimba · GPL-3.0-or-later'),
          child: const Text('Lisensi pustaka pihak ketiga'),
        ),
      ],
    );
  }
}

// ───────────────────────────── Pengumuman ─────────────────────────────

class AnnouncementPage extends StatelessWidget {
  const AnnouncementPage({super.key, required this.title, required this.body, required this.at, this.division});

  final String title, body;
  final DateTime at;
  final String? division;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(title, style: theme.textTheme.h4),
        const SizedBox(height: 4),
        Text([fmtDate(at), if (division != null) 'Divisi $division'].join(' · '), style: theme.textTheme.muted),
        const SizedBox(height: 16),
        SelectableText(body, style: theme.textTheme.p),
      ],
    );
  }
}
