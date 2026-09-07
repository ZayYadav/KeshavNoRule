# TeamDark release hardening
# Component/native class names are intentionally stable because JNI exports are name-based.
-keepnames class com.bgmi.TeamDark1
-keepnames class com.bgmi.TeamDark2
-keepnames class com.bgmi.TeamDark3
-keepnames class com.bgmi.utils.TeamDark4
# TeamDark5 performs network/archive/integrity work and must stay verifier-stable.
# Keep its bytecode shape intact on Android 16; native Version()/Link() bindings remain unchanged.
-keep class com.bgmi.utils.TeamDark5 { *; }
-keepnames class com.bgmi.utils.TeamDark6
-keepnames class com.bgmi.utils.TeamDark7
-keepnames class com.bgmi.TeamDark8

# Preserve JNI method names/descriptors while allowing normal R8 optimization elsewhere.
-keepclasseswithmembernames,includedescriptorclasses class * {
    native <methods>;
}

# BlackBox/BlackReflection rely heavily on reflection and generated metadata.
-keep class top.niunaijun.blackbox.** { *; }
-keep class black.android.** { *; }
-keep class net_62v.external.** { *; }
-keepattributes RuntimeVisibleAnnotations,RuntimeInvisibleAnnotations,AnnotationDefault,Signature,InnerClasses,EnclosingMethod

# Aggressive optimizer settings for app-owned code.
-allowaccessmodification
-adaptclassstrings
-dontnote **
-dontwarn org.jetbrains.annotations.**
-dontwarn kotlin.**

# Strip release logging calls.
-assumenosideeffects class android.util.Log {
    public static *** v(...);
    public static *** d(...);
    public static *** i(...);
}

# Do not retain source/debug metadata in the release artifact.
-renamesourcefileattribute Keshav
