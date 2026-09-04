package com.bgmi.utils;

import org.lsposed.lsparanoid.Obfuscate;

import android.app.Activity;
import android.content.Context;
import android.content.SharedPreferences;
import android.os.AsyncTask;
import android.util.Log;

import com.google.android.material.dialog.MaterialAlertDialogBuilder;

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

    // Native URLs
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
        this.context = context;
        this.callback = callback;
    }

    public void setProgressListener(ProgressListener listener) {
        this.progressListener = listener;
    }

    @Override
    protected String doInBackground(String... params) {
        try {
            // 1. Fetch Server Version
            String serverVersion = getServerVersion();
            if (serverVersion == null) {
                Log.e(TAG, "Could not fetch version, continuing to app.");
                return null; 
            }

            SharedPreferences prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE);
            String localVersion = prefs.getString(PREF_VERSION_KEY, "0");

            // 2. Check if update is needed
            if (serverVersion.equals(localVersion)) {
                Log.d(TAG, "Already latest version.");
                return null;
            }

            // 3. Update Required: Clean old files in the 'loader' directory
            Log.d(TAG, "New version detected. Cleaning old files...");
            deleteExistingFiles(context.getFilesDir());

            // 4. Download and Extract
            String err = downloadAndExtract(Link());
            if (err != null) {
                Log.e(TAG, "Download failed: " + err + ". Proceeding anyway.");
                return null; 
            }

            // 5. Save new version on success
            prefs.edit().putString(PREF_VERSION_KEY, serverVersion).apply();
            return null;

        } catch (Exception e) {
            Log.e(TAG, "Background error: " + e.getMessage());
            return null; 
        }
    }

    @Override
    protected void onPostExecute(String result) {
        if (callback != null) {
            callback.onComplete(true);
        }
    }

    private void deleteExistingFiles(File dir) {
        if (dir == null || !dir.exists()) return;

        // Clean base ZIP
        File zipFile = new File(dir, ZIP_NAME);
        if (zipFile.exists()) zipFile.delete();

        // Clean the 'loader' directory specifically
        File loaderDir = new File(dir, "loader");
        if (loaderDir.exists()) {
            File foundSo = findLib(loaderDir, SO_NAME);
            if (foundSo != null && foundSo.exists()) {
                foundSo.delete();
                Log.d(TAG, "Deleted old libbgmi.so from loader.");
            }
        }
    }

    private String downloadAndExtract(String urlString) {
        HttpURLConnection con = null;
        try {
            URL url = new URL(urlString);
            con = (HttpURLConnection) url.openConnection();
            con.setConnectTimeout(10000);
            con.connect();

            if (con.getResponseCode() != HttpURLConnection.HTTP_OK) {
                return "HTTP Error: " + con.getResponseCode();
            }

            int total = con.getContentLength();
            int done = 0;

            InputStream in = con.getInputStream();
            File zipFile = new File(context.getFilesDir(), ZIP_NAME);
            FileOutputStream out = new FileOutputStream(zipFile);

            byte[] buf = new byte[8192];
            int len;

            while ((len = in.read(buf)) != -1) {
                out.write(buf, 0, len);
                done += len;
                if (total > 0 && progressListener != null) {
                    progressListener.onProgress((int) ((done * 100L) / total));
                }
            }

            out.close();
            in.close();

            // FIXED: Create 'loader' sub-directory and extract there
            File targetDir = new File(context.getFilesDir(), "loader");
            if (!targetDir.exists()) targetDir.mkdirs();

            unzip(zipFile, targetDir);
            zipFile.delete();

            // FIXED: Verify extracted file exists in 'loader'
            File so = findLib(targetDir, SO_NAME);
            if (so == null) {
                return "Library file not found in loader after extraction.";
            }

            return null; 

        } catch (Exception e) {
            return e.getMessage();
        } finally {
            if (con != null) con.disconnect();
        }
    }

    private void unzip(File zipFile, File targetDir) throws Exception {
        ZipInputStream zis = new ZipInputStream(new FileInputStream(zipFile));
        ZipEntry entry;
        byte[] buffer = new byte[8192];

        while ((entry = zis.getNextEntry()) != null) {
            File outFile = new File(targetDir, entry.getName());

            if (entry.isDirectory()) {
                outFile.mkdirs();
            } else {
                File parent = outFile.getParentFile();
                if (parent != null) parent.mkdirs();

                FileOutputStream fos = new FileOutputStream(outFile);
                int count;
                while ((count = zis.read(buffer)) != -1) {
                    fos.write(buffer, 0, count);
                }
                fos.close();
            }
            zis.closeEntry();
        }
        zis.close();
    }

    private File findLib(File dir, String name) {
        if (dir == null || !dir.exists()) return null;

        File[] files = dir.listFiles();
        if (files == null) return null;

        for (File f : files) {
            if (f.isDirectory()) {
                File r = findLib(f, name);
                if (r != null) return r;
            } else if (f.getName().equals(name)) {
                return f;
            }
        }
        return null;
    }

    private String getServerVersion() {
        HttpURLConnection con = null;
        try {
            URL url = new URL(Version());
            con = (HttpURLConnection) url.openConnection();
            con.setConnectTimeout(5000);
            con.connect();

            Scanner sc = new Scanner(con.getInputStream());
            if (sc.hasNextLine()) {
                String v = sc.nextLine().trim();
                sc.close();
                return v;
            }
            sc.close();
            return null;
        } catch (Exception e) {
            return null;
        } finally {
            if (con != null) con.disconnect();
        }
    }
}
