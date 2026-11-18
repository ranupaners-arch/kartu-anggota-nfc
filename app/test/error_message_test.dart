import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';

void main() {
  test('pesan server dipakai bila ada; selain itu pesan Indonesia per kode HTTP', () {
    expect(errorMessage(422, 'Kode 2FA tidak valid.'), 'Kode 2FA tidak valid.');
    expect(errorMessage(502, null), 'Server sedang tidak tersedia. Coba lagi beberapa saat lagi.');
    expect(errorMessage(500, ''), 'Terjadi kesalahan di server. Coba lagi nanti.');
    expect(errorMessage(413, null), 'Berkas terlalu besar.');
  });

  test('respons bukan JSON (halaman error proxy) tidak membuat app crash', () async {
    final api = ApiClient(baseUrl: 'http://srv', client: MockClient((_) async => http.Response('<html>502 Bad Gateway</html>', 502, reasonPhrase: 'Bad Gateway')));
    await expectLater(api.ongoingEvents(), throwsA(isA<ApiException>().having((e) => e.message, 'message', contains('Server sedang tidak tersedia'))));
  });
}
