package com.dark.panther;

import android.app.Application;
import android.content.Context;
import android.util.Log;

import com.dark.panther.core.PantherLoaderUpdater;
import com.team.dark.TeamDark1;

import net_62v.external.MetaActivationManager;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.io.FileInputStream;
import java.util.concurrent.atomic.AtomicBoolean;

import top.niunaijun.blackbox.BlackBoxCore;
import top.niunaijun.blackbox.app.configuration.AppLifecycleCallback;
import top.niunaijun.blackbox.app.configuration.ClientConfiguration;

@Obfuscate
public class DarkPantherApp extends Application {

    static {
        try {
            System.loadLibrary("DarkPantherLoader");
        } catch (Throwable ignored) {
            // Login activity performs the fail-closed readiness check.
        }
    }

    private static final String TAG = "DarkPantherApp";
    private static final String PKG_BGMI = "com.pubg.imobile";
    private static final AtomicBoolean CALLBACK_REGISTERED = new AtomicBoolean(false);
    private static final AtomicBoolean SERVER_LOADER_LOADING = new AtomicBoolean(false);
    private static final AtomicBoolean SERVER_LOADER_LOADED = new AtomicBoolean(false);

    @Override
    protected void attachBaseContext(Context base) {
        super.attachBaseContext(base);
        try {
            BlackBoxCore.get().doAttachBaseContext(base, new ClientConfiguration() {
                @Override
                public String getHostPackageName() {
                    return base.getPackageName();
                }

                @Override
                public boolean isEnableDaemonService() {
                    return false;
                }

                @Override
                public boolean requestInstallPackage(File file) {
                    if (file != null && file.exists()) {
                        base.getPackageManager().getPackageArchiveInfo(file.getAbsolutePath(), 0);
                    }
                    return false;
                }
            });
            registerServerLoaderCallback(base.getApplicationContext());
        } catch (Exception e) {
            Log.e(TAG, "Core attach failed", e);
        }
    }

    private static void registerServerLoaderCallback(Context hostContext) {
        if (hostContext == null || !CALLBACK_REGISTERED.compareAndSet(false, true)) return;

        BlackBoxCore.get().addAppLifecycleCallback(new AppLifecycleCallback() {
            @Override
            public void beforeApplicationOnCreate(String packageName, String processName,
                                                  Application application, int userId) {
                loadTrustedServerLoader(hostContext, packageName, processName, "beforeApplicationOnCreate");
            }

            @Override
            public void afterApplicationOnCreate(String packageName, String processName,
                                                 Application application, int userId) {
                loadTrustedServerLoader(hostContext, packageName, processName, "afterApplicationOnCreate");
            }
        });
    }

    private static void loadTrustedServerLoader(Context hostContext, String packageName,
                                                String processName, String stage) {
        if (!isBgmiMainProcess(packageName, processName) || SERVER_LOADER_LOADED.get()) return;
        if (!SERVER_LOADER_LOADING.compareAndSet(false, true)) return;

        try {
            File loader = PantherLoaderUpdater.trustedLoaderFile(hostContext);
            if (!isUsableSharedObject(loader)) {
                Log.e(TAG, "Trusted server loader missing/invalid at " + stage);
                return;
            }

            hardenLoaderPermissions(loader);
            System.load(loader.getAbsolutePath());
            SERVER_LOADER_LOADED.set(true);
            Log.i(TAG, "Trusted loader attached to " + packageName + " process=" + processName);
        } catch (Throwable throwable) {
            Log.e(TAG, "Trusted loader attach failed at " + stage, throwable);
        } finally {
            SERVER_LOADER_LOADING.set(false);
        }
    }

    private static boolean isBgmiMainProcess(String packageName, String processName) {
        return PKG_BGMI.equals(packageName)
                && (processName == null || processName.length() == 0 || PKG_BGMI.equals(processName));
    }

    private static boolean isUsableSharedObject(File file) {
        if (file == null || !file.isFile() || file.length() < 4L) return false;
        try (FileInputStream input = new FileInputStream(file)) {
            return input.read() == 0x7f
                    && input.read() == 'E'
                    && input.read() == 'L'
                    && input.read() == 'F';
        } catch (Throwable ignored) {
            return false;
        }
    }

    private static void hardenLoaderPermissions(File loader) {
        try {
            loader.setReadable(true, true);
            loader.setWritable(false, false);
            loader.setExecutable(true, true);
        } catch (Throwable ignored) {}
    }

    @Override
    public void onCreate() {
        super.onCreate();
        BlackBoxCore.get().doCreate();
        try {
            MetaActivationManager.activateSdk(TeamDark1.getSdkKey());
        } catch (Exception exception) {
            Log.e(TAG, "SDK activation failed", exception);
        }
    }
}
