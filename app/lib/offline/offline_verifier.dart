import '../api/api_client.dart';
import '../nfc/card_payload.dart';
import 'roster.dart';

/// Periksa kartu tanpa internet memakai roster lokal. Aturannya mengikuti server (ScanService):
/// kartu harus aktif & milik peserta, di dalam jendela check-in, belum absen. Server tetap
/// memutuskan akhirnya saat sinkron (mis. kartu yang diblokir setelah roster diunduh).
abstract final class OfflineVerifier {
  /// [session]: sesi yang dipilih petugas; jendela & batas telatnya dipakai (bawaan: jendela kegiatan).
  /// [alreadyPresent] berisi anggota yang sudah absen di sesi itu.
  static ScanResult check(Roster roster, String payload, DateTime now, {Set<int> alreadyPresent = const {}, SessionInfo? session}) {
    final opensAt = session?.opensAt ?? roster.opensAt, lateAfter = session?.lateAfter ?? roster.lateAfter, closesAt = session?.closesAt ?? roster.closesAt;
    ScanResult reject(String status, String message, [RosterMember? m]) => ScanResult(
          status: status, message: message, offline: true,
          memberId: m?.id, memberName: m?.name, memberNo: m?.memberNo, division: m?.division, photoUrl: m?.photoUrl,
        );

    if (CardPayload.parse(payload) == null) return reject('invalid', 'Bukan kartu anggota.');
    final hit = roster.byFingerprint(CardPayload.fingerprint(payload));
    if (hit == null) return reject('invalid', 'Kartu tidak dikenal atau bukan peserta (data offline).');
    final m = hit.member;
    if (!hit.card.active) return reject('blocked', 'Kartu diblokir.', m);
    if (alreadyPresent.contains(m.id)) return reject('duplicate', 'Sudah absen.', m);
    if (now.isBefore(opensAt) || now.isAfter(closesAt)) {
      return reject('outside_window', session != null && roster.sessions.length > 1 ? 'Di luar jendela check-in sesi ${session.title}.' : 'Di luar jendela check-in.', m);
    }

    return ScanResult(
      status: 'ok',
      attendanceStatus: now.isAfter(lateAfter) ? 'telat' : 'hadir',
      message: 'Tersimpan offline, akan disinkron.',
      offline: true,
      memberId: m.id, memberName: m.name, memberNo: m.memberNo, division: m.division, photoUrl: m.photoUrl,
    );
  }
}
