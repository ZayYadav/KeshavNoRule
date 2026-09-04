# KeshavOwner release hardening
# Component/native class names are intentionally stable because JNI exports are name-based.
-keepnames class com.bgmi.KeshavOwner1
-keepnames class com.bgmi.KeshavOwner2
-keepnames class com.bgmi.KeshavOwner3
-keepnames class com.bgmi.utils.KeshavOwner4
-keepnames class com.bgmi.utils.KeshavOwner5
-keepnames class com.bgmi.utils.KeshavOwner6
-keepnames class com.bgmi.utils.KeshavOwner7
-keepnames class com.bgmi.KeshavOwner8

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
