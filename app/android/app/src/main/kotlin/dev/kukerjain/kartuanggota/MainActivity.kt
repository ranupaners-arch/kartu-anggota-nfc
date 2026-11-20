package dev.kukerjain.kartuanggota

import android.content.Intent
import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        // Buka pengaturan NFC (bila NFC HP mati) — dipanggil dari tab Kartu anggota.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "kartuanggota/settings").setMethodCallHandler { call, result ->
            if (call.method == "openNfcSettings") {
                val opened = listOf(Settings.ACTION_NFC_SETTINGS, Settings.ACTION_WIRELESS_SETTINGS).any { action ->
                    try {
                        startActivity(Intent(action).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                        true
                    } catch (e: Exception) {
                        false
                    }
                }
                result.success(opened)
            } else {
                result.notImplemented()
            }
        }
    }
}
