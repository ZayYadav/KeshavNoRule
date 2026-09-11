package com.team.dark;

/**
 * Native ABI compatibility bridge.
 * User-facing application code lives under com.dark.panther.
 */
public final class TeamDark1 {
    static {
        try {
            System.loadLibrary("DarkPantherLoader");
        } catch (Throwable ignored) {}
    }

    private TeamDark1() {}

    public static native String getSdkKey();
}
