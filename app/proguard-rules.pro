# Dark Panther release hardening
-keepnames class com.dark.panther.DarkPantherApp
-keepnames class com.dark.panther.DarkPantherLoginActivity
-keepnames class com.dark.panther.DarkPantherHomeActivity
-keepnames class com.dark.panther.core.PantherPrefs
-keepnames class com.dark.panther.core.PantherEffects
-keepnames class com.dark.panther.core.PantherIntegrity
-keepnames class com.dark.panther.core.PantherSecurity
-keep class com.dark.panther.core.PantherLoaderUpdater { *; }
-keep class com.dark.panther.core.PantherNative { *; }

-keepclasseswithmembernames,includedescriptorclasses class * {
    native <methods>;
}

# BlackBox/BlackReflection/SDK reflection metadata.
-keep class top.niunaijun.blackbox.** { *; }
-keep class black.android.** { *; }
-keep class net_62v.external.** { *; }
-keepattributes RuntimeVisibleAnnotations,RuntimeInvisibleAnnotations,AnnotationDefault,Signature,InnerClasses,EnclosingMethod

-allowaccessmodification
-adaptclassstrings
-dontnote **
-dontwarn org.jetbrains.annotations.**
-dontwarn kotlin.**

-assumenosideeffects class android.util.Log {
    public static *** v(...);
    public static *** d(...);
    public static *** i(...);
}

-renamesourcefileattribute DarkPanther
