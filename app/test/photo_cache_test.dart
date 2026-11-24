import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/offline/photo_cache.dart';
import 'package:kartuanggota/offline/roster.dart';
import 'package:kartuanggota/widgets/member_photo.dart';
import 'package:kartuanggota/widgets/scan_result_card.dart';
import 'package:kartuanggota/main.dart';
import 'package:material_ui/material_ui.dart';

import 'offline_test.dart' show rosterJson;

Roster rosterWith(Map<int, String?> photos) {
  final j = rosterJson();
  for (final m in j['members'] as List) {
    (m as Map<String, dynamic>)['photo_url'] = photos[m['id']];
  }
  return Roster.fromApi(j);
}

void main() {
  late Directory tmp;
  late PhotoCache cache;
  setUp(() {
    tmp = Directory.systemTemp.createTempSync('foto');
    cache = PhotoCache(Directory('${tmp.path}/photos'));
  });
  tearDown(() => tmp.deleteSync(recursive: true));

  test('versi diambil dari ?v=; URL tanpa versi tidak di-cache', () {
    expect(PhotoCache.versionOf('http://s/api/members/1/photo?v=ab12cd34'), 'ab12cd34');
    expect(PhotoCache.versionOf('http://s/api/members/1/photo'), isNull);
    expect(PhotoCache.versionOf('http://s/x?v=../../etc'), isNull);
  });

  test('unduh sekali, pakai offline, ganti saat versi berubah, lanjut walau satu gagal', () async {
    final downloads = <String>[];
    Future<List<int>> dl(String url) async {
      downloads.add(url);
      if (url.contains('/2/')) throw const SocketException('offline');
      return [0xFF, 0xD8, url.length];
    }

    final v1 = rosterWith({1: 'http://s/api/members/1/photo?v=aaaa1111', 2: 'http://s/api/members/2/photo?v=bbbb2222'});
    expect(await cache.sync(v1, dl), 1); // foto #2 gagal, #1 tetap tersimpan
    expect(cache.cached(1, 'http://s/api/members/1/photo?v=aaaa1111'), isNotNull);
    expect(cache.cached(2, 'http://s/api/members/2/photo?v=bbbb2222'), isNull);

    expect(await cache.sync(v1, dl), 0); // tidak mengunduh ulang #1
    expect(downloads.where((u) => u.contains('/1/')), hasLength(1));

    final v2 = rosterWith({1: 'http://s/api/members/1/photo?v=cccc3333'});
    expect(await cache.sync(v2, dl), 1);
    expect(cache.cached(1, 'http://s/api/members/1/photo?v=cccc3333'), isNotNull);
    expect(cache.cached(1, 'http://s/api/members/1/photo?v=aaaa1111'), isNull); // versi lama dihapus
    expect(cache.dir.listSync().where((e) => e.path.endsWith('.part')), isEmpty);
  });

  testWidgets('hasil scan offline memakai foto tersimpan', (tester) async {
    final file = File('${tmp.path}/1_aaaa1111.jpg')..writeAsBytesSync([1, 2, 3]);
    const r = ScanResult(status: 'ok', message: 'Berhasil.', memberId: 1, memberName: 'Budi', photoUrl: 'http://s/api/members/1/photo?v=aaaa1111', offline: true);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: ScanResultCard(result: r, photoFile: file),
        ),
      ),
    );
    expect(tester.widget<MemberPhoto>(find.byType(MemberPhoto)).file, file);
    expect(find.byWidgetPredicate((w) => w is Image && w.image is FileImage), findsOneWidget);
  });
}
