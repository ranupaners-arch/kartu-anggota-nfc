# flutter_local_notifications menyimpan alarm lewat Gson; tipe generiknya tidak boleh dihapus R8.
-keepattributes Signature,*Annotation*
-keep class com.dexterous.flutterlocalnotifications.models.** { *; }
-keep class com.google.gson.reflect.TypeToken { *; }
-keep class * extends com.google.gson.reflect.TypeToken
