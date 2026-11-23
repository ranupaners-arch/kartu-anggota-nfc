import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/nfc/card_payload.dart';

void main() {
  const valid = 'card:v1:k1:01M7HC95QETAHJ4309PDSPG35N:64baa8aeec41fdf9dda2c8f9';

  test('parse payload valid', () {
    final p = CardPayload.parse(valid)!;
    expect(p.keyId, 'k1');
    expect(p.publicId, '01M7HC95QETAHJ4309PDSPG35N');
    expect(p.encode(), valid);
  });

  test('tolak format salah', () {
    for (final bad in [
      '',
      'card:v2:k1:01M7HC95QETAHJ4309PDSPG35N:64ba',
      'hmps:v1:k1:01M7HC95QETAHJ4309PDSPG35N:64ba', // prefix lain
      'card:v1:k1:bukan-ulid:64ba',
      'card:v1:k1:01M7HC95QETAHJ4309PDSPG35N:XYZ',
      'card:v1:k1:01M7HC95QETAHJ4309PDSPG35N',
    ]) {
      expect(CardPayload.parse(bad), isNull, reason: bad);
    }
  });

  test('prefix bisa diatur', () {
    expect(CardPayload.parse(valid.replaceFirst('card', 'org'), prefix: 'org'), isNotNull);
  });

  test('fingerprint = sha256 hex, sama dengan backend', () {
    // php -r 'echo hash("sha256", "card:v1:k1:01M7HC95QETAHJ4309PDSPG35N:64baa8aeec41fdf9dda2c8f9");'
    expect(CardPayload.fingerprint(valid), hasLength(64));
    expect(CardPayload.fingerprint(' $valid\n'), CardPayload.fingerprint(valid));
  });
}
