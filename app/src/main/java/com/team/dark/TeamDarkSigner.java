package com.team.dark;

import android.content.Context;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.content.pm.Signature;
import android.content.pm.SigningInfo;
import android.os.Build;

import java.io.File;
import java.security.MessageDigest;
import java.util.Locale;

/**
 * Minimal host identity verifier intentionally kept independent from AppCompat,
 * ParallaxElite and native code. This class is safe to use before any heavy SDK
 * startup and is the source of truth for the launcher tamper gate.
 */
public final class TeamDarkSigner {

    private static final String EXPECTED_PACKAGE = "com.team.dark.elite";
    private static final String EXPECTED_CERT_SHA256 =
            "95d42274430c198e20056da00e5e4dcafd5935d93d2e4380e2788b1b7ff8a32f";

    private TeamDarkSigner() {}

    public static boolean verify(Context context) {
        if (context == null) return false;

        try {
            Context app = context.getApplicationContext();
            if (app == null) app = context;

            if (!EXPECTED_PACKAGE.equals(app.getPackageName())) return false;

            ApplicationInfo ai = app.getApplicationInfo();
            if (ai == null) return false;
            if ((ai.flags & ApplicationInfo.FLAG_DEBUGGABLE) != 0) return false;

            // Installed package signer must match the release certificate.
            if (!verifyInstalledSigner(app)) return false;

            // Independently parse the signer from the currently installed base APK.
            // This catches common repack/re-sign flows before any SDK/native startup.
            return verifyArchiveSigner(app, ai);
        } catch (Throwable ignored) {
            return false;
        }
    }

    private static boolean verifyInstalledSigner(Context context) throws Exception {
        PackageManager pm = context.getPackageManager();
        int flags = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P
                ? PackageManager.GET_SIGNING_CERTIFICATES
                : PackageManager.GET_SIGNATURES;

        PackageInfo info = pm.getPackageInfo(context.getPackageName(), flags);
        return containsExpectedSigner(info);
    }

    private static boolean verifyArchiveSigner(Context context, ApplicationInfo ai) throws Exception {
        String apkPath = ai.sourceDir;
        if (apkPath == null || apkPath.trim().isEmpty()) return false;

        File baseApk = new File(apkPath);
        if (!baseApk.isFile() || baseApk.length() <= 0L) return false;

        PackageManager pm = context.getPackageManager();
        int flags = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P
                ? PackageManager.GET_SIGNING_CERTIFICATES
                : PackageManager.GET_SIGNATURES;

        PackageInfo archive = pm.getPackageArchiveInfo(baseApk.getAbsolutePath(), flags);
        return containsExpectedSigner(archive);
    }

    private static boolean containsExpectedSigner(PackageInfo info) throws Exception {
        if (info == null) return false;

        Signature[] signatures;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P && info.signingInfo != null) {
            SigningInfo signingInfo = info.signingInfo;
            signatures = signingInfo.hasMultipleSigners()
                    ? signingInfo.getApkContentsSigners()
                    : signingInfo.getSigningCertificateHistory();
        } else {
            signatures = info.signatures;
        }

        if (signatures == null || signatures.length == 0) return false;

        for (Signature signature : signatures) {
            if (signature == null) continue;
            if (EXPECTED_CERT_SHA256.equals(sha256Hex(signature.toByteArray()))) {
                return true;
            }
        }
        return false;
    }

    private static String sha256Hex(byte[] data) throws Exception {
        MessageDigest digest = MessageDigest.getInstance("SHA-256");
        byte[] hash = digest.digest(data);
        StringBuilder out = new StringBuilder(hash.length * 2);
        for (byte b : hash) {
            out.append(String.format(Locale.US, "%02x", b & 0xff));
        }
        return out.toString();
    }
}
