import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/login_screen.dart';
import 'package:kartuanggota/widgets/scan_result_card.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  test('URL tanpa skema otomatis diberi http://', () {
    expect(LoginScreenValidation.normalize(' 100.84.251.14:8000/ '), 'http://100.84.251.14:8000');
    expect(LoginScreenValidation.normalize('https://absen.org.id'), 'https://absen.org.id');
    expect(LoginScreenValidation.normalize('ftp://x'), 'ftp://x'); // skema lain dibiarkan → ditolak check()
    expect(LoginScreenValidation.check(LoginScreenValidation.normalize('ftp://x')), isNotNull);
  });

  test('validasi URL server', () {
    expect(LoginScreenValidation.check('http://100.84.251.14:8000'), isNull);
    expect(LoginScreenValidation.check('https://absen.organisasi.id'), isNull);
    expect(LoginScreenValidation.check('100.84.251.14:8000'), isNotNull);
    expect(LoginScreenValidation.check('http://'), isNotNull);
  });

  testWidgets('tanpa sesi tersimpan, app membuka halaman login', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(const KartuAnggotaApp());
    await tester.pumpAndSettle();
    expect(find.text('Masuk Petugas'), findsOneWidget);
    expect(find.text('Masuk'), findsOneWidget);
  });

  testWidgets('URL server terisi otomatis dan tersembunyi; bisa diganti', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(const KartuAnggotaApp(home: LoginScreen()));
    await tester.pumpAndSettle();
    expect(find.text(defaultServerUrl), findsOneWidget); // tampil sebagai teks, bukan kolom isian
    expect(find.text('URL server'), findsNothing);

    await tester.tap(find.text('Ganti server'));
    await tester.pumpAndSettle();
    expect(find.text('URL server'), findsOneWidget);
    await tester.enterText(find.byType(EditableText).last, 'ftp://salah');
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();
    expect(find.textContaining('harus diawali http://'), findsOneWidget);
  });

  testWidgets('kartu hasil scan menampilkan label sesuai status', (tester) async {
    Future<void> show(ScanResult r) => tester.pumpWidget(KartuAnggotaApp(home: Scaffold(body: ScanResultCard(result: r))));

    await show(const ScanResult(status: 'ok', attendanceStatus: 'hadir', message: 'Berhasil.', memberName: 'Budi', memberNo: '001'));
    expect(find.text('Hadir'), findsOneWidget);
    expect(find.text('Budi'), findsOneWidget);

    await show(const ScanResult(status: 'ok', attendanceStatus: 'telat', message: 'Berhasil.', memberName: 'Budi'));
    expect(find.text('Telat'), findsOneWidget);

    await show(const ScanResult(status: 'duplicate', message: 'Sudah absen.', memberName: 'Budi'));
    expect(find.text('Sudah absen'), findsOneWidget);

    await show(const ScanResult(status: 'blocked', message: 'Kartu diblokir.'));
    expect(find.text('Kartu diblokir'), findsWidgets);
  });
}
