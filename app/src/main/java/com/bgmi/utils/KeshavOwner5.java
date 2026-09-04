package com.bgmi.utils;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.AsyncTask;
import android.util.Log;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.Scanner;
import java.util.zip.ZipEntry;
import java.util.zip.ZipInputStream;

@Obfuscate
public class KeshavOwner5 extends AsyncTask<String, Integer, String> {

    private static final String TAG = "KeshavOwner5";
    private static final long MAX_DOWNLOAD_BYTES = 100L * 1024L * 1024L;
    private static final long MAX_EXTRACTED_BYTES = 512L * 1024L * 1024L;

    public static final String TRUSTED_LOADER_NAME = "libbgmi.so";
    public static final String LOADER_HASH_KEY = "ko_loader_hash_v2";
    public static final String LOADER_SIZE_KEY = "ko_loader_size_v2";

    public static native String Version();
    public static native String Link();

    public interface Callback {
        void onComplete(boolean success);
    }

    public interface ProgressListener {
        void onProgress(int percent);
    }

    private final Context context;
    private final Callback callback;
    private ProgressListener progressListener;
    private volatile boolean operationOk = false;

    private static final String PREF_NAME = "com.bgmi.download";
    private static final String PREF_VERSION_KEY = "version";
    private static final String ZIP_NAME = "imgui.zip";

    public KeshavOwner5(Context context) {
        this(context, null);
    }

    public KeshavOwner5(Context context, Callback callback) {
        this.context = context.getApplicationContext();
        this.callback = callback;
    }

    public void setProgressListener(ProgressListener listener) {
        this.progressListener = listener;
    }

    public static File trustedLoaderFile(Context context) {
        return new File(new File(context.getFilesDir(), "loader"), TRUSTED_LOADER_NAME);
    }

    @Override
    protected String doInBackground(String... params) {
        operationOk = false;

        try {
            String serverVersion = getServerVersion();
            if (serverVersion == null || serverVersion.length() > 128) {
                return "Version check failed";
            }

            SharedPreferences prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE);
            String localVersion = prefs.getString(PREF_VERSION_KEY, "0");
            File currentLoader = trustedLoaderFile(context);

            if (serverVersion.equals(localVersion) && verifyStoredFingerprint(currentLoader)) {
                operationOk = true;
                return null;
            }

            deleteExistingFiles(context.getFilesDir());

            String err = downloadAndExtract(Link());
            if (err != null) {
                return err;
            }

            File loader = trustedLoaderFile(context);
            if (!persistLoaderFingerprint(loader)) {
                safeDelete(loader);
                return "Loader integrity binding failed";
            }

            prefs.edit().putString(PREF_VERSION_KEY, serverVersion).apply();
            operationOk = true;
            return null;

        } catch (Throwable ignored) {
            return "Secure loader update failed";
        }
    }

    @Override
    protected void onPostExecute(String result) {
        if (callback != null) {
            callback.onComplete(operationOk);
        }
    }

    private boolean isHttps(URL url) {
        return url != null && "https".equalsIgnoreCase(url.getProtocol());
    }

    private void deleteExistingFiles(File dir) {
        if (dir == null || !dir.exists()) return;

        safeDelete(new File(dir, ZIP_NAME));

        File loaderDir = new File(dir, "loader");
        if (loaderDir.exists()) {
            deleteSoFilesRecursively(loaderDir);
        }

        try {
            KeshavOwner6 secure = new KeshavOwner6(context);
            secure.setSt(LOADER_HASH_KEY, "");
            secure.setSt(LOADER_SIZE_KEY, "");
        } catch (Throwable ignored) {}
    }

    private String downloadAndExtract(String urlString) {
        HttpURLConnection con = null;
        File zipFile = new File(context.getFilesDir(), ZIP_NAME);

        try {
            URL url = new URL(urlString);
            if (!isHttps(url)) {
                return "HTTPS required";
            }

            con = (HttpURLConnection) url.openConnection();
            con.setConnectTimeout(10000);
            con.setReadTimeout(20000);
            con.setInstanceFollowRedirects(true);
            con.setUseCaches(false);
            con.connect();

            int code = con.getResponseCode();
            if (!isHttps(con.getURL())) {
                return "Unsafe redirect rejected";
            }
            if (code < 200 || code >= 300) {
                return "HTTP Error: " + code;
            }

            long total = con.getContentLengthLong();
            if (total > MAX_DOWNLOAD_BYTES) {
                return "Download too large";
            }

            long done = 0;
            try (InputStream in = con.getInputStream();
                 FileOutputStream out = new FileOutputStream(zipFile)) {
                byte[] buf = new byte[8192];
                int len;
                while ((len = in.read(buf)) != -1) {
                    done += len;
                    if (done > MAX_DOWNLOAD_BYTES) {
                        return "Download limit exceeded";
                    }
                    out.write(buf, 0, len);

                    if (total > 0 && progressListener != null) {
                        progressListener.onProgress((int) ((done * 100L) / total));
                    }
                }
            }

            File targetDir = new File(context.getFilesDir(), "loader");
            if (!targetDir.exists() && !targetDir.mkdirs()) {
                return "Could not create loader directory";
            }

            unzipSafely(zipFile, targetDir);

            File loader = normalizeTrustedLoader(targetDir);
            if (loader == null || !loader.isFile() || loader.length() <= 0) {
                return "Trusted loader missing after extraction";
            }

            if (loader.length() > MAX_DOWNLOAD_BYTES) {
                return "Trusted loader exceeds size limit";
            }

            try {
                loader.setReadable(true, true);
                loader.setWritable(false, false);
            } catch (Throwable ignored) {}

            return null;

        } catch (SecurityException e) {
            return "Loader archive integrity rejected";
        } catch (Throwable ignored) {
            return "Secure download failed";
        } finally {
            if (con != null) con.disconnect();
            safeDelete(zipFile);
        }
    }

    private void unzipSafely(File zipFile, File targetDir) throws Exception {
        String targetRoot = targetDir.getCanonicalPath() + File.separator;
        long extracted = 0;

        try (ZipInputStream zis = new ZipInputStream(new FileInputStream(zipFile))) {
            ZipEntry entry;
            byte[] buffer = new byte[8192];

            while ((entry = zis.getNextEntry()) != null) {
                String entryName = entry.getName();
                if (entryName == null || entryName.isEmpty()) {
                    throw new SecurityException("Bad zip entry");
                }

                File outFile = new File(targetDir, entryName);
                String canonical = outFile.getCanonicalPath();

                if (!canonical.startsWith(targetRoot)) {
                    throw new SecurityException("Zip traversal");
                }

                if (!entry.isDirectory() && entryName.toLowerCase(Locale.US).endsWith(".so")) {
                    if (!TRUSTED_LOADER_NAME.equals(outFile.getName())) {
                        throw new SecurityException("Unexpected shared library");
                    }
                }

                if (entry.isDirectory()) {
                    if (!outFile.exists() && !outFile.mkdirs()) {
                        throw new SecurityException("Could not create directory");
                    }
                } else {
                    File parent = outFile.getParentFile();
                    if (parent != null && !parent.exists() && !parent.mkdirs()) {
                        throw new SecurityException("Could not create parent");
                    }

                    try (FileOutputStream fos = new FileOutputStream(outFile)) {
                        int count;
                        while ((count = zis.read(buffer)) != -1) {
                            extracted += count;
                            if (extracted > MAX_EXTRACTED_BYTES) {
                                throw new SecurityException("Archive too large");
                            }
                            fos.write(buffer, 0, count);
                        }
                    }
                }

                zis.closeEntry();
            }
        }
    }

    private File normalizeTrustedLoader(File targetDir) throws Exception {
        List<File> soFiles = new ArrayList<>();
        collectSoFiles(targetDir, soFiles);

        if (soFiles.size() != 1) {
            throw new SecurityException("Unexpected loader library count");
        }

        File found = soFiles.get(0);
        if (!TRUSTED_LOADER_NAME.equals(found.getName())) {
            throw new SecurityException("Unexpected loader name");
        }

        File expected = new File(targetDir, TRUSTED_LOADER_NAME);
        String expectedPath = expected.getCanonicalPath();
        String foundPath = found.getCanonicalPath();

        if (!foundPath.equals(expectedPath)) {
            if (expected.exists()) safeDelete(expected);

            if (!found.renameTo(expected)) {
                copyFile(found, expected);
                safeDelete(found);
            }
        }

        if (!expected.getCanonicalPath().equals(expectedPath)) {
            throw new SecurityException("Loader path normalization failed");
        }

        return expected;
    }

    private void collectSoFiles(File dir, List<File> out) {
        if (dir == null || !dir.exists()) return;
        File[] files = dir.listFiles();
        if (files == null) return;

        for (File file : files) {
            if (file.isDirectory()) {
                collectSoFiles(file, out);
            } else if (file.getName().toLowerCase(Locale.US).endsWith(".so")) {
                out.add(file);
            }
        }
    }

    private void deleteSoFilesRecursively(File dir) {
        if (dir == null || !dir.exists()) return;
        File[] files = dir.listFiles();
        if (files == null) return;

        for (File file : files) {
            if (file.isDirectory()) {
                deleteSoFilesRecursively(file);
            } else if (file.getName().toLowerCase(Locale.US).endsWith(".so")) {
                safeDelete(file);
            }
        }
    }

    private void copyFile(File src, File dst) throws Exception {
        try (FileInputStream in = new FileInputStream(src);
             FileOutputStream out = new FileOutputStream(dst)) {
            byte[] buffer = new byte[8192];
            int n;
            while ((n = in.read(buffer)) != -1) {
                out.write(buffer, 0, n);
            }
        }
    }

    private boolean persistLoaderFingerprint(File loader) {
        try {
            if (loader == null || !loader.isFile()) return false;
            String hash = sha256File(loader);
            if (hash == null || hash.length() != 64) return false;

            KeshavOwner6 secure = new KeshavOwner6(context);
            secure.setSt(LOADER_HASH_KEY, hash);
            secure.setSt(LOADER_SIZE_KEY, Long.toString(loader.length()));
            return true;
        } catch (Throwable ignored) {
            return false;
        }
    }

    private boolean verifyStoredFingerprint(File loader) {
        try {
            if (loader == null || !loader.isFile() || loader.length() <= 0) return false;

            String expectedPath = trustedLoaderFile(context).getCanonicalPath();
            if (!loader.getCanonicalPath().equals(expectedPath)) return false;

            KeshavOwner6 secure = new KeshavOwner6(context);
            String expectedHash = secure.getSt(LOADER_HASH_KEY, "");
            String expectedSize = secure.getSt(LOADER_SIZE_KEY, "");

            if (expectedHash.isEmpty() || expectedSize.isEmpty()) {
                // Migration path: force a fresh server download to establish a secure binding.
                return false;
            }

            if (!Long.toString(loader.length()).equals(expectedSize)) return false;
            return expectedHash.equalsIgnoreCase(sha256File(loader));

        } catch (Throwable ignored) {
            return false;
        }
    }

    public static String sha256File(File file) {
        if (file == null || !file.isFile()) return null;

        try (FileInputStream in = new FileInputStream(file)) {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] buffer = new byte[8192];
            int n;
            while ((n = in.read(buffer)) != -1) {
                digest.update(buffer, 0, n);
            }

            byte[] hash = digest.digest();
            StringBuilder out = new StringBuilder(hash.length * 2);
            for (byte b : hash) {
                out.append(String.format(Locale.US, "%02x", b & 0xff));
            }
            return out.toString();

        } catch (Throwable ignored) {
            return null;
        }
    }

    private void safeDelete(File file) {
        if (file == null) return;
        try {
            if (file.exists()) file.delete();
        } catch (Throwable ignored) {}
    }

    private String getServerVersion() {
        HttpURLConnection con = null;

        try {
            URL url = new URL(Version());
            if (!isHttps(url)) return null;

            con = (HttpURLConnection) url.openConnection();
            con.setConnectTimeout(5000);
            con.setReadTimeout(10000);
            con.setInstanceFollowRedirects(true);
            con.setUseCaches(false);
            con.connect();

            int code = con.getResponseCode();
            if (!isHttps(con.getURL())) return null;
            if (code < 200 || code >= 300) return null;

            try (Scanner sc = new Scanner(con.getInputStream())) {
                return sc.hasNextLine() ? sc.nextLine().trim() : null;
            }

        } catch (Throwable ignored) {
            return null;
        } finally {
            if (con != null) con.disconnect();
        }
    }
}
