import 'dart:io';

import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

/// Simpan PDF ke folder sementara lalu buka dengan penampil PDF HP. Kembalikan pesan galat atau null.
Future<String?> saveAndOpenPdf(List<int> bytes, {String fileName = 'dokumen.pdf'}) => saveAndOpenFile(bytes, fileName: fileName, type: 'application/pdf');

/// Simpan berkas ke folder sementara lalu buka dengan aplikasi yang sesuai. Kembalikan pesan galat atau null.
Future<String?> saveAndOpenFile(List<int> bytes, {required String fileName, String? type}) async {
  final f = File('${(await getTemporaryDirectory()).path}/$fileName');
  await f.writeAsBytes(bytes, flush: true);
  final r = await OpenFilex.open(f.path, type: type);
  return r.type == ResultType.done ? null : 'Tidak ada aplikasi untuk membuka ${fileName.split('.').last.toUpperCase()} di HP ini.';
}
