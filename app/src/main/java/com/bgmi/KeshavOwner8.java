package com.bgmi;

import android.content.Context;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.content.pm.Signature;
import android.content.pm.SigningInfo;
import android.os.Build;

import com.bgmi.utils.KeshavOwner5;
import com.bgmi.utils.KeshavOwner6;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.Enumeration;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.zip.ZipEntry;
import java.util.zip.ZipFile;

@Obfuscate
public final class KeshavOwner8 {

    private static final Set<String> ALLOWED_NATIVE_LIBS = new HashSet<>();

    static {
        ALLOWED_NATIVE_LIBS.add("libKeshavLoader.so");
        ALLOWED_NATIVE_LIBS.add("libKESHAVXOWNERCore.so");
    }

    private KeshavOwner8() {}

    public static boolean verify(Context context) {
        if (context == null) return false;

        try {
            Context app = context.getApplicationContext();

            if (!expectedPackage().equals(app.getPackageName())) return false;
            if ((app.getApplicationInfo().flags & ApplicationInfo.FLAG_DEBUGGABLE) != 0) return false;

            if (!verifyInstalledSigningCertificate(app)) return false;
            if (!verifyBaseApkSigningCertificate(app)) return false;
            if (!verifyApkNativeEntries(app)) return false;
            if (!verifyExtractedNativeDirectory(app)) return false;
            if (!verifyTrustedServerLoader(app)) return false;
            if (!verifySdkRuntimeArtifacts(app)) return false;

            return true;

        } catch (Throwable ignored) {
            return false;
        }
    }

    private static String expectedPackage() {
        return "com." + "bgmi";
    }

    private static String expectedCertSha256() {
        return "77f05d53ce8bf1855"
                + "caef38ce87f13a8"
                + "bb2b1b2cdd2d48da"
                + "9d3ba897eac4549e";
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

        String expected = expectedCertSha256();

        for (Signature signature : signatures) {
            if (signature == null) continue;

            String actual = sha256Hex(signature.toByteArray());
            if (expected.equals(actual)) {
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

        return found.size() == 2
                && found.contains("libKeshavLoader.so")
                && found.contains("libKESHAVXOWNERCore.so");
    }

    private static boolean verifyExtractedNativeDirectory(Context context) throws Exception {
        ApplicationInfo ai = context.getApplicationInfo();
        if (ai == null || ai.nativeLibraryDir == null) return false;

        File dir = new File(ai.nativeLibraryDir);
        String canonicalDir = dir.getCanonicalPath();
        File[] files = dir.listFiles();

        if (files == null) return false;

        Set<String> found = new HashSet<>();

        for (File file : files) {
            if (file == null || !file.isFile() || !file.getName().endsWith(".so")) continue;

            String canonical = file.getCanonicalPath();
            if (!canonical.startsWith(canonicalDir + File.separator)) return false;

            if (!ALLOWED_NATIVE_LIBS.contains(file.getName())) {
                return false;
            }

            found.add(file.getName());
        }

        return found.size() == 2
                && found.contains("libKeshavLoader.so")
                && found.contains("libKESHAVXOWNERCore.so");
    }

    private static boolean verifyTrustedServerLoader(Context context) throws Exception {
        File loaderDir = new File(context.getFilesDir(), "loader");
        if (!loaderDir.exists()) return true;

        String filesRoot = context.getFilesDir().getCanonicalPath() + File.separator;
        String loaderRoot = loaderDir.getCanonicalPath() + File.separator;

        if (!loaderRoot.startsWith(filesRoot)) return false;

        List<File> soFiles = new ArrayList<>();
        collectSoFiles(loaderDir, soFiles);

        if (soFiles.isEmpty()) return true;
        if (soFiles.size() != 1) return false;

        File loader = soFiles.get(0);
        File expected = KeshavOwner5.trustedLoaderFile(context);

        if (!KeshavOwner5.TRUSTED_LOADER_NAME.equals(loader.getName())) return false;
        if (!loader.getCanonicalPath().equals(expected.getCanonicalPath())) return false;
        if (!loader.getCanonicalPath().startsWith(loaderRoot)) return false;
        if (!loader.isFile() || loader.length() <= 0) return false;

        KeshavOwner6 secure = new KeshavOwner6(context);
        String expectedHash = secure.getSt(KeshavOwner5.LOADER_HASH_KEY, "");
        String expectedSize = secure.getSt(KeshavOwner5.LOADER_SIZE_KEY, "");

        if (expectedHash.isEmpty() || expectedSize.isEmpty()) {
            long age = Math.abs(System.currentTimeMillis() - loader.lastModified());

            // A brand-new file can briefly exist while the updater is binding its hash.
            if (age <= 120000L) {
                return true;
            }

            // Migration from an older build: remove the unbound loader and let the updater
            // fetch a fresh trusted copy. Failure to remove means integrity cannot be trusted.
            try {
                return loader.delete() || !loader.exists();
            } catch (Throwable ignored) {
                return false;
            }
        }

        if (!Long.toString(loader.length()).equals(expectedSize)) return false;

        String actualHash = KeshavOwner5.sha256File(loader);
        if (actualHash == null || !expectedHash.equalsIgnoreCase(actualHash)) {
            return false;
        }

        long boundSize;
        try {
            boundSize = Long.parseLong(expectedSize);
        } catch (Throwable ignored) {
            return false;
        }

        try {
            return KeshavOwner2.nativeVerifyServerLoader(
                    context,
                    expectedHash,
                    boundSize);
        } catch (Throwable ignored) {
            return false;
        }
    }


    private static boolean verifySdkRuntimeArtifacts(Context context) throws Exception {
        File root = context.getNoBackupFilesDir();
        if (root == null) return false;

        File nativeDir = new File(root, "native");
        if (!nativeDir.exists()) return true;
        if (!nativeDir.isDirectory()) return false;

        String rootPath = root.getCanonicalPath() + File.separator;
        String nativePath = nativeDir.getCanonicalPath() + File.separator;
        if (!nativePath.startsWith(rootPath)) return false;

        File[] files = nativeDir.listFiles();
        if (files == null) return false;

        Set<String> allowed = new HashSet<>();
        allowed.add("KESHAVXOWNER.so");
        allowed.add("libpubgm.so");
        allowed.add("libkorea.so");

        for (File file : files) {
            if (file == null) continue;
            if (file.isDirectory()) return false;
            if (!file.getName().toLowerCase(Locale.US).endsWith(".so")) continue;

            if (!allowed.contains(file.getName())) return false;
            if (!file.isFile() || file.length() < 4) return false;

            String canonical = file.getCanonicalPath();
            if (!canonical.startsWith(nativePath)) return false;

            try (java.io.FileInputStream in = new java.io.FileInputStream(file)) {
                if (in.read() != 0x7f
                        || in.read() != 'E'
                        || in.read() != 'L'
                        || in.read() != 'F') {
                    return false;
                }
            }
        }

        return true;
    }

    private static void collectSoFiles(File dir, List<File> out) {
        if (dir == null || !dir.exists()) return;

        File[] files = dir.listFiles();
        if (files == null) return;

        for (File file : files) {
            if (file == null) continue;

            if (file.isDirectory()) {
                collectSoFiles(file, out);
            } else if (file.getName().toLowerCase(Locale.US).endsWith(".so")) {
                out.add(file);
            }
        }
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
