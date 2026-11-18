import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/nfc/card_payload.dart';
import 'package:kartuanggota/offline/offline_verifier.dart';
import 'package:kartuanggota/offline/roster.dart';
import 'package:kartuanggota/widgets/scan_result_card.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

Future<void> show(WidgetTester t, ScanResult r) => t.pumpWidget(KartuAnggotaApp(home: Scaffold(body: ScanResultCard(result: r, token: 'tok-123'))));

void main() {
  test('photo_url dari respons scan terbaca', () {
    final r = ScanResult.fromJson({
      'status': 'ok', 'message': 'Berhasil.', 'attendance_status': 'hadir',
      'member': {'id': 5, 'name': 'Budi', 'member_no': '001', 'photo_url': 'http://srv/api/members/5/photo'},
    });
    expect(r.photoUrl, 'http://srv/api/members/5/photo');
  });

  testWidgets('foto anggota dimuat dengan token sesi', (tester) async {
    await show(tester, const ScanResult(status: 'ok', attendanceStatus: 'hadir', message: 'Berhasil.', memberName: 'Budi', photoUrl: 'http://srv/api/members/5/photo'));
    final img = tester.widget<Image>(find.byType(Image));
    final provider = img.image as NetworkImage;
    expect(provider.url, 'http://srv/api/members/5/photo');
    expect(provider.headers, {'Authorization': 'Bearer tok-123'});
    expect(img.semanticLabel, 'Foto Budi');
  });

  testWidgets('tanpa foto: ikon pengganti; ditolak tanpa anggota: ikon status', (tester) async {
    await show(tester, const ScanResult(status: 'ok', attendanceStatus: 'hadir', message: 'Berhasil.', memberName: 'Budi'));
    expect(find.byType(Image), findsNothing);
    expect(find.byIcon(LucideIcons.userRound), findsOneWidget);

    await show(tester, const ScanResult(status: 'invalid', message: 'Kartu tidak dikenali.'));
    expect(find.byIcon(LucideIcons.userRound), findsNothing);
    expect(find.byIcon(LucideIcons.circleX), findsOneWidget);
  });

  test('hasil offline membawa foto dari roster', () {
    const card = 'card:v1:k1:01M7HC95QETAHJ4309PDSPG35N:64baa8aeec41fdf9dda2c8f9';
    final roster = Roster.fromApi({
      'event': {'id': 1, 'title': 'X', 'closed_at': null, 'checkin_opens_at': '2026-11-18T00:00:00Z', 'late_after': '2026-11-18T01:00:00Z', 'checkin_closes_at': '2026-11-18T05:00:00Z'},
      'members': [
        {'id': 5, 'member_no': '001', 'name': 'Budi', 'division': null, 'photo_url': 'http://srv/api/members/5/photo', 'cards': [
          {'public_id': '01M7HC95QETAHJ4309PDSPG35N', 'status': 'active', 'payload_sha256': CardPayload.fingerprint(card)},
        ]},
      ],
      'generated_at': '2026-11-18T00:00:00Z',
    });
    final r = OfflineVerifier.check(roster, card, DateTime.parse('2026-11-18T00:30:00Z'));
    expect(r.photoUrl, 'http://srv/api/members/5/photo');
    // Roster disimpan ulang tetap membawa foto.
    expect(Roster.fromApi(roster.toApiJson()).members.first.photoUrl, 'http://srv/api/members/5/photo');
  });
}
