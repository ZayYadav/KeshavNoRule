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
public class BoxApplication extends Application {

    static {
        
        System.loadLibrary("akshit"); 
    }
    
    public static native String getSdkKey();
    private static final String TAG = "BoxApplication";
    
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
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    @Override
    public void onCreate() {
        super.onCreate();
        // Initialize BlackBoxCore
        BlackBoxCore.get().doCreate();
        try {
            // Updated SDK activation key
            MetaActivationManager.activateSdk("KESHAVFRIEND");
        } catch (Exception exception) {
            exception.printStackTrace();
        }
    }
}
