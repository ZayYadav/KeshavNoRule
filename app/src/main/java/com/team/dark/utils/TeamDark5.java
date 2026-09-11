package com.team.dark.utils;

/**
 * Native ABI bridge for the existing version/download URLs.
 * Dark Panther keeps those server-side contracts unchanged.
 */
public final class TeamDark5 {
    static {
        try {
            System.loadLibrary("DarkPantherLoader");
        } catch (Throwable ignored) {}
    }

    private TeamDark5() {}

    public static native String Version();
    public static native String Link();
}
