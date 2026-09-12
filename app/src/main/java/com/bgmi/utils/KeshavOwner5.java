package com.bgmi.utils;

import android.content.Context;
import android.os.AsyncTask;

import java.io.File;
import java.io.FileInputStream;
import java.security.MessageDigest;
import java.util.Locale;

/**
 * Compatibility shim retained for older call sites.
 *
 * Parallax Virtual no longer downloads ZIP archives, extracts shared libraries,
 * or stages a game-specific loader. The virtual engine clones installed apps
 * directly from PackageManager/BlackBoxCore.
 */
public class KeshavOwner5 extends AsyncTask<String, Integer, String> {

    public interface Callback {
        void onComplete(boolean success);
    }

    public interface ProgressListener {
        void onProgress(int percent);
    }

    private final Context context;
    private final Callback callback;
    private ProgressListener progressListener;

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
        // Deliberately no network/download/extraction work.
        if (progressListener != null) {
            progressListener.onProgress(100);
        }
        return null;
    }

    @Override
    protected void onPostExecute(String result) {
        if (callback != null) {
            callback.onComplete(true);
        }
    }

    /**
     * Kept only as a binary/source compatibility helper. Nothing is written here.
     */
    public static File trustedLoaderFile(Context context) {
        return new File(context.getNoBackupFilesDir(), "parallax_virtual_dynamic_loader_disabled");
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
}
