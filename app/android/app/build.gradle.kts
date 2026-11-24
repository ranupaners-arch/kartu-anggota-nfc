import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Kunci tanda tangan APK uji (debug & profile), supaya APK dari build berbeda bisa saling
// memperbarui tanpa uninstall. Sumber: variabel lingkungan (CI) atau file properties lokal
// yang ditunjuk APK_UJI_PROPERTIES (bawaan ~/.config/kartu-anggota/apk-uji.properties).
// Tanpa kunci ini, build memakai kunci debug bawaan (berbeda tiap mesin/CI run).
val apkUjiProps = Properties().apply {
    val path = System.getenv("APK_UJI_PROPERTIES") ?: "${System.getProperty("user.home")}/.config/kartu-anggota/apk-uji.properties"
    val f = file(path)
    if (f.exists()) FileInputStream(f).use { load(it) }
}
fun apkUji(prop: String, env: String): String? = System.getenv(env)?.takeIf { it.isNotEmpty() } ?: apkUjiProps.getProperty(prop)
val apkUjiStore = apkUji("storeFile", "APK_UJI_KEYSTORE_PATH")

// Kunci rilis: terpisah dari kunci uji, hanya dari variabel lingkungan (workflow "Rilis APK" atau build
// lokal pemegang kunci). Tanpa kunci lengkap, build release gagal: tidak ada lagi rilis berkunci debug.
fun rilis(env: String): String? = System.getenv("APK_RILIS_$env")?.takeIf { it.isNotEmpty() }
val rilisSiap = listOf("KEYSTORE_PATH", "STORE_PASSWORD", "KEY_ALIAS", "KEY_PASSWORD").all { rilis(it) != null }

android {
    namespace = "dev.kukerjain.kartuanggota"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "dev.kukerjain.kartuanggota"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Versi dari pubspec.yaml (CI menimpanya dengan nomor run; lihat README app).
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (apkUjiStore != null) {
            create("apkUji") {
                storeFile = file(apkUjiStore)
                storePassword = apkUji("storePassword", "APK_UJI_STORE_PASSWORD")
                keyAlias = apkUji("keyAlias", "APK_UJI_KEY_ALIAS")
                keyPassword = apkUji("keyPassword", "APK_UJI_KEY_PASSWORD")
            }
        }
    }

    if (rilisSiap) {
        signingConfigs.create("apkRilis") {
            storeFile = file(rilis("KEYSTORE_PATH")!!)
            storePassword = rilis("STORE_PASSWORD")
            keyAlias = rilis("KEY_ALIAS")
            keyPassword = rilis("KEY_PASSWORD")
        }
    }

    buildTypes {
        configureEach {
            if (apkUjiStore != null && (name == "debug" || name == "profile")) {
                signingConfig = signingConfigs.getByName("apkUji")
            }
        }
        release {
            // APK uji memakai mode profile (kunci apkUji di atas).
            signingConfig = if (rilisSiap) signingConfigs.getByName("apkRilis") else null
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

gradle.taskGraph.whenReady {
    if (!rilisSiap && allTasks.any { it.path == ":app:packageRelease" || it.path == ":app:bundleRelease" }) {
        throw GradleException("Kunci rilis belum diisi (APK_RILIS_*). Lihat docs/rilis-apk.md; untuk uji pakai --profile.")
    }
}

flutter {
    source = "../.."
}
