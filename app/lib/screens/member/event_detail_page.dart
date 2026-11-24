import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';
import 'excuse_screen.dart';
import 'my_events_tab.dart';

/// Tahap check-in sebuah kegiatan pada waktu [now].
enum CheckinPhase { notYet, onTime, late, closed }

CheckinPhase checkinPhase(MemberEvent e, DateTime now) {
  if (e.closed || now.isAfter(e.checkinClosesAt)) return CheckinPhase.closed;
  final opens = e.checkinOpensAt ?? e.startsAt;
  if (now.isBefore(opens)) return CheckinPhase.notYet;
  final late = e.lateAfter;
  if (late != null && now.isAfter(late)) return CheckinPhase.late;
  return CheckinPhase.onTime;
}

/// Detail kegiatan anggota: status, jadwal & garis waktu check-in, sesi, izin, dan langganan kalender.
class EventDetailPage extends StatefulWidget {
  const EventDetailPage({super.key, required this.api, required this.event, this.now});

  final ApiClient api;
  final MemberEvent event;

  /// Untuk tes.
  final DateTime? now;

  @override
  State<EventDetailPage> createState() => _EventDetailPageState();
}

class _EventDetailPageState extends State<EventDetailPage> {
  late MemberEvent _e = widget.event;

  /// Muat ulang kegiatan ini setelah mengajukan izin.
  Future<void> _reload() async {
    try {
      final list = await widget.api.myEvents();
      final fresh = list.where((x) => x.id == _e.id).firstOrNull;
      if (fresh != null && mounted) setState(() => _e = fresh);
    } catch (_) {
      // tetap tampilkan data lama
    }
  }

  bool _answering = false;
  String? _rsvpError;

  Future<void> _answer(bool going) async {
    setState(() {
      _answering = true;
      _rsvpError = null;
    });
    String? error;
    try {
      await widget.api.rsvp(_e.id, going: going);
    } on ApiException catch (e) {
      error = e.message;
    } catch (_) {
      error = 'Server tidak terjangkau. Jawaban belum terkirim.';
    }
    await _reload(); // juga saat gagal: mis. kuota/batas waktu baru berubah
    if (!mounted) return;
    setState(() {
      _answering = false;
      _rsvpError = error;
    });
  }

  Widget _rsvpCard(MemberRsvp r) => SectionCard(
    title: 'RSVP',
    icon: LucideIcons.users,
    description: [r.quota == null ? '${r.going} anggota ikut' : '${r.going} dari ${r.quota} tempat terisi', if (r.open) 'dijawab sampai ${fmtDateTime(r.deadline)}'].join(' · '),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      spacing: 8,
      children: [
        Text(r.label, style: ShadTheme.of(context).textTheme.p),
        if (_rsvpError != null) ErrorBanner(_rsvpError!),
        if (r.open && !r.joined) FlexButton(label: r.full ? 'Masuk daftar tunggu' : 'Ikut', icon: LucideIcons.check, enabled: !_answering, onPressed: () => _answer(true)),
        if (r.open && r.status != 'declined')
          FlexButton(label: r.joined ? 'Batal ikut' : 'Tidak ikut', icon: LucideIcons.x, variant: ShadButtonVariant.outline, enabled: !_answering, onPressed: () => _answer(false)),
      ],
    ),
  );

  Future<void> _excuse() async {
    final sent = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => ExcuseScreen(api: widget.api, event: _e),
      ),
    );
    if (sent == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final e = _e;
    final now = widget.now ?? DateTime.now();
    final phase = checkinPhase(e, now);
    return RefreshIndicator(
      onRefresh: _reload,
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          Text(e.title, style: theme.textTheme.h3),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              StatusBadge(status: e.myStatus ?? e.excuseStatus, label: MyEventsTabStatus.of(e)),
              if (!e.mandatory) const StatusBadge(status: null, label: 'Tidak wajib'),
              if (e.sessions > 1) StatusBadge(status: null, label: '${e.sessions} sesi'),
            ],
          ),
          const SizedBox(height: 16),
          SectionCard(
            title: 'Jadwal',
            icon: LucideIcons.calendarDays,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [_KeyValue('Mulai', fmtDateTime(e.startsAt)), if (e.endsAt != null) _KeyValue('Selesai', fmtDateTime(e.endsAt!)), if (e.location != null) _KeyValue('Lokasi', e.location!)],
            ),
          ),
          if (e.rsvp != null) ...[const SizedBox(height: 16), _rsvpCard(e.rsvp!)],
          const SizedBox(height: 16),
          SectionCard(
            title: 'Check-in',
            icon: LucideIcons.clock,
            description: switch (phase) {
              CheckinPhase.notYet => 'Belum dibuka.',
              CheckinPhase.onTime => 'Sedang dibuka — datang sekarang tercatat Hadir.',
              CheckinPhase.late => 'Sedang dibuka — datang sekarang tercatat Telat.',
              CheckinPhase.closed => 'Sudah ditutup.',
            },
            child: _Timeline(
              steps: [
                (label: 'Dibuka', time: e.checkinOpensAt ?? e.startsAt, active: phase == CheckinPhase.onTime),
                if (e.lateAfter != null) (label: 'Telat setelah', time: e.lateAfter!, active: phase == CheckinPhase.late),
                (label: 'Ditutup', time: e.checkinClosesAt, active: phase == CheckinPhase.closed),
              ],
            ),
          ),
          if (e.sessions > 1) ...[
            const SizedBox(height: 16),
            SectionCard(
              title: 'Kehadiran per sesi',
              icon: LucideIcons.listChecks,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('Hadir ${e.presentSessions} dari ${e.sessions} sesi', style: theme.textTheme.p),
                  const SizedBox(height: 8),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(value: e.presentSessions / e.sessions, minHeight: 8, backgroundColor: theme.colorScheme.muted, color: theme.colorScheme.primary),
                  ),
                ],
              ),
            ),
          ],
          if (e.excuseStatus != null) ...[
            const SizedBox(height: 16),
            SectionCard(
              title: e.excuseType == 'sakit' ? 'Pengajuan sakit' : 'Pengajuan izin',
              icon: LucideIcons.calendarCheck,
              trailing: StatusBadge(status: e.excuseStatus, label: statusLabel[e.excuseStatus] ?? e.excuseStatus!),
              child: Text(e.excuseNote == null || e.excuseNote!.isEmpty ? 'Belum ada catatan pengurus.' : 'Catatan pengurus: ${e.excuseNote}', style: theme.textTheme.muted),
            ),
          ],
          const SizedBox(height: 20),
          if (e.canRequestExcuse) ...[
            FlexButton(label: e.excuseStatus == 'pending' ? 'Ubah izin' : 'Ajukan izin / sakit', icon: LucideIcons.calendarPlus, onPressed: _excuse),
            const SizedBox(height: 8),
          ],
          FlexButton(label: 'Masukkan jadwal ke kalender HP', icon: LucideIcons.calendarSync, variant: ShadButtonVariant.outline, onPressed: () => showCalendarSubscription(context, widget.api)),
          if (!e.closed) ...[const SizedBox(height: 12), Text('Bawa kartu anggota. Lupa kartu? Tunjukkan QR Saya ke petugas.', style: theme.textTheme.muted, textAlign: TextAlign.center)],
        ],
      ),
    );
  }
}

/// Garis waktu vertikal sederhana: titik + label + jam; tahap yang sedang berlaku ditebalkan.
class _Timeline extends StatelessWidget {
  const _Timeline({required this.steps});

  final List<({String label, DateTime time, bool active})> steps;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Column(
      children: [
        for (var i = 0; i < steps.length; i++)
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  width: 16,
                  child: Column(
                    children: [
                      Container(
                        margin: const EdgeInsets.only(top: 4),
                        width: 10,
                        height: 10,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: steps[i].active ? theme.colorScheme.primary : theme.colorScheme.background,
                          border: Border.all(color: steps[i].active ? theme.colorScheme.primary : theme.colorScheme.mutedForeground, width: 2),
                        ),
                      ),
                      if (i < steps.length - 1) Expanded(child: Container(width: 2, color: theme.colorScheme.border)),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Padding(
                    padding: EdgeInsets.only(bottom: i < steps.length - 1 ? 14 : 0),
                    // Label kiri, jam rata kanan; turun baris bila sempit/huruf besar.
                    child: Wrap(
                      alignment: WrapAlignment.spaceBetween,
                      spacing: 8,
                      children: [
                        Text(steps[i].label, style: theme.textTheme.small.copyWith(fontWeight: steps[i].active ? FontWeight.w700 : FontWeight.w400)),
                        Text(fmtDateTime(steps[i].time), style: theme.textTheme.muted.copyWith(fontSize: 12, fontFeatures: const [FontFeature.tabularFigures()])),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

/// Baris ringkas: label redup di kiri, nilai rata kanan (turun baris bila sempit).
class _KeyValue extends StatelessWidget {
  const _KeyValue(this.label, this.value);

  final String label, value;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Wrap(
        alignment: WrapAlignment.spaceBetween,
        spacing: 12,
        children: [
          Text(label, style: theme.textTheme.muted),
          Text(value, style: theme.textTheme.small, textAlign: TextAlign.right),
        ],
      ),
    );
  }
}
