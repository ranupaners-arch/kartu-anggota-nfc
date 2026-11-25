import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/api/api_client.dart';

import 'offline_test.dart' show budiCard;

// Berkas terpisah tanpa testWidgets: pengikat tes widget memblokir jaringan sungguhan.
void main() {
  test('scan ke server yang ditolak, diam, atau tidak ada: gagal paling lama 4 detik (lalu diantre)', () async {
    final silent = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    final held = <Socket>[];
    silent.listen(held.add);
    final closed = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    final port = closed.port;
    await closed.close();
    for (final url in ['http://127.0.0.1:$port', 'http://127.0.0.1:${silent.port}', 'http://tidak-ada.invalid']) {
      final w = Stopwatch()..start();
      await expectLater((ApiClient(baseUrl: url)..token = 't').scan(7, budiCard), throwsA(isNot(isA<ApiException>())), reason: url);
      expect(w.elapsed.inSeconds, lessThan(6), reason: url);
    }
    for (final s in held) {
      s.destroy();
    }
    await silent.close();
  });
}
