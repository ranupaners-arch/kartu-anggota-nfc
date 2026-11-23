package dev.kukerjain.kartuanggota

import android.content.Intent
import android.provider.Settings
import android.view.WindowManager
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
            } else if (call.method == "setQrScreenMode") {
                // Halaman QR: layar tetap menyala & kecerahan maksimal agar mudah dipindai.
                // Hanya untuk jendela app ini (tanpa izin sistem); dikembalikan saat halaman ditutup.
                val on = call.arguments as? Boolean ?: false
                runOnUiThread {
                    if (on) window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    else window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    val attrs = window.attributes
                    attrs.screenBrightness = if (on) 1f else WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_NONE
                    window.attributes = attrs
                }
                result.success(true)
            } else {
                result.notImplemented()
            }
        }
    }
}
