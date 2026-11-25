import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';
import 'package:nfc_manager/nfc_manager.dart' show NfcAvailability;
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../nfc/nfc_service.dart';
import '../../widgets/ui.dart';

const _reasonLabel = {'hilang': 'Kartu hilang', 'rusak': 'Kartu rusak', 'tanpa_nfc': 'HP tanpa NFC'};
const _cardStatus = {'active': 'Aktif', 'lost': 'Hilang', 'blocked': 'Diblokir', 'unassigned': 'Belum dipakai'};

String _issuedBy(String? by) => switch (by) {
  'self' => 'Didaftarkan sendiri',
  'admin' => 'Diterbitkan pengurus',
  _ => '-',
};

/// Baris label kiri, nilai rata kanan (turun baris bila sempit).
class _Row extends StatelessWidget {
  const _Row(this.label, this.value);

  final String label, value;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
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

// ───────────────────────────── Detail kartu ─────────────────────────────

class CardDetailPage extends StatelessWidget {
  const CardDetailPage({super.key, required this.info});

  final MemberCardInfo info;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final status = switch (info.state) {
      CardState.active => info.cardLast4 == null ? 'Aktif (kartu NFC belum terdaftar)' : 'Aktif',
      CardState.pending => 'Menunggu pengesahan',
      CardState.inactive => info.status == 'alumni' ? 'Alumni' : 'Tidak aktif',
      CardState.none => 'Belum terdaftar',
    };
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        SectionCard(
          title: 'Kartu anggota',
          icon: LucideIcons.idCard,
          child: Column(
            children: [
              _Row('Status', status),
              _Row('Nama', info.name ?? '-'),
              _Row('Nomor anggota', info.memberNo ?? '-'),
              _Row('Divisi', info.division ?? '-'),
              _Row('Periode', info.term ?? '-'),
              _Row('Berlaku sampai', info.validUntil == null ? '-' : fmtDate(info.validUntil!)),
            ],
          ),
        ),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Kartu NFC',
          icon: LucideIcons.nfc,
          child: Column(
            children: [
              _Row('Nomor kartu', info.cardLast4 == null ? 'Belum terdaftar' : '…${info.cardLast4}'),
              _Row('Diterbitkan', info.cardIssuedAt == null ? '-' : fmtDate(info.cardIssuedAt!)),
              _Row('Cara terbit', info.cardLast4 == null ? '-' : _issuedBy(info.cardIssuedBy)),
              _Row('Jatah daftar sendiri', info.selfWritesLeft > 0 ? '${info.selfWritesLeft}× lagi' : 'Habis, lewat pengurus'),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Text('Ada data yang keliru? Hubungi pengurus.', style: theme.textTheme.muted),
      ],
    );
  }
}

// ───────────────────────────── Riwayat kartu ─────────────────────────────

class CardHistoryPage extends StatefulWidget {
  const CardHistoryPage({super.key, required this.api});

  final ApiClient api;

  @override
  State<CardHistoryPage> createState() => _CardHistoryPageState();
}

class _CardHistoryPageState extends State<CardHistoryPage> {
  late Future<CardHistory> _history = widget.api.myCardHistory();

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<CardHistory>(
      future: _history,
      builder: (context, snap) {
        final h = snap.data;
        return RefreshIndicator(
          onRefresh: () async {
            setState(() {
              _history = widget.api.myCardHistory();
            });
            await _history.catchError((_) => const CardHistory());
          },
          child: ListView(
            padding: const EdgeInsets.all(16),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              if (snap.connectionState != ConnectionState.done)
                const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: CircularProgressIndicator()),
                )
              else if (snap.hasError)
                ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat riwayat kartu. Tarik untuk mencoba lagi.')
              else ...[
                SectionCard(
                  title: 'Kartu NFC',
                  icon: LucideIcons.creditCard,
                  child: h!.cards.isEmpty
                      ? Text('Belum ada kartu NFC.', style: theme.textTheme.muted)
                      : Column(
                          children: [
                            for (var i = 0; i < h.cards.length; i++) ...[
                              if (i > 0) Divider(height: 1, color: theme.colorScheme.border),
                              _item(
                                context,
                                title: 'Kartu …${h.cards[i].last4}',
                                subtitle: [
                                  if (h.cards[i].issuedAt != null) 'Terbit ${fmtDate(h.cards[i].issuedAt!)}',
                                  if (h.cards[i].issuedBy != null) _issuedBy(h.cards[i].issuedBy).toLowerCase(),
                                  if (h.cards[i].revokedAt != null) 'nonaktif ${fmtDate(h.cards[i].revokedAt!)}',
                                ].join(' · '),
                                badge: StatusBadge(status: h.cards[i].status == 'active' ? 'hadir' : 'alpa', label: _cardStatus[h.cards[i].status] ?? h.cards[i].status),
                              ),
                            ],
                          ],
                        ),
                ),
                const SizedBox(height: 16),
                SectionCard(
                  title: 'Laporan kartu',
                  icon: LucideIcons.triangleAlert,
                  child: h.requests.isEmpty
                      ? Text('Belum pernah melapor kartu.', style: theme.textTheme.muted)
                      : Column(
                          children: [
                            for (var i = 0; i < h.requests.length; i++) ...[
                              if (i > 0) Divider(height: 1, color: theme.colorScheme.border),
                              _item(
                                context,
                                title: _reasonLabel[h.requests[i].reason] ?? h.requests[i].reason,
                                subtitle: [
                                  if (h.requests[i].createdAt != null) fmtDate(h.requests[i].createdAt!),
                                  if (h.requests[i].status == 'approved' && h.requests[i].resolution == 'admin') 'kartu diterbitkan pengurus',
                                  if (h.requests[i].status == 'approved' && h.requests[i].resolution == 'self') 'boleh daftar kartu sendiri',
                                  if (h.requests[i].reviewNote != null && h.requests[i].reviewNote!.isNotEmpty) 'Catatan: ${h.requests[i].reviewNote}',
                                ].join(' · '),
                                badge: StatusBadge(status: h.requests[i].status, label: statusLabel[h.requests[i].status] ?? h.requests[i].status),
                              ),
                            ],
                          ],
                        ),
                ),
              ],
            ],
          ),
        );
      },
    );
  }

  Widget _item(BuildContext context, {required String title, required String subtitle, required Widget badge}) {
    final theme = ShadTheme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: theme.textTheme.small.copyWith(fontWeight: FontWeight.w600)),
                if (subtitle.isNotEmpty) Text(subtitle, style: theme.textTheme.muted.copyWith(fontSize: 12)),
              ],
            ),
          ),
          const SizedBox(width: 8),
          badge,
        ],
      ),
    );
  }
}

// ───────────────────────────── Cek kartu NFC ─────────────────────────────

/// Tempel kartu fisik ke HP: dibaca lewat NFC lalu diverifikasi server (asli? aktif? milik sendiri?).
class CardCheckPage extends StatefulWidget {
  const CardCheckPage({super.key, required this.api, this.readCard});

  final ApiClient api;

  /// Untuk tes: baca teks kartu (bawaan: NFC HP).
  final Future<String?> Function()? readCard;

  @override
  State<CardCheckPage> createState() => _CardCheckPageState();
}

class _CardCheckPageState extends State<CardCheckPage> {
  NfcService? _nfc;
  bool _reading = false;
  String? _result;
  String? _error;

  @override
  void initState() {
    super.initState();
    _check();
  }

  @override
  void dispose() {
    _nfc?.stop().catchError((_) {});
    super.dispose();
  }

  Future<String?> _readNfc() async {
    _nfc ??= NfcService();
    if (await _nfc!.availability() != NfcAvailability.enabled) {
      throw NfcException('NFC HP ini mati atau tidak tersedia. Nyalakan NFC, atau minta petugas memindai kartu Anda.');
    }
    return (await _nfc!.read()).text;
  }

  Future<void> _check() async {
    setState(() {
      _reading = true;
      _result = null;
      _error = null;
    });
    try {
      final text = await (widget.readCard ?? _readNfc)();
      final result = text == null || text.isEmpty ? 'invalid' : await widget.api.checkMyCard(text);
      HapticFeedback.mediumImpact();
      if (mounted) setState(() => _result = result);
    } on NfcException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'NFC tidak tersedia di perangkat ini atau server tidak terjangkau.');
    } finally {
      if (mounted) setState(() => _reading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final (IconData icon, Color color, String title, String desc) = switch (_result) {
      'mine' => (LucideIcons.circleCheck, const Color(0xFF16A34A), 'Kartu Anda aktif', 'Kartu terbaca, asli, dan terdaftar atas nama Anda. Siap dipakai absensi.'),
      'mine_inactive' => (
        LucideIcons.circleX,
        theme.colorScheme.destructive,
        'Kartu Anda sudah nonaktif',
        'Kartu ini pernah milik Anda tetapi sudah diblokir/dilaporkan hilang. Pakai kartu aktif atau lapor ke pengurus.',
      ),
      'other' => (LucideIcons.circleAlert, const Color(0xFFD97706), 'Kartu milik anggota lain', 'Kartu asli, tetapi bukan milik Anda. Kembalikan ke pemiliknya atau serahkan ke pengurus.'),
      'unknown' => (LucideIcons.circleHelp, const Color(0xFFD97706), 'Kartu belum terdaftar', 'Kartu asli organisasi ini tetapi belum dipasangkan ke anggota mana pun.'),
      'invalid' => (LucideIcons.circleX, theme.colorScheme.destructive, 'Bukan kartu anggota yang sah', 'Kartu kosong, rusak, atau bukan kartu organisasi ini.'),
      _ => (LucideIcons.nfc, theme.colorScheme.foreground, _reading ? 'Tempelkan kartu ke HP' : 'Siap memeriksa', 'Tempelkan kartu ke bagian belakang HP (dekat kamera) dan tahan sebentar.'),
    };
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        SectionCard(
          child: Column(
            children: [
              const SizedBox(height: 12),
              Container(
                width: 88,
                height: 88,
                decoration: BoxDecoration(color: color.withValues(alpha: 0.12), shape: BoxShape.circle),
                child: Icon(icon, size: 44, color: color),
              ),
              const SizedBox(height: 16),
              Text(title, style: theme.textTheme.large, textAlign: TextAlign.center),
              const SizedBox(height: 6),
              Text(desc, style: theme.textTheme.muted, textAlign: TextAlign.center),
              if (_reading) ...[const SizedBox(height: 16), const SizedBox(width: 160, child: LinearProgressIndicator(minHeight: 3))],
              const SizedBox(height: 12),
            ],
          ),
        ),
        if (_error != null) ...[const SizedBox(height: 12), ErrorBanner(_error!)],
        const SizedBox(height: 16),
        if (!_reading) FlexButton(label: 'Cek kartu lagi', icon: LucideIcons.refreshCw, variant: ShadButtonVariant.outline, onPressed: _check),
        const SizedBox(height: 12),
        Text('Hanya membaca kartu, tidak mencatat kehadiran.', style: theme.textTheme.muted.copyWith(fontSize: 12), textAlign: TextAlign.center),
      ],
    );
  }
}
