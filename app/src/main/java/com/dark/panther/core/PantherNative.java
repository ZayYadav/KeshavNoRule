package com.dark.panther.core;

import android.content.Context;

/** Single Dark Panther Java facade over the preserved native auth implementation. */
public final class PantherNative {
    static {
        try {
            System.loadLibrary("DarkPantherLoader");
        } catch (Throwable ignored) {}
    }

    private PantherNative() {}

    public static native String getSdkKey();
    public static native String getKeyLink();
    public static native String versionUrl();
    public static native String downloadUrl();
    public static native String expiryDate();
    public static native String sessionToken();
    public static native String check(Context context, String userKey);
    public static native boolean verifySignature(Context context);
    public static native boolean customIntegrity(Context context);
    public static native boolean verifyServerLoader(Context context, String expectedHash, long expectedSize);
}
