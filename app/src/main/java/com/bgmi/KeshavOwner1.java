package com.bgmi;

import android.app.ActivityManager;
import android.app.Application;
import android.content.Context;
import android.os.Build;
import android.os.Process;
import android.util.Log;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.util.List;

import top.niunaijun.blackbox.BlackBoxCore;
import top.niunaijun.blackbox.app.configuration.ClientConfiguration;
import top.niunaijun.blackbox.core.system.api.MetaActivationManager;

@Obfuscate
public class KeshavOwner1 extends Application {

    static {
        try {
            System.loadLibrary("KeshavLoader");
        } catch (Throwable ignored) {
            // Login activity performs the visible native-readiness validation.
        }
    }

    public static native String getSdkKey();

    private static final String TAG = "ParallaxVirtual";
    private static volatile boolean coreAttached;
    private static volatile boolean coreReady;
    private static volatile String coreStartupError = "";

    public static boolean isVirtualCoreReady() {
        return coreReady;
    }

    public static String getVirtualCoreStartupError() {
        return coreStartupError == null ? "" : coreStartupError;
    }

    private static void rememberCoreError(String stage, Throwable throwable) {
        String message = throwable == null ? null : throwable.getMessage();
        String type = throwable == null ? "UnknownError" : throwable.getClass().getSimpleName();
        coreStartupError = stage + ": " + type
                + (message == null || message.trim().isEmpty() ? "" : " - " + message.trim());
    }

    @Override
    protected void attachBaseContext(Context base) {
        super.attachBaseContext(base);
        coreAttached = false;
        coreReady = false;
        coreStartupError = "";

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
            coreAttached = true;
        } catch (Throwable throwable) {
            rememberCoreError("attach", throwable);
            Log.e(TAG, "Virtual core attach failed; keeping app alive", throwable);
        }
    }

    private boolean isHostMainProcess() {
        String processName = null;
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                processName = Application.getProcessName();
            } else {
                ActivityManager manager =
                        (ActivityManager) getSystemService(Context.ACTIVITY_SERVICE);
                if (manager != null) {
                    List<ActivityManager.RunningAppProcessInfo> processes =
                            manager.getRunningAppProcesses();
                    if (processes != null) {
                        int pid = Process.myPid();
                        for (ActivityManager.RunningAppProcessInfo info : processes) {
                            if (info != null && info.pid == pid) {
                                processName = info.processName;
                                break;
                            }
                        }
                    }
                }
            }
        } catch (Throwable ignored) {
        }

        if (processName == null || processName.trim().isEmpty()) {
            processName = getPackageName();
        }
        return getPackageName().equals(processName);
    }

    @Override
    public void onCreate() {
        super.onCreate();

        // Install diagnostics first. Startup failures are recorded, but are no
        // longer rethrown from Application.onCreate().
        ParallaxCrashReporter.install(this);

        if (coreAttached) {
            try {
                BlackBoxCore.get().doCreate();
                coreReady = true;
                coreStartupError = "";
            } catch (Throwable throwable) {
                coreReady = false;
                rememberCoreError("create", throwable);
                Log.e(TAG, "Virtual core create failed; keeping app alive", throwable);
            }
        } else {
            coreReady = false;
            if (coreStartupError == null || coreStartupError.trim().isEmpty()) {
                coreStartupError = "attach: virtual core was not initialized";
            }
        }

        // Activation is host-only. Virtual app processes must not restart it.
        if (!isHostMainProcess()) {
            return;
        }

        try {
            String sdkKey = getSdkKey();
            if (sdkKey != null && !sdkKey.trim().isEmpty()) {
                MetaActivationManager.activateSdk(sdkKey.trim());
                Log.i(TAG, "SDK activation requested at startup");
            } else {
                Log.e(TAG, "SDK activation key is empty");
            }
        } catch (Throwable throwable) {
            Log.e(TAG, "SDK activation startup request failed", throwable);
        }
    }
}
