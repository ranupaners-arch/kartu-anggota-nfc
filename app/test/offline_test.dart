import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/nfc/card_payload.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/offline/offline_verifier.dart';
import 'package:kartuanggota/offline/roster.dart';
import 'package:kartuanggota/offline/sync_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

const budiCard = 'card:v1:k1:01M7HC95QETAHJ4309PDSPG35N:64baa8aeec41fdf9dda2c8f9';
const sitiOldCard = 'card:v1:k1:01M7HC95QETAHJ4309PDSPG35P:aaaaaaaaaaaaaaaaaaaaaaaa';

/// Bentuk persis respons GET /api/events/{id}/roster (tanggal Eloquent dengan mikrodetik).
Map<String, dynamic> rosterJson() => {
      'event': {
        'id': 7, 'title': 'Rapat Pleno', 'location': 'Aula', 'closed_at': null,
        'checkin_opens_at': '2026-11-17T01:00:00.000000Z',
        'late_after': '2026-11-17T01:30:00.000000Z',
        'checkin_closes_at': '2026-11-17T03:00:00.000000Z',
      },
      'members': [
        {'id': 1, 'member_no': '001', 'name': 'Budi', 'division': 'Humas', 'photo_url': null, 'cards': [
          {'public_id': '01M7HC95QETAHJ4309PDSPG35N', 'status': 'active', 'payload_sha256': CardPayload.fingerprint(budiCard)},
        ]},
        {'id': 2, 'member_no': '002', 'name': 'Siti', 'division': null, 'photo_url': null, 'cards': [
          {'public_id': '01M7HC95QETAHJ4309PDSPG35P', 'status': 'blocked', 'payload_sha256': CardPayload.fingerprint(sitiOldCard)},
        ]},
      ],
      'generated_at': '2026-11-17T00:55:00+00:00',
    };

DateTime at(String hhmmUtc) => DateTime.parse('2026-11-17T$hhmmUtc:00Z');

void main() {
  final roster = Roster.fromApi(rosterJson());

  group('roster', () {
    test('dibaca dari format server dan bisa disimpan-dibaca ulang', () {
      expect(roster.eventId, 7);
      expect(roster.members, hasLength(2));
      expect(roster.byFingerprint(CardPayload.fingerprint(budiCard))!.member.name, 'Budi');
      final again = Roster.fromApi(jsonDecode(jsonEncode(roster.toApiJson())) as Map<String, dynamic>);
      expect(again.lateAfter, roster.lateAfter);
      expect(again.byFingerprint(CardPayload.fingerprint(sitiOldCard))!.card.active, isFalse);
    });
  });

  group('pemeriksaan offline (aturan sama dengan server)', () {
    test('hadir sebelum batas telat, telat sesudahnya', () {
      final h = OfflineVerifier.check(roster, budiCard, at('01:10'));
      expect(h.ok, isTrue);
      expect(h.attendanceStatus, 'hadir');
      expect(h.offline, isTrue);
      expect(h.memberId, 1);
      expect(OfflineVerifier.check(roster, budiCard, at('01:45')).attendanceStatus, 'telat');
    });

    test('kartu diblokir, kartu asing, format salah, duplikat, di luar jendela', () {
      expect(OfflineVerifier.check(roster, sitiOldCard, at('01:10')).status, 'blocked');
      expect(OfflineVerifier.check(roster, 'card:v1:k1:01M7HC95QETAHJ4309PDSPG35Q:bbbbbbbbbbbbbbbbbbbbbbbb', at('01:10')).status, 'invalid');
      expect(OfflineVerifier.check(roster, 'bukan-kartu', at('01:10')).status, 'invalid');
      expect(OfflineVerifier.check(roster, budiCard, at('01:10'), alreadyPresent: {1}).status, 'duplicate');
      expect(OfflineVerifier.check(roster, budiCard, at('00:30')).status, 'outside_window');
      expect(OfflineVerifier.check(roster, budiCard, at('03:30')).status, 'outside_window');
    });
  });

  group('antrean & sinkron', () {
    late OfflineStore store;
    PendingScan scan(String uuid, {int eventId = 7}) =>
        PendingScan(clientUuid: uuid, eventId: eventId, payload: budiCard, source: 'nfc', scannedAt: at('01:10'));

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      store = await OfflineStore.open();
    });

    test('antrean tersimpan, per kegiatan, tanpa duplikat uuid', () async {
      await store.enqueue(scan('a'));
      await store.enqueue(scan('a')); // simpan ulang uuid sama
      await store.enqueue(scan('b', eventId: 9));
      expect(store.pending(eventId: 7).map((p) => p.clientUuid), ['a']);
      expect((await OfflineStore.open()).pending(), hasLength(2)); // bertahan setelah app dibuka ulang
      await store.saveRoster(roster);
      expect(store.loadRoster(7)!.members, hasLength(2));
      expect(store.loadRoster(99), isNull);
    });

    test('sinkron berhasil menghapus item yang sudah dijawab server', () async {
      await store.enqueue(scan('a'));
      await store.enqueue(scan('b'));
      Map<String, dynamic>? sentBody;
      final api = ApiClient(baseUrl: 'http://srv', client: MockClient((req) async {
        sentBody = jsonDecode(req.body) as Map<String, dynamic>;
        expect(req.url.path, '/api/events/7/scan/batch');
        return http.Response(jsonEncode({'results': [
          {'client_uuid': 'a', 'status': 'ok'},
          {'client_uuid': 'b', 'status': 'duplicate'},
        ]}), 200);
      }))..token = 't';

      final r = await SyncService(api, store).sync(7);
      expect(r.ok, isTrue);
      expect((r.sent, r.accepted, r.duplicates, r.remaining), (2, 1, 1, 0));
      expect(store.pending(eventId: 7), isEmpty);
      expect((sentBody!['scans'] as List).first['client_uuid'], 'a'); // uuid asli dikirim → idempoten
    });

    test('jaringan putus: antrean tetap utuh', () async {
      await store.enqueue(scan('a'));
      final api = ApiClient(baseUrl: 'http://srv', client: MockClient((_) async => throw http.ClientException('offline')));
      final r = await SyncService(api, store).sync(7);
      expect(r.ok, isFalse);
      expect(r.remaining, 1);
      expect(store.pending(eventId: 7), hasLength(1));
    });

    test('server menolak sebagian item: yang dijawab tetap dihapus', () async {
      await store.enqueue(scan('a'));
      final api = ApiClient(baseUrl: 'http://srv', client: MockClient((_) async =>
          http.Response(jsonEncode({'results': [{'client_uuid': 'a', 'status': 'blocked'}]}), 200)));
      final r = await SyncService(api, store).sync(7);
      expect((r.rejected, r.remaining), (1, 0));
    });
  });
}
