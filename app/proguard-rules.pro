# Dark Panther release hardening
# New user-facing/component classes.
-keepnames class com.dark.panther.DarkPantherApp
-keepnames class com.dark.panther.DarkPantherLoginActivity
-keepnames class com.dark.panther.DarkPantherHomeActivity
-keepnames class com.dark.panther.core.PantherPrefs
-keepnames class com.dark.panther.core.PantherEffects
-keepnames class com.dark.panther.core.PantherIntegrity
-keepnames class com.dark.panther.core.PantherSecurity
-keep class com.dark.panther.core.PantherLoaderUpdater { *; }

# Tiny legacy JNI ABI bridges. These names must remain stable because main.cpp
# exports name-based JNI symbols from the existing native auth/server contract.
-keep class com.team.dark.TeamDark1 { *; }
-keep class com.team.dark.TeamDark2 { *; }
-keep class com.team.dark.TeamDark3 { *; }
-keep class com.team.dark.utils.TeamDark5 { *; }

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
