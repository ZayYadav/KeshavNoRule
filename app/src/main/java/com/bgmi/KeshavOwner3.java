package com.bgmi;

import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.os.Bundle;
import android.os.Debug;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.view.View;
import android.view.WindowManager;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.AppCompatButton;
import androidx.appcompat.widget.SwitchCompat;

import com.bgmi.utils.KeshavOwner7;

import net_62v.external.MetaActivationManager;

import org.lsposed.lsparanoid.Obfuscate;

import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.atomic.AtomicBoolean;

import top.niunaijun.blackbox.BlackBoxCore;
import top.niunaijun.blackbox.entity.pm.InstallResult;

@Obfuscate
public class KeshavOwner3 extends AppCompatActivity {

    private static final int USER_ID = 0;
    private static final long SDK_ACTIVATION_POLL_MS = 500L;
    private static final long SDK_ACTIVATION_TIMEOUT_MS = 60_000L;
    private static final String PREFS_POLICY = "parallax_virtual_policy";
    private static final String KEY_PRIVILEGED_PACKAGES = "sandbox_privileged_packages";

    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private final Handler securityHandler = new Handler(Looper.getMainLooper());
    private final Handler sdkActivationHandler = new Handler(Looper.getMainLooper());
    private final AtomicBoolean sdkActivationPending = new AtomicBoolean(false);

    private LinearLayout clonedAppsContainer;
    private TextView tvCloneCount;
    private Runnable securityGuard;
    private Runnable pendingSdkAction;
    private boolean dashboardReady;
    private boolean doubleBackExit;

    static {
        try {
            System.loadLibrary("KeshavLoader");
        } catch (Throwable ignored) {
        }
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);

        if (Debug.isDebuggerConnected() || Debug.waitingForDebugger()) {
            KeshavOwner9.showIntegrityFailure(this,
                    "Debugger or runtime instrumentation was detected.");
            return;
        }

        if (!KeshavOwner8.verify(this)) {
            KeshavOwner9.showIntegrityFailure(this,
                    "APK signature, package, or host native-library integrity validation failed.");
            return;
        }

        boolean nativeIntegrityOk;
        try {
            nativeIntegrityOk = KeshavOwner2.nativeVerifySignature(this)
                    && KeshavOwner2.nativeCustomIntegrity(this);
        } catch (Throwable ignored) {
            nativeIntegrityOk = false;
        }

        if (!nativeIntegrityOk) {
            KeshavOwner9.showIntegrityFailure(
                    this,
                    "Native runtime validation rejected the dashboard session.");
            return;
        }

        setContentView(R.layout.activity_main);
        clonedAppsContainer = findViewById(R.id.clonedAppsContainer);
        tvCloneCount = findViewById(R.id.tvCloneCount);

        View btnAddApp = findViewById(R.id.btnAddApp);
        if (btnAddApp != null) {
            KeshavOwner7.applyTouchBounce(btnAddApp, this::showInstalledAppPicker);
        }

        dashboardReady = true;
        refreshClonedApps();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (!dashboardReady) return;
        securityHandler.removeCallbacksAndMessages(null);
        securityGuard = KeshavOwner9.installRuntimeGuard(this, securityHandler);
        refreshClonedApps();
    }

    @Override
    protected void onPause() {
        securityHandler.removeCallbacksAndMessages(null);
        securityGuard = null;
        super.onPause();
    }

    @Override
    protected void onDestroy() {
        securityHandler.removeCallbacksAndMessages(null);
        sdkActivationHandler.removeCallbacksAndMessages(null);
        mainHandler.removeCallbacksAndMessages(null);
        pendingSdkAction = null;
        super.onDestroy();
    }

    private void showInstalledAppPicker() {
        runWhenSdkReady(() -> new Thread(() -> {
            final List<AppChoice> choices = loadInstalledApps();
            runOnUiThread(() -> showAppPickerDialog(choices));
        }, "pv-installed-apps").start());
    }

    private List<AppChoice> loadInstalledApps() {
        List<AppChoice> out = new ArrayList<>();
        PackageManager pm = getPackageManager();
        try {
            List<ApplicationInfo> installed = pm.getInstalledApplications(PackageManager.GET_META_DATA);
            for (ApplicationInfo info : installed) {
                if (info == null || info.packageName == null || info.sourceDir == null) continue;
                if (getPackageName().equals(info.packageName)) continue;

                String label;
                try {
                    CharSequence cs = pm.getApplicationLabel(info);
                    label = cs == null ? info.packageName : cs.toString();
                } catch (Throwable ignored) {
                    label = info.packageName;
                }

                boolean system = (info.flags & ApplicationInfo.FLAG_SYSTEM) != 0;
                out.add(new AppChoice(info.packageName, label, system));
            }
        } catch (Throwable ignored) {
        }

        Collections.sort(out, Comparator.comparing(
                choice -> choice.label.toLowerCase(Locale.US)));
        return out;
    }

    private void showAppPickerDialog(List<AppChoice> choices) {
        if (isFinishing() || isDestroyed()) return;
        if (choices == null || choices.isEmpty()) {
            Toast.makeText(this, "No installed apps found", Toast.LENGTH_SHORT).show();
            return;
        }

        CharSequence[] labels = new CharSequence[choices.size()];
        for (int i = 0; i < choices.size(); i++) {
            AppChoice choice = choices.get(i);
            labels[i] = choice.label + "\n" + choice.packageName
                    + (choice.systemApp ? "  • system" : "");
        }

        new AlertDialog.Builder(this)
                .setTitle("Clone installed app")
                .setItems(labels, (dialog, which) -> clonePackage(choices.get(which)))
                .setNegativeButton("Cancel", null)
                .show();
    }

    private void clonePackage(AppChoice choice) {
        if (choice == null) return;
        Toast.makeText(this, "Cloning " + choice.label + "...", Toast.LENGTH_SHORT).show();

        new Thread(() -> {
            String message;
            boolean success = false;
            try {
                if (BlackBoxCore.get().isInstalled(choice.packageName, USER_ID)) {
                    success = true;
                    message = choice.label + " is already cloned";
                } else {
                    InstallResult result = BlackBoxCore.get().installPackageAsUser(
                            choice.packageName, USER_ID);
                    success = result != null && result.success;
                    message = success
                            ? choice.label + " cloned"
                            : "Clone failed: " + (result == null ? "unknown error" : result.msg);
                }
            } catch (Throwable throwable) {
                message = "Clone failed: " + safeMessage(throwable);
            }

            final boolean ok = success;
            final String uiMessage = message;
            runOnUiThread(() -> {
                if (!ok) KeshavOwner7.getInstance().playError();
                Toast.makeText(this, uiMessage, Toast.LENGTH_LONG).show();
                refreshClonedApps();
            });
        }, "pv-clone-app").start();
    }

    private void refreshClonedApps() {
        if (!dashboardReady) return;
        new Thread(() -> {
            List<PackageInfo> packages = new ArrayList<>();
            try {
                List<PackageInfo> installed = BlackBoxCore.get().getInstalledPackages(0, USER_ID);
                if (installed != null) packages.addAll(installed);
            } catch (Throwable ignored) {
            }

            Collections.sort(packages, Comparator.comparing(
                    item -> labelForPackage(item).toLowerCase(Locale.US)));

            runOnUiThread(() -> renderClonedApps(packages));
        }, "pv-refresh-apps").start();
    }

    private String labelForPackage(PackageInfo info) {
        if (info == null || info.packageName == null) return "Unknown app";
        try {
            ApplicationInfo hostInfo = getPackageManager().getApplicationInfo(info.packageName, 0);
            CharSequence label = getPackageManager().getApplicationLabel(hostInfo);
            if (label != null && label.length() > 0) return label.toString();
        } catch (Throwable ignored) {
        }
        return info.packageName;
    }

    private void renderClonedApps(List<PackageInfo> packages) {
        if (clonedAppsContainer == null || isFinishing() || isDestroyed()) return;
        clonedAppsContainer.removeAllViews();

        int count = packages == null ? 0 : packages.size();
        if (tvCloneCount != null) {
            tvCloneCount.setText(count + (count == 1 ? " APP" : " APPS"));
        }

        if (count == 0) {
            TextView empty = new TextView(this);
            empty.setText("No cloned apps yet. Tap + ADD INSTALLED APP.");
            empty.setTextColor(getResources().getColor(R.color.text_muted));
            empty.setTextSize(11f);
            empty.setPadding(dp(14), dp(18), dp(14), dp(18));
            empty.setBackgroundResource(R.drawable.cyber_card_inner);
            clonedAppsContainer.addView(empty, fullWidthParams(dp(10)));
            return;
        }

        for (PackageInfo info : packages) {
            if (info == null || info.packageName == null) continue;
            clonedAppsContainer.addView(createAppCard(info), fullWidthParams(dp(10)));
        }
    }

    private View createAppCard(PackageInfo info) {
        final String packageName = info.packageName;
        final String label = labelForPackage(info);

        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(15), dp(14), dp(15), dp(14));
        card.setBackgroundResource(R.drawable.cyber_card_inner);

        TextView title = new TextView(this);
        title.setText(label);
        title.setTextColor(getResources().getColor(R.color.white));
        title.setTextSize(16f);
        title.setTypeface(title.getTypeface(), android.graphics.Typeface.BOLD);
        card.addView(title);

        TextView pkg = new TextView(this);
        pkg.setText(packageName);
        pkg.setTextColor(getResources().getColor(R.color.text_muted));
        pkg.setTextSize(10f);
        LinearLayout.LayoutParams pkgParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        pkgParams.topMargin = dp(3);
        card.addView(pkg, pkgParams);

        SwitchCompat privilege = new SwitchCompat(this);
        privilege.setText("Sandbox privilege");
        privilege.setTextColor(getResources().getColor(R.color.cyber_orange));
        privilege.setTextSize(11f);
        privilege.setChecked(isSandboxPrivileged(packageName));
        LinearLayout.LayoutParams switchParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        switchParams.topMargin = dp(10);
        card.addView(privilege, switchParams);
        privilege.setOnCheckedChangeListener((buttonView, isChecked) ->
                setSandboxPrivileged(packageName, isChecked));

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        LinearLayout.LayoutParams actionsParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        actionsParams.topMargin = dp(10);
        card.addView(actions, actionsParams);

        AppCompatButton launch = new AppCompatButton(this);
        launch.setText("LAUNCH");
        launch.setTextSize(11f);
        launch.setTextColor(getResources().getColor(R.color.text_dark));
        launch.setBackgroundResource(R.drawable.cyber_btn_primary);
        LinearLayout.LayoutParams launchParams = new LinearLayout.LayoutParams(
                0, dp(48), 1f);
        launchParams.rightMargin = dp(5);
        actions.addView(launch, launchParams);

        AppCompatButton remove = new AppCompatButton(this);
        remove.setText("REMOVE");
        remove.setTextSize(11f);
        remove.setTextColor(getResources().getColor(R.color.white));
        remove.setBackgroundResource(R.drawable.cyber_btn_secondary);
        LinearLayout.LayoutParams removeParams = new LinearLayout.LayoutParams(
                0, dp(48), 1f);
        removeParams.leftMargin = dp(5);
        actions.addView(remove, removeParams);

        KeshavOwner7.applyTouchBounce(launch,
                () -> launchVirtualApp(packageName, label));
        KeshavOwner7.applyTouchBounce(remove,
                () -> confirmRemove(packageName, label));

        return card;
    }

    private void launchVirtualApp(String packageName, String label) {
        runWhenSdkReady(() -> {
            try {
                // This does not grant host/device root. It only chooses whether
                // the virtual engine hides root indicators for this app launch.
                BlackBoxCore.setHideRoot(!isSandboxPrivileged(packageName));
                boolean launched = BlackBoxCore.get().launchApk(packageName, USER_ID);
                if (!launched) {
                    KeshavOwner7.getInstance().playError();
                    Toast.makeText(this, "Unable to launch " + label,
                            Toast.LENGTH_LONG).show();
                }
            } catch (Throwable throwable) {
                KeshavOwner7.getInstance().playError();
                Toast.makeText(this, "Launch failed: " + safeMessage(throwable),
                        Toast.LENGTH_LONG).show();
            }
        });
    }

    private void confirmRemove(String packageName, String label) {
        new AlertDialog.Builder(this)
                .setTitle("Remove virtual copy?")
                .setMessage(label + " will be removed only from Parallax Virtual.")
                .setPositiveButton("Remove", (dialog, which) -> removeVirtualApp(packageName))
                .setNegativeButton("Cancel", null)
                .show();
    }

    private void removeVirtualApp(String packageName) {
        new Thread(() -> {
            String message = "Virtual app removed";
            try {
                BlackBoxCore.get().stopPackage(packageName, USER_ID);
                BlackBoxCore.get().uninstallPackageAsUser(packageName, USER_ID);
                setSandboxPrivileged(packageName, false);
            } catch (Throwable throwable) {
                message = "Remove failed: " + safeMessage(throwable);
            }
            final String uiMessage = message;
            runOnUiThread(() -> {
                Toast.makeText(this, uiMessage, Toast.LENGTH_SHORT).show();
                refreshClonedApps();
            });
        }, "pv-remove-app").start();
    }

    private boolean isSandboxPrivileged(String packageName) {
        SharedPreferences prefs = getSharedPreferences(PREFS_POLICY, MODE_PRIVATE);
        Set<String> current = prefs.getStringSet(KEY_PRIVILEGED_PACKAGES,
                Collections.emptySet());
        return current != null && current.contains(packageName);
    }

    private void setSandboxPrivileged(String packageName, boolean enabled) {
        SharedPreferences prefs = getSharedPreferences(PREFS_POLICY, MODE_PRIVATE);
        Set<String> current = prefs.getStringSet(KEY_PRIVILEGED_PACKAGES,
                Collections.emptySet());
        Set<String> copy = new HashSet<>();
        if (current != null) copy.addAll(current);
        if (enabled) copy.add(packageName); else copy.remove(packageName);
        prefs.edit().putStringSet(KEY_PRIVILEGED_PACKAGES, copy).apply();
    }

    private void runWhenSdkReady(Runnable action) {
        try {
            if (MetaActivationManager.getActivatedStatus()) {
                action.run();
                return;
            }
        } catch (Throwable ignored) {
        }

        pendingSdkAction = action;
        if (!sdkActivationPending.compareAndSet(false, true)) {
            Toast.makeText(this, "SDK activation in progress...", Toast.LENGTH_SHORT).show();
            return;
        }

        String sdkKey;
        try {
            sdkKey = KeshavOwner1.getSdkKey();
        } catch (Throwable throwable) {
            sdkKey = null;
        }

        if (sdkKey == null || sdkKey.trim().isEmpty()) {
            sdkActivationPending.set(false);
            pendingSdkAction = null;
            KeshavOwner7.getInstance().playError();
            Toast.makeText(this, "SDK key unavailable", Toast.LENGTH_LONG).show();
            return;
        }

        try {
            MetaActivationManager.activateSdk(sdkKey.trim());
        } catch (Throwable throwable) {
            sdkActivationPending.set(false);
            pendingSdkAction = null;
            KeshavOwner7.getInstance().playError();
            Toast.makeText(this, "SDK activation could not start", Toast.LENGTH_LONG).show();
            return;
        }

        final long deadline = SystemClock.elapsedRealtime() + SDK_ACTIVATION_TIMEOUT_MS;
        sdkActivationHandler.post(new Runnable() {
            @Override
            public void run() {
                if (isFinishing() || isDestroyed()) {
                    sdkActivationPending.set(false);
                    pendingSdkAction = null;
                    return;
                }

                boolean activated = false;
                try {
                    activated = MetaActivationManager.getActivatedStatus();
                } catch (Throwable ignored) {
                }

                if (activated) {
                    sdkActivationPending.set(false);
                    Runnable next = pendingSdkAction;
                    pendingSdkAction = null;
                    sdkActivationHandler.removeCallbacksAndMessages(null);
                    if (next != null) next.run();
                    return;
                }

                if (SystemClock.elapsedRealtime() >= deadline) {
                    sdkActivationPending.set(false);
                    pendingSdkAction = null;
                    String message = "SDK activation failed";
                    try {
                        String serverMessage = MetaActivationManager.getServerMessage();
                        if (serverMessage != null && !serverMessage.trim().isEmpty()) {
                            message = serverMessage;
                        }
                    } catch (Throwable ignored) {
                    }
                    KeshavOwner7.getInstance().playError();
                    Toast.makeText(KeshavOwner3.this, message, Toast.LENGTH_LONG).show();
                    return;
                }

                sdkActivationHandler.postDelayed(this, SDK_ACTIVATION_POLL_MS);
            }
        });
    }

    private LinearLayout.LayoutParams fullWidthParams(int topMargin) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        params.topMargin = topMargin;
        return params;
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private static String safeMessage(Throwable throwable) {
        if (throwable == null || throwable.getMessage() == null
                || throwable.getMessage().trim().isEmpty()) {
            return "unknown error";
        }
        String value = throwable.getMessage().trim();
        return value.length() > 120 ? value.substring(0, 120) : value;
    }

    @Override
    public void onBackPressed() {
        if (doubleBackExit) {
            finishAffinity();
            return;
        }
        doubleBackExit = true;
        Toast.makeText(this, "Press BACK again to exit", Toast.LENGTH_SHORT).show();
        mainHandler.postDelayed(() -> doubleBackExit = false, 2000L);
    }

    private static final class AppChoice {
        final String packageName;
        final String label;
        final boolean systemApp;

        AppChoice(String packageName, String label, boolean systemApp) {
            this.packageName = packageName;
            this.label = label;
            this.systemApp = systemApp;
        }
    }
}
