import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:kartuanggota/widgets/ui.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import 'responsive_test.dart' as r;

/// Rasio kontras WCAG antara dua warna.
double contrast(Color a, Color b) {
  final la = a.computeLuminance(), lb = b.computeLuminance();
  final hi = la > lb ? la : lb, lo = la > lb ? lb : la;
  return (hi + 0.05) / (lo + 0.05);
}

Color textColor(WidgetTester tester, String text) {
  final p = tester.renderObject<RenderParagraph>(find.text(text));
  return p.text.style!.color!;
}

void main() {
  for (final brightness in [Brightness.light, Brightness.dark]) {
    testWidgets('teks halaman terbaca di mode ${brightness.name}', (tester) async {
      tester.platformDispatcher.platformBrightnessTestValue = brightness;
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

      late Color background;
      await tester.pumpWidget(
        KartuAnggotaApp(
          home: Builder(
            builder: (context) {
              final theme = ShadTheme.of(context);
              background = theme.colorScheme.background;
              return AppPage(
                title: 'Kegiatan Berlangsung',
                child: Column(
                  children: [
                    Text('Masuk sebagai Petugas Scan', style: theme.textTheme.muted),
                    Text('Teks besar', style: theme.textTheme.large),
                    Text('Teks kecil', style: theme.textTheme.small),
                    const Text('Teks tanpa gaya'),
                  ],
                ),
              );
            },
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(background.computeLuminance() < 0.2, brightness == Brightness.dark, reason: 'latar mengikuti mode');
      for (final t in ['Kegiatan Berlangsung', 'Masuk sebagai Petugas Scan', 'Teks besar', 'Teks kecil', 'Teks tanpa gaya']) {
        final ratio = contrast(textColor(tester, t), background);
        expect(ratio, greaterThanOrEqualTo(4.5), reason: '"$t" kontras ${ratio.toStringAsFixed(2)}:1 di mode ${brightness.name}');
      }
      // Gaya bawaan shadcn tetap utuh (hanya warnanya yang ditambahkan).
      final large = tester.renderObject<RenderParagraph>(find.text('Teks besar')).text.style!;
      expect((large.fontSize, large.fontWeight), (18, FontWeight.w600));
      // Teks muted tetap lebih redup dari teks utama.
      expect(textColor(tester, 'Masuk sebagai Petugas Scan'), isNot(textColor(tester, 'Teks besar')));
    });
  }

  // Tombol navigasi bawah layar anggota: sebelumnya abu gelap di atas hitam pada mode gelap.
  for (final brightness in [Brightness.light, Brightness.dark]) {
    testWidgets('navigasi bawah anggota terbaca di mode ${brightness.name}', (tester) async {
      tester.platformDispatcher.platformBrightnessTestValue = brightness;
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
      await tester.pumpWidget(
        KartuAnggotaApp(
          home: MemberHomeScreen(session: r.memberSession, api: r.api()),
        ),
      );
      await tester.pump();
      final bg = Theme.of(tester.element(find.byType(NavigationBar))).navigationBarTheme.backgroundColor!;
      for (final label in ['Beranda', 'Kegiatan', 'Riwayat', 'Akun']) {
        final p = tester.renderObject<RenderParagraph>(find.descendant(of: find.byType(NavigationBar), matching: find.text(label)));
        final ratio = contrast(p.text.style!.color!, bg);
        expect(ratio, greaterThanOrEqualTo(4.5), reason: '"$label" kontras ${ratio.toStringAsFixed(2)}:1 di mode ${brightness.name}');
      }
      await tester.pumpWidget(const SizedBox());
    });
  }

  // Teks yang diketik/terisi harus berwarna teks utama, bukan abu seperti petunjuk (dulu tampak kosong).
  for (final brightness in [Brightness.light, Brightness.dark]) {
    testWidgets('teks isian form berwarna utama, petunjuk tetap redup (${brightness.name})', (tester) async {
      tester.platformDispatcher.platformBrightnessTestValue = brightness;
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
      final c = TextEditingController(text: 'Budi Santoso');
      addTearDown(c.dispose);
      await tester.pumpWidget(
        KartuAnggotaApp(
          home: Scaffold(
            body: Column(children: [ShadInput(controller: c), const ShadInput(placeholder: Text('Petunjuk'))]),
          ),
        ),
      );
      await tester.pump();
      final theme = ShadTheme.of(tester.element(find.byType(ShadInput).first));
      final typed = tester.widget<EditableText>(find.byType(EditableText).first).style.color;
      expect(typed, theme.colorScheme.foreground);
      expect(textColor(tester, 'Petunjuk'), isNot(theme.colorScheme.foreground));
    });
  }
}
