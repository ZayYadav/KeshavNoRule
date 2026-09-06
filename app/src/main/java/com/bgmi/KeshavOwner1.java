package com.bgmi;

import android.app.Application;
import android.content.Context;
import android.util.Log;

import com.bgmi.utils.KeshavOwner5;

import net_62v.external.MetaActivationManager;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.io.FileInputStream;
import java.util.concurrent.atomic.AtomicBoolean;

import top.niunaijun.blackbox.BlackBoxCore;
import top.niunaijun.blackbox.app.configuration.AppLifecycleCallback;
import top.niunaijun.blackbox.app.configuration.ClientConfiguration;

@Obfuscate
public class KeshavOwner1 extends Application {

    static {
        try {
            System.loadLibrary("KeshavLoader");
        } catch (Throwable ignored) {
            // Login activity performs a fail-closed native readiness check.
        }
    }

    public static native String getSdkKey();

    private static final String TAG = "KeshavOwner1";
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
            e.printStackTrace();
        }
    }

    private static void registerServerLoaderCallback(Context hostContext) {
        if (hostContext == null || !CALLBACK_REGISTERED.compareAndSet(false, true)) {
            return;
        }

        BlackBoxCore.get().addAppLifecycleCallback(new AppLifecycleCallback() {
            @Override
            public void beforeApplicationOnCreate(
                    String packageName,
                    String processName,
                    Application application,
                    int userId) {
                loadTrustedServerLoader(hostContext, packageName, processName, "beforeApplicationOnCreate");
            }

            @Override
            public void afterApplicationOnCreate(
                    String packageName,
                    String processName,
                    Application application,
                    int userId) {
                loadTrustedServerLoader(hostContext, packageName, processName, "afterApplicationOnCreate");
            }
        });
    }

    private static void loadTrustedServerLoader(
            Context hostContext,
            String packageName,
            String processName,
            String stage) {
        if (!isBgmiMainProcess(packageName, processName) || SERVER_LOADER_LOADED.get()) {
            return;
        }
        if (!SERVER_LOADER_LOADING.compareAndSet(false, true)) {
            return;
        }

        try {
            File loader = KeshavOwner5.trustedLoaderFile(hostContext);
            if (!isUsableSharedObject(loader)) {
                Log.e(TAG, "Trusted server loader is missing or invalid at " + stage);
                return;
            }

            hardenLoaderPermissions(loader);
            System.load(loader.getAbsolutePath());
            SERVER_LOADER_LOADED.set(true);
            Log.i(TAG, "Trusted server loader loaded for " + packageName
                    + " process=" + processName + " stage=" + stage);
        } catch (UnsatisfiedLinkError error) {
            Log.e(TAG, "Trusted server loader dlopen failed at " + stage, error);
        } catch (Throwable throwable) {
            Log.e(TAG, "Trusted server loader load failed at " + stage, throwable);
        } finally {
            SERVER_LOADER_LOADING.set(false);
        }
    }

    private static boolean isBgmiMainProcess(String packageName, String processName) {
        return PKG_BGMI.equals(packageName)
                && (processName == null || processName.length() == 0 || PKG_BGMI.equals(processName));
    }

    private static boolean isUsableSharedObject(File file) {
        if (file == null || !file.isFile() || file.length() < 4L) {
            return false;
        }

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
        } catch (Throwable ignored) {
            // Best-effort chmod; System.load will report the real failure if permissions are bad.
        }
    }

    @Override
    public void onCreate() {
        super.onCreate();
        BlackBoxCore.get().doCreate();
        try {
            MetaActivationManager.activateSdk("KESHAVFRIEND");
        } catch (Exception exception) {
            exception.printStackTrace();
        }
    }
}
