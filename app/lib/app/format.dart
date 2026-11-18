/// Format tanggal/jam Indonesia tanpa dependensi tambahan.
const _bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

String fmtDate(DateTime d) => '${d.day} ${_bulan[d.month - 1]} ${d.year}';
String fmtTime(DateTime d) => '${d.hour.toString().padLeft(2, '0')}.${d.minute.toString().padLeft(2, '0')}';
String fmtDateTime(DateTime d) => '${fmtDate(d)} ${fmtTime(d)}';

const statusLabel = {
  'hadir': 'Hadir', 'telat': 'Telat', 'izin': 'Izin', 'sakit': 'Sakit', 'alpa': 'Alpa',
  'pending': 'Menunggu', 'approved': 'Disetujui', 'rejected': 'Ditolak',
};
