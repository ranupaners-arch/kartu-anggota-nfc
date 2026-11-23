/// Format tanggal/jam Indonesia tanpa dependensi tambahan.
const _bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

String fmtDate(DateTime d) => '${d.day} ${_bulan[d.month - 1]} ${d.year}';
String fmtTime(DateTime d) => '${d.hour.toString().padLeft(2, '0')}.${d.minute.toString().padLeft(2, '0')}';
String fmtDateTime(DateTime d) => '${fmtDate(d)} ${fmtTime(d)}';

const statusLabel = {'hadir': 'Hadir', 'telat': 'Telat', 'izin': 'Izin', 'sakit': 'Sakit', 'alpa': 'Alpa', 'pending': 'Menunggu', 'approved': 'Disetujui', 'rejected': 'Ditolak'};

/// Waktu relatif singkat: "baru saja", "5 menit lalu", "3 jam lalu", "kemarin", "4 hari lalu", lalu tanggal.
String fmtAgo(DateTime d, [DateTime? now]) {
  final n = now ?? DateTime.now();
  final diff = n.difference(d);
  if (diff.isNegative || diff.inMinutes < 1) return 'baru saja';
  if (diff.inHours < 1) return '${diff.inMinutes} menit lalu';
  if (diff.inDays < 1) return '${diff.inHours} jam lalu';
  if (diff.inDays == 1) return 'kemarin';
  if (diff.inDays < 7) return '${diff.inDays} hari lalu';
  return fmtDate(d);
}
