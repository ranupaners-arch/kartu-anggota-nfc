import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/widgets/ui_scale.dart';
import 'package:material_ui/material_ui.dart';

void main() {
  test('faktor: HP kecil & tablet tetap, HP lebar membesar sampai 115%', () {
    expect(UiScale.factor(const Size(320, 568)), 1);
    expect(UiScale.factor(const Size(360, 800)), 1);
    expect(UiScale.factor(const Size(411, 891)), closeTo(411 / 360, 0.0001));
    expect(UiScale.factor(const Size(432, 960)), 1.15);
    expect(UiScale.factor(const Size(891, 411)), closeTo(411 / 360, 0.0001)); // lanskap sama dengan potret
    expect(UiScale.factor(const Size(768, 1024)), 1);
  });

  testWidgets('di A36 tata letak setara HP 360, memenuhi layar, dan ketukan tetap tepat', (tester) async {
    tester.view.physicalSize = const Size(411, 891);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    Size? seen;
    var taps = 0;
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Builder(
          builder: (context) {
            seen = MediaQuery.sizeOf(context);
            return Scaffold(
              body: Align(
                alignment: Alignment.bottomRight,
                child: SizedBox(
                  width: 100,
                  height: 50,
                  child: TextButton(onPressed: () => taps++, child: const Text('Ketuk')),
                ),
              ),
            );
          },
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(seen!.width, closeTo(360, 0.01));
    final box = tester.getRect(find.byType(TextButton));
    expect(box.right, closeTo(411, 0.5));
    expect(box.bottom, closeTo(891, 0.5));
    expect(box.width, closeTo(100 * 411 / 360, 0.5));
    await tester.tapAt(box.center);
    expect(taps, 1);
  });
}
