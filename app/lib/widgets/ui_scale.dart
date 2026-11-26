import 'package:material_ui/material_ui.dart';

/// Perbesar seluruh tampilan di HP yang lebih lebar dari desain acuan (360 dp), supaya proporsinya sama.
/// Tablet (sisi terpendek 600 dp ke atas) dan HP kecil tidak berubah. Ukuran huruf Android tetap berlaku.
class UiScale extends StatelessWidget {
  const UiScale({super.key, required this.child});

  final Widget child;

  static const baseWidth = 360.0, maxScale = 1.15, tabletWidth = 600.0;

  static double factor(Size screen) => screen.shortestSide >= tabletWidth ? 1 : (screen.shortestSide / baseWidth).clamp(1.0, maxScale);

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final s = factor(mq.size);
    if (s == 1) return child;
    final size = mq.size / s;
    return MediaQuery(
      data: mq.copyWith(
        size: size,
        devicePixelRatio: mq.devicePixelRatio * s,
        padding: mq.padding / s,
        viewPadding: mq.viewPadding / s,
        viewInsets: mq.viewInsets / s,
        systemGestureInsets: mq.systemGestureInsets / s,
      ),
      // Disusun pada ukuran yang lebih kecil lalu diperbesar; teks dan ikon tetap tajam.
      child: FittedBox(
        alignment: Alignment.topLeft,
        child: SizedBox.fromSize(size: size, child: child),
      ),
    );
  }
}
