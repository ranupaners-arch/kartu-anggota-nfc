import '../api/api_client.dart';
import '../nfc/card_payload.dart';
import 'roster.dart';

/// Periksa kartu tanpa internet memakai roster lokal. Aturannya mengikuti server (ScanService):
/// kartu harus aktif & milik peserta, di dalam jendela check-in, belum absen. Server tetap
/// memutuskan akhirnya saat sinkron (mis. kartu yang diblokir setelah roster diunduh).
abstract final class OfflineVerifier {
  static ScanResult check(Roster roster, String payload, DateTime now, {Set<int> alreadyPresent = const {}}) {
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
    if (now.isBefore(roster.opensAt) || now.isAfter(roster.closesAt)) return reject('outside_window', 'Di luar jendela check-in.', m);

    return ScanResult(
      status: 'ok',
      attendanceStatus: now.isAfter(roster.lateAfter) ? 'telat' : 'hadir',
      message: 'Tersimpan offline, akan disinkron.',
      offline: true,
      memberId: m.id, memberName: m.name, memberNo: m.memberNo, division: m.division, photoUrl: m.photoUrl,
    );
  }
}
