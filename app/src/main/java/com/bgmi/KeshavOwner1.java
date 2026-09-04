package com.bgmi;

import android.app.Application;
import android.content.Context;
import android.content.pm.PackageManager;
import android.content.pm.PackageInfo;
import android.content.pm.Signature;
import android.util.Log;
import top.niunaijun.blackbox.BlackBoxCore;
import java.io.File;
import net_62v.external.MetaActivationManager;
import org.lsposed.lsparanoid.Obfuscate;
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
    private static volatile boolean ATTACH_READY = false;
    private static volatile boolean CORE_READY = false;
    private static volatile boolean SDK_READY = false;
    private static volatile String STARTUP_ERROR = "";

    public static boolean isCoreReady() {
        return CORE_READY;
    }

    public static String getStartupError() {
        return STARTUP_ERROR;
    }

    public static boolean isSdkReady() {
        return SDK_READY;
    }

    public static boolean refreshSdkReady() {
        try {
            SDK_READY = MetaActivationManager.getActivatedStatus();
        } catch (Throwable ignored) {
            SDK_READY = false;
        }
        return SDK_READY;
    }

    public static void requestSdkActivation() {
        try {
            MetaActivationManager.activateSdk("KESHAVFRIEND");
        } catch (Throwable ignored) {
            SDK_READY = false;
        }
    }
    
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

                //@Override
                public boolean requestInstallPackage(File file) {
                    // Fixed: Added PackageInfo retrieval logic
                    if (file != null && file.exists()) {
                        PackageInfo packageInfo = base.getPackageManager().getPackageArchiveInfo(file.getAbsolutePath(), 0);
                    }
                    return false;
                }
            });
            ATTACH_READY = true;
        } catch (Throwable ignored) {
            ATTACH_READY = false;
            CORE_READY = false;
            STARTUP_ERROR = "Core attach failed";
        }
    }

    @Override
    public void onCreate() {
        super.onCreate();

        CORE_READY = false;

        if (!ATTACH_READY) {
            if (STARTUP_ERROR == null || STARTUP_ERROR.isEmpty()) {
                STARTUP_ERROR = "Core attach failed";
            }
            return;
        }

        STARTUP_ERROR = "";

        try {
            BlackBoxCore.get().doCreate();
            CORE_READY = true;
        } catch (Throwable ignored) {
            CORE_READY = false;
            STARTUP_ERROR = "Runtime core initialization failed";
        }

        if (!CORE_READY) {
            return;
        }

        SDK_READY = false;
        requestSdkActivation();
    }
}
