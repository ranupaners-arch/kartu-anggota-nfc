import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';

/// Barang organisasi yang sedang dipinjam anggota.
class LoansPage extends StatefulWidget {
  const LoansPage({super.key, required this.api});

  final ApiClient api;

  @override
  State<LoansPage> createState() => _LoansPageState();
}

class _LoansPageState extends State<LoansPage> {
  List<MemberLoan>? _items;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    List<MemberLoan>? items;
    String? error;
    try {
      items = await widget.api.loans();
    } on ApiException catch (e) {
      error = e.status == 404 ? 'Server organisasi Anda belum mendukung inventaris.' : e.message;
    } catch (_) {
      error = 'Server tidak terjangkau. Tarik untuk mencoba lagi.';
    }
    if (!mounted) return;
    setState(() {
      _items = items ?? _items;
      _error = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
          if (items == null && _error == null)
            const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            ),
          if (items != null && items.isEmpty) Text('Anda tidak sedang meminjam barang.', style: ShadTheme.of(context).textTheme.muted),
          for (final l in items ?? const <MemberLoan>[])
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: SectionCard(
                title: l.quantity > 1 ? '${l.item} (${l.quantity})' : l.item,
                icon: LucideIcons.package,
                description: 'Jatuh tempo ${fmtDate(l.dueOn)}. Kembalikan ke pengurus.',
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: StatusBadge(status: l.overdue ? 'alpa' : null, label: l.overdue ? 'Terlambat' : 'Dipinjam'),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
