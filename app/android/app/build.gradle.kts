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

android {
    namespace = "dev.kukerjain.kartuanggota"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "dev.kukerjain.kartuanggota"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
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

    buildTypes {
        configureEach {
            if (apkUjiStore != null && (name == "debug" || name == "profile")) {
                signingConfig = signingConfigs.getByName("apkUji")
            }
        }
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
