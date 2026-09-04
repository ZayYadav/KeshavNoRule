package com.bgmi.utils;

import android.annotation.SuppressLint;
import android.app.Dialog;
import android.content.Context;
import android.content.pm.PackageInfo;
import android.os.AsyncTask;
import android.widget.ProgressBar;
import android.widget.TextView;

import com.google.android.material.dialog.MaterialAlertDialogBuilder;
import com.bgmi.R;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import top.niunaijun.blackbox.BlackBoxCore;
import top.niunaijun.blackbox.entity.pm.InstallResult;

@Obfuscate
public class KeshavOwner4 {

    private final Context ctx;

    public static final int INSTALL_APP = 1;
    public static final int COPY_OBB = 3;

    public interface CopyCallback {
        void onCopyCompleted(boolean success);
    }

    public KeshavOwner4(Context ctx) {
        this.ctx = ctx;
    }

    /* ================= MAIN MANAGER ================= */

    @SuppressLint("StaticFieldLeak")
    public void appManager(String pkg, int method) {
        new AsyncTask<Void, Integer, Boolean>() {

            String errorMessage = "";
            Dialog progressDialog;
            ProgressBar progressBar;
            TextView progressText;

            @Override
            protected void onPreExecute() {
                if (method == COPY_OBB) {
                    progressDialog = new Dialog(ctx);
                    progressDialog.setContentView(R.layout.custom_progress_bar);
                    progressDialog.setCancelable(false);

                    progressBar = progressDialog.findViewById(R.id.progressBar);
                    progressText = progressDialog.findViewById(R.id.progressText);

                    progressBar.setMax(100);
                    progressBar.setProgress(0);
                    progressText.setText("0/100");

                    progressDialog.show();
                }
            }

            @Override
            protected Boolean doInBackground(Void... voids) {
                if (method == COPY_OBB) {
                    return copyObbInternal(pkg);
                } else if (method == INSTALL_APP) {
                    return installAppInternal(pkg);
                }
                return false;
            }

            @Override
            protected void onProgressUpdate(Integer... values) {
                if (progressBar != null && progressText != null) {
                    int p = values[0];
                    progressBar.setProgress(p);
                    progressText.setText(p + "/100");
                }
            }

            @Override
            protected void onPostExecute(Boolean success) {
                if (progressDialog != null && progressDialog.isShowing()) {
                    progressDialog.dismiss();
                }

                new MaterialAlertDialogBuilder(ctx)
                        .setTitle(success ? "Success" : "Error")
                        .setMessage(success
                                ? (method == INSTALL_APP
                                ? "App installed successfully"
                                : "OBB copied successfully")
                                : errorMessage)
                        .setPositiveButton("OK", null)
                        .show();
            }

            /* ================= INTERNAL METHODS ================= */

            private boolean copyObbInternal(String packageName) {
                File sourceDir = new File("/storage/emulated/0/Android/obb/" + packageName);
                File destDir = new File(
                        ctx.getExternalFilesDir(null),
                        "SdCard/Android/obb/" + packageName
                );

                if (!sourceDir.exists()) {
                    errorMessage = "OBB not found!";
                    return false;
                }

                if (!destDir.exists() && !destDir.mkdirs()) {
                    errorMessage = "Failed to create destination!";
                    return false;
                }

                File[] files = sourceDir.listFiles();
                if (files == null || files.length == 0) {
                    errorMessage = "No OBB files found!";
                    return false;
                }

                long total = 0, copied = 0;
                for (File f : files) total += f.length();

                try {
                    byte[] buffer = new byte[8192];
                    for (File file : files) {
                        InputStream in = new FileInputStream(file);
                        FileOutputStream out =
                                new FileOutputStream(new File(destDir, file.getName()));

                        int len;
                        while ((len = in.read(buffer)) != -1) {
                            out.write(buffer, 0, len);
                            copied += len;
                            publishProgress((int) ((copied * 100) / total));
                        }
                        in.close();
                        out.close();
                    }
                    return true;
                } catch (IOException e) {
                    errorMessage = e.getMessage();
                    return false;
                }
            }

            private boolean installAppInternal(String pkg) {
                try {
                    PackageInfo info = ctx.getPackageManager().getPackageInfo(pkg, 0);
                    String apkPath = info.applicationInfo.sourceDir;

                    InstallResult result =
                            BlackBoxCore.get().installPackageAsUser(apkPath, 0);

                    if (!result.success) {
                        errorMessage = result.msg;
                        return false;
                    }
                    return true;
                } catch (Exception e) {
                    errorMessage = e.getMessage();
                    return false;
                }
            }

        }.execute();
    }

    /* ================= PUBLIC HELPERS ================= */

    public boolean checkObbContainer(String pkg) {
        File dir = new File(
                ctx.getExternalFilesDir(null),
                "Android/obb/" + pkg
        );
        return dir.exists() && dir.isDirectory();
    }

    public void copyObbFolderAsync(String packageName, CopyCallback callback) {
        new AsyncTask<Void, Void, Boolean>() {

            @Override
            protected Boolean doInBackground(Void... voids) {
                return copyObbInternal(packageName);
            }

            @Override
            protected void onPostExecute(Boolean result) {
                if (callback != null) {
                    callback.onCopyCompleted(result);
                }
            }

            private boolean copyObbInternal(String packageName) {
                File sourceDir = new File("/storage/emulated/0/Android/obb/" + packageName);
                File destDir = new File(
                        ctx.getExternalFilesDir(null),
                        "Android/obb/" + packageName
                );

                if (!sourceDir.exists()) return false;
                if (!destDir.exists()) destDir.mkdirs();

                File[] files = sourceDir.listFiles();
                if (files == null) return false;

                try {
                    byte[] buffer = new byte[8192];
                    for (File file : files) {
                        InputStream in = new FileInputStream(file);
                        FileOutputStream out =
                                new FileOutputStream(new File(destDir, file.getName()));

                        int len;
                        while ((len = in.read(buffer)) != -1) {
                            out.write(buffer, 0, len);
                        }
                        in.close();
                        out.close();
                    }
                    return true;
                } catch (Exception e) {
                    return false;
                }
            }
        }.execute();
    }
}