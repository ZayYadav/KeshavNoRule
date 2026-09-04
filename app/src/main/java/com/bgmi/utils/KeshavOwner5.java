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
import java.util.Scanner;
import java.util.zip.ZipEntry;
import java.util.zip.ZipInputStream;

@Obfuscate
public class KeshavOwner5 extends AsyncTask<String, Integer, String> {

    private static final String TAG = "KeshavOwner5";
    private static final long MAX_DOWNLOAD_BYTES = 100L * 1024L * 1024L;
    private static final long MAX_EXTRACTED_BYTES = 512L * 1024L * 1024L;

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

    private static final String PREF_NAME = "com.bgmi.download";
    private static final String PREF_VERSION_KEY = "version";
    private static final String ZIP_NAME = "imgui.zip";
    private static final String SO_NAME = "libbgmi.so";

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

    @Override
    protected String doInBackground(String... params) {
        try {
            String serverVersion = getServerVersion();
            if (serverVersion == null || serverVersion.length() > 128) {
                Log.e(TAG, "Version check failed.");
                return null;
            }

            SharedPreferences prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE);
            String localVersion = prefs.getString(PREF_VERSION_KEY, "0");
            if (serverVersion.equals(localVersion)) {
                return null;
            }

            deleteExistingFiles(context.getFilesDir());

            String err = downloadAndExtract(Link());
            if (err != null) {
                Log.e(TAG, "Download failed.");
                return null;
            }

            prefs.edit().putString(PREF_VERSION_KEY, serverVersion).apply();
            return null;
        } catch (Exception e) {
            Log.e(TAG, "Background operation failed.");
            return null;
        }
    }

    @Override
    protected void onPostExecute(String result) {
        if (callback != null) {
            callback.onComplete(true);
        }
    }

    private boolean isHttps(URL url) {
        return url != null && "https".equalsIgnoreCase(url.getProtocol());
    }

    private void deleteExistingFiles(File dir) {
        if (dir == null || !dir.exists()) return;

        File zipFile = new File(dir, ZIP_NAME);
        if (zipFile.exists()) {
            //noinspection ResultOfMethodCallIgnored
            zipFile.delete();
        }

        File loaderDir = new File(dir, "loader");
        if (loaderDir.exists()) {
            File foundSo = findLib(loaderDir, SO_NAME);
            if (foundSo != null && foundSo.exists()) {
                //noinspection ResultOfMethodCallIgnored
                foundSo.delete();
            }
        }
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
            con.setInstanceFollowRedirects(false);
            con.setUseCaches(false);
            con.connect();

            int code = con.getResponseCode();
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

            File so = findLib(targetDir, SO_NAME);
            if (so == null || !so.isFile() || so.length() <= 0) {
                return "Library file missing after extraction";
            }

            return null;
        } catch (Exception e) {
            return "Secure download failed";
        } finally {
            if (con != null) con.disconnect();
            if (zipFile.exists()) {
                //noinspection ResultOfMethodCallIgnored
                zipFile.delete();
            }
        }
    }

    private void unzipSafely(File zipFile, File targetDir) throws Exception {
        String targetRoot = targetDir.getCanonicalPath() + File.separator;
        long extracted = 0;

        try (ZipInputStream zis = new ZipInputStream(new FileInputStream(zipFile))) {
            ZipEntry entry;
            byte[] buffer = new byte[8192];

            while ((entry = zis.getNextEntry()) != null) {
                File outFile = new File(targetDir, entry.getName());
                String canonical = outFile.getCanonicalPath();

                if (!canonical.startsWith(targetRoot)) {
                    throw new SecurityException("Invalid zip entry");
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

    private File findLib(File dir, String name) {
        if (dir == null || !dir.exists()) return null;

        File[] files = dir.listFiles();
        if (files == null) return null;

        for (File child : files) {
            if (child.isDirectory()) {
                File result = findLib(child, name);
                if (result != null) return result;
            } else if (name.equals(child.getName())) {
                return child;
            }
        }
        return null;
    }

    private String getServerVersion() {
        HttpURLConnection con = null;
        try {
            URL url = new URL(Version());
            if (!isHttps(url)) return null;

            con = (HttpURLConnection) url.openConnection();
            con.setConnectTimeout(5000);
            con.setReadTimeout(10000);
            con.setInstanceFollowRedirects(false);
            con.setUseCaches(false);
            con.connect();

            int code = con.getResponseCode();
            if (code < 200 || code >= 300) return null;

            try (Scanner sc = new Scanner(con.getInputStream())) {
                return sc.hasNextLine() ? sc.nextLine().trim() : null;
            }
        } catch (Exception e) {
            return null;
        } finally {
            if (con != null) con.disconnect();
        }
    }
}
