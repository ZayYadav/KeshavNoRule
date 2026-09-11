package com.team.dark;

import android.content.Context;

/**
 * Native ABI compatibility bridge for the existing panel/auth contract.
 * All visible loader screens are implemented by Dark Panther classes.
 */
public final class TeamDark2 {
    static {
        try {
            System.loadLibrary("DarkPantherLoader");
        } catch (Throwable ignored) {}
    }

    private TeamDark2() {}

    public static native boolean nativeVerifySignature(Context context);
    public static native boolean nativeCustomIntegrity(Context context);
    public static native boolean nativeVerifyServerLoader(
            Context context,
            String expectedHash,
            long expectedSize);
    public static native String Check(Context context, String userKey);
    public static native String GetKey();
}
