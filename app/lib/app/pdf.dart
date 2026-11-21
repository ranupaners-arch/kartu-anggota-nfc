import 'dart:io';

import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

/// Simpan PDF ke folder sementara lalu buka dengan penampil PDF HP. Kembalikan pesan galat atau null.
Future<String?> saveAndOpenPdf(List<int> bytes, {String fileName = 'dokumen.pdf'}) async {
  final f = File('${(await getTemporaryDirectory()).path}/$fileName');
  await f.writeAsBytes(bytes, flush: true);
  final r = await OpenFilex.open(f.path, type: 'application/pdf');
  return r.type == ResultType.done ? null : 'Tidak ada aplikasi untuk membuka PDF di HP ini.';
}
