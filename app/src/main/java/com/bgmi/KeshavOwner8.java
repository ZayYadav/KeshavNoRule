package com.bgmi;

import android.content.Context;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.content.pm.Signature;
import android.content.pm.SigningInfo;
import android.os.Build;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.security.MessageDigest;
import java.util.Enumeration;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;
import java.util.zip.ZipEntry;
import java.util.zip.ZipFile;

@Obfuscate
public final class KeshavOwner8 {

    private static final String EXPECTED_PACKAGE = "com.bgmi";
    private static final String EXPECTED_CERT_SHA256 =
            "77f05d53ce8bf1855caef38ce87f13a8bb2b1b2cdd2d48da9d3ba897eac4549e";

    private static final Set<String> ALLOWED_NATIVE_LIBS = new HashSet<>();

    static {
        ALLOWED_NATIVE_LIBS.add("libKeshavOwner.so");
        ALLOWED_NATIVE_LIBS.add("libKESHAVXOWNERCore.so");
    }

    private KeshavOwner8() {}

    public static boolean verify(Context context) {
        if (context == null) return false;
        try {
            Context app = context.getApplicationContext();
            if (!EXPECTED_PACKAGE.equals(app.getPackageName())) return false;
            if ((app.getApplicationInfo().flags & ApplicationInfo.FLAG_DEBUGGABLE) != 0) return false;

            if (!verifyInstalledSigningCertificate(app)) return false;
            if (!verifyBaseApkSigningCertificate(app)) return false;
            if (!verifyApkNativeEntries(app)) return false;
            if (!verifyExtractedNativeDirectory(app)) return false;

            return true;
        } catch (Throwable ignored) {
            return false;
        }
    }

    private static boolean verifyInstalledSigningCertificate(Context context) throws Exception {
        PackageManager pm = context.getPackageManager();
        int flags = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P
                ? PackageManager.GET_SIGNING_CERTIFICATES
                : PackageManager.GET_SIGNATURES;
        PackageInfo info = pm.getPackageInfo(context.getPackageName(), flags);
        return signaturesTrusted(info);
    }

    private static boolean verifyBaseApkSigningCertificate(Context context) throws Exception {
        PackageManager pm = context.getPackageManager();
        ApplicationInfo ai = context.getApplicationInfo();
        if (ai == null || ai.sourceDir == null) return false;

        int flags = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P
                ? PackageManager.GET_SIGNING_CERTIFICATES
                : PackageManager.GET_SIGNATURES;
        PackageInfo archive = pm.getPackageArchiveInfo(ai.sourceDir, flags);
        return signaturesTrusted(archive);
    }

    private static boolean signaturesTrusted(PackageInfo info) throws Exception {
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
            if (signature != null && EXPECTED_CERT_SHA256.equals(sha256Hex(signature.toByteArray()))) {
                return true;
            }
        }
        return false;
    }

    private static boolean verifyApkNativeEntries(Context context) throws Exception {
        ApplicationInfo ai = context.getApplicationInfo();
        if (ai == null || ai.sourceDir == null) return false;

        Set<String> found = new HashSet<>();
        try (ZipFile zip = new ZipFile(ai.sourceDir)) {
            Enumeration<? extends ZipEntry> entries = zip.entries();
            while (entries.hasMoreElements()) {
                ZipEntry entry = entries.nextElement();
                if (entry == null || entry.isDirectory()) continue;

                String name = entry.getName();
                if (name == null || !name.startsWith("lib/") || !name.endsWith(".so")) continue;

                String baseName = new File(name).getName();
                if (!ALLOWED_NATIVE_LIBS.contains(baseName)) {
                    return false;
                }
                found.add(baseName);
            }
        }

        return found.contains("libKeshavOwner.so")
                && found.contains("libKESHAVXOWNERCore.so");
    }

    private static boolean verifyExtractedNativeDirectory(Context context) {
        ApplicationInfo ai = context.getApplicationInfo();
        if (ai == null || ai.nativeLibraryDir == null) return false;

        File dir = new File(ai.nativeLibraryDir);
        File[] files = dir.listFiles();
        if (files == null) return false;

        Set<String> found = new HashSet<>();
        for (File file : files) {
            if (file == null || !file.isFile() || !file.getName().endsWith(".so")) continue;
            if (!ALLOWED_NATIVE_LIBS.contains(file.getName())) {
                return false;
            }
            found.add(file.getName());
        }

        return found.contains("libKeshavOwner.so")
                && found.contains("libKESHAVXOWNERCore.so");
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
