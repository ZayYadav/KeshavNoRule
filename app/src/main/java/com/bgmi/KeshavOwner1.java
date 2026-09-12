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
            // Host JNI bridge only. No cloned-app library is downloaded or injected.
            System.loadLibrary("KeshavLoader");
        } catch (Throwable ignored) {
            // Login activity performs a fail-closed native readiness check.
        }
    }

    public static native String getSdkKey();

    private static final String TAG = "ParallaxVirtual";

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
        } catch (Throwable e) {
            Log.e(TAG, "Virtual core attach failed", e);
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

        // Install diagnostics before the virtual core starts so startup crashes from
        // host/server/virtual processes are captured in the private crash-report store.
        ParallaxCrashReporter.install(this);

        try {
            BlackBoxCore.get().doCreate();
        } catch (Throwable throwable) {
            Log.e(TAG, "Virtual core create failed", throwable);
            if (throwable instanceof RuntimeException) {
                throw (RuntimeException) throwable;
            }
            if (throwable instanceof Error) {
                throw (Error) throwable;
            }
            throw new RuntimeException("Virtual core create failed", throwable);
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
