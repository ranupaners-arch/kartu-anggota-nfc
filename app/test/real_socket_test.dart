import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/api/response_cache.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'offline_test.dart' show budiCard;

// Berkas terpisah tanpa testWidgets: pengikat tes widget memblokir jaringan sungguhan.
void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('soket sungguhan: server ditolak & server diam', () async {
    final silent = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    final held = <Socket>[];
    silent.listen(held.add);
    final closed = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    final closedPort = closed.port;
    await closed.close();
    for (final (url, limit) in [('http://127.0.0.1:$closedPort', 2), ('http://127.0.0.1:${silent.port}', 6), ('http://tidak-ada.invalid', 6)]) {
      final cache = ResponseCache(url);
      await cache.save('/api/me/events', {'data': <Object>[]});
      final api = ApiClient(baseUrl: url)
        ..token = 't'
        ..cache = cache;
      final w = Stopwatch()..start();
      expect(await api.myEvents(), isEmpty, reason: url);
      expect(w.elapsed.inSeconds, lessThan(limit), reason: url);
      expect(api.offlineSince.value, isNotNull);
      await expectLater(api.scan(7, budiCard), throwsA(isNot(isA<ApiException>())), reason: url);
    }
    for (final s in held) {
      s.destroy();
    }
    await silent.close();
  });
}
