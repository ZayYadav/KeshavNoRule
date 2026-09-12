package com.bgmi;

import android.content.ClipData;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.graphics.drawable.Drawable;
import android.net.Uri;
import android.os.Bundle;
import android.os.Debug;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.provider.OpenableColumns;
import android.database.Cursor;
import android.view.Gravity;
import android.view.View;
import android.view.WindowManager;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.AppCompatButton;
import androidx.appcompat.widget.SwitchCompat;
import androidx.core.content.FileProvider;

import com.bgmi.debug.DebugLibraryInspector;
import com.bgmi.utils.KeshavOwner7;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicBoolean;

import top.niunaijun.blackbox.BlackBoxCore;
import top.niunaijun.blackbox.core.system.api.MetaActivationManager;
import top.niunaijun.blackbox.entity.pm.InstallResult;

@Obfuscate
public class KeshavOwner3 extends AppCompatActivity {

    private static final int USER_ID = 0;
    private static final int REQUEST_DEBUG_LIBRARY = 31042;
    private static final long SDK_ACTIVATION_POLL_MS = 500L;
    private static final long SDK_ACTIVATION_TIMEOUT_MS = 60_000L;
    private static final long MAX_DEBUG_LIBRARY_BYTES = 64L * 1024L * 1024L;

    private static final String PREFS_POLICY = "parallax_virtual_policy";
    private static final String KEY_PRIVILEGED_PACKAGES = "sandbox_privileged_packages";
    private static final String KEY_DEBUG_LIB_PREFIX = "debug_lib_";

    public static final String EXTRA_DEBUG_ENABLED = "parallax.debug.enabled";
    public static final String EXTRA_DEBUG_LIBRARY_URI = "parallax.debug.lib_uri";
    public static final String EXTRA_DEBUG_LIBRARY_NAME = "parallax.debug.lib_name";
    public static final String EXTRA_DEBUG_LIBRARY_SHA256 = "parallax.debug.lib_sha256";
    public static final String EXTRA_DEBUG_TARGET_PACKAGE = "parallax.debug.target_package";
    public static final String EXTRA_DEBUG_SESSION_ID = "parallax.debug.session_id";

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

    private String pendingDebugPackage;
    private String pendingDebugLabel;

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
        pendingDebugPackage = null;
        pendingDebugLabel = null;
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
                boolean split = info.splitSourceDirs != null && info.splitSourceDirs.length > 0;
                out.add(new AppChoice(info.packageName, label, system, split));
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
            StringBuilder suffix = new StringBuilder();
            if (choice.systemApp) suffix.append("  • system");
            if (choice.splitApk) suffix.append("  • split APK");
            labels[i] = choice.label + "\n" + choice.packageName + suffix;
        }

        new AlertDialog.Builder(this)
                .setTitle("Clone installed app")
                .setItems(labels, (dialog, which) -> clonePackage(choices.get(which)))
                .setNegativeButton("Cancel", null)
                .show();
    }

    private void clonePackage(AppChoice choice) {
        if (choice == null) return;

        if (choice.splitApk) {
            KeshavOwner7.getInstance().playError();
            new AlertDialog.Builder(this)
                    .setTitle("Split APK detected")
                    .setMessage(choice.label + " uses a base APK plus split modules. "
                            + "This Parallax Virtual engine currently clones a single APK path only, "
                            + "so cloning it would be unreliable. Use a universal/single-APK debug build "
                            + "for deterministic testing.")
                    .setPositiveButton("OK", null)
                    .show();
            return;
        }

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

    private Drawable iconForPackage(String packageName) {
        if (packageName != null) {
            try {
                return getPackageManager().getApplicationIcon(packageName);
            } catch (Throwable ignored) {
            }
        }
        try {
            return getApplicationInfo().loadIcon(getPackageManager());
        } catch (Throwable ignored) {
            return null;
        }
    }

    private boolean isVirtualAppRunning(String packageName) {
        try {
            return BlackBoxCore.get().isAppRunning(packageName, USER_ID);
        } catch (Throwable ignored) {
            return false;
        }
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
        final boolean running = isVirtualAppRunning(packageName);
        final File debugLibrary = getDebugLibraryFile(packageName);

        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(15), dp(14), dp(15), dp(14));
        card.setBackgroundResource(R.drawable.cyber_card_inner);

        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        card.addView(header, new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT));

        ImageView icon = new ImageView(this);
        icon.setScaleType(ImageView.ScaleType.CENTER_CROP);
        Drawable appIcon = iconForPackage(packageName);
        if (appIcon != null) icon.setImageDrawable(appIcon);
        LinearLayout.LayoutParams iconParams = new LinearLayout.LayoutParams(dp(58), dp(58));
        iconParams.rightMargin = dp(12);
        header.addView(icon, iconParams);

        LinearLayout identity = new LinearLayout(this);
        identity.setOrientation(LinearLayout.VERTICAL);
        header.addView(identity, new LinearLayout.LayoutParams(
                0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f));

        TextView title = new TextView(this);
        title.setText(label);
        title.setTextColor(getResources().getColor(R.color.white));
        title.setTextSize(16f);
        title.setTypeface(title.getTypeface(), android.graphics.Typeface.BOLD);
        identity.addView(title);

        TextView pkg = new TextView(this);
        pkg.setText(packageName);
        pkg.setTextColor(getResources().getColor(R.color.text_muted));
        pkg.setTextSize(10f);
        LinearLayout.LayoutParams pkgParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        pkgParams.topMargin = dp(2);
        identity.addView(pkg, pkgParams);

        TextView state = new TextView(this);
        state.setText(running ? "● RUNNING" : "○ READY");
        state.setTextColor(getResources().getColor(
                running ? R.color.cyber_emerald : R.color.cyber_cyan));
        state.setTextSize(9f);
        state.setTypeface(state.getTypeface(), android.graphics.Typeface.BOLD);
        LinearLayout.LayoutParams stateParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        stateParams.topMargin = dp(5);
        identity.addView(state, stateParams);

        SwitchCompat privilege = new SwitchCompat(this);
        privilege.setText("Developer sandbox mode");
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

        TextView privilegeInfo = new TextView(this);
        privilegeInfo.setText("Compatibility/root-visibility profile inside the virtual engine only. "
                + "No host root or cross-app memory permission is granted.");
        privilegeInfo.setTextColor(getResources().getColor(R.color.text_muted));
        privilegeInfo.setTextSize(9f);
        LinearLayout.LayoutParams infoParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        infoParams.topMargin = dp(2);
        card.addView(privilegeInfo, infoParams);

        TextView debugState = new TextView(this);
        debugState.setText(debugLibrary == null
                ? "Debug bridge: no library selected"
                : "Debug bridge: " + debugLibrary.getName());
        debugState.setTextColor(getResources().getColor(
                debugLibrary == null ? R.color.text_muted : R.color.cyber_cyan));
        debugState.setTextSize(9f);
        LinearLayout.LayoutParams debugStateParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        debugStateParams.topMargin = dp(7);
        card.addView(debugState, debugStateParams);

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        LinearLayout.LayoutParams actionsParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        actionsParams.topMargin = dp(11);
        card.addView(actions, actionsParams);

        AppCompatButton launch = new AppCompatButton(this);
        launch.setText(running ? "OPEN" : "LAUNCH");
        launch.setTextSize(10f);
        launch.setTextColor(getResources().getColor(R.color.text_dark));
        launch.setBackgroundResource(R.drawable.cyber_btn_primary);
        LinearLayout.LayoutParams launchParams = new LinearLayout.LayoutParams(
                0, dp(46), 1f);
        launchParams.rightMargin = dp(4);
        actions.addView(launch, launchParams);

        AppCompatButton stop = new AppCompatButton(this);
        stop.setText("STOP");
        stop.setEnabled(running);
        stop.setAlpha(running ? 1f : 0.45f);
        stop.setTextSize(10f);
        stop.setTextColor(getResources().getColor(R.color.white));
        stop.setBackgroundResource(R.drawable.cyber_btn_secondary);
        LinearLayout.LayoutParams stopParams = new LinearLayout.LayoutParams(
                0, dp(46), 1f);
        stopParams.leftMargin = dp(4);
        stopParams.rightMargin = dp(4);
        actions.addView(stop, stopParams);

        AppCompatButton remove = new AppCompatButton(this);
        remove.setText("REMOVE");
        remove.setTextSize(10f);
        remove.setTextColor(getResources().getColor(R.color.white));
        remove.setBackgroundResource(R.drawable.cyber_btn_secondary);
        LinearLayout.LayoutParams removeParams = new LinearLayout.LayoutParams(
                0, dp(46), 1f);
        removeParams.leftMargin = dp(4);
        actions.addView(remove, removeParams);

        AppCompatButton developerLab = new AppCompatButton(this);
        developerLab.setText(debugLibrary == null ? "DEVELOPER LAB" : "DEVELOPER LAB • LIB READY");
        developerLab.setTextSize(10f);
        developerLab.setTextColor(getResources().getColor(R.color.white));
        developerLab.setBackgroundResource(R.drawable.cyber_btn_secondary);
        LinearLayout.LayoutParams labParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT, dp(46));
        labParams.topMargin = dp(8);
        card.addView(developerLab, labParams);

        KeshavOwner7.applyTouchBounce(launch,
                () -> launchVirtualApp(packageName, label));
        if (running) {
            KeshavOwner7.applyTouchBounce(stop,
                    () -> stopVirtualApp(packageName, label));
        }
        KeshavOwner7.applyTouchBounce(remove,
                () -> confirmRemove(packageName, label));
        KeshavOwner7.applyTouchBounce(developerLab,
                () -> showDeveloperLab(packageName, label));

        return card;
    }

    private void launchVirtualApp(String packageName, String label) {
        runWhenSdkReady(() -> {
            try {
                // This toggles only root-visibility compatibility inside the virtual engine.
                BlackBoxCore.setHideRoot(!isSandboxPrivileged(packageName));

                boolean launched = launchWithOptionalDebugBridge(packageName);
                if (!launched) {
                    KeshavOwner7.getInstance().playError();
                    Toast.makeText(this, "Unable to launch " + label,
                            Toast.LENGTH_LONG).show();
                } else {
                    mainHandler.postDelayed(this::refreshClonedApps, 700L);
                }
            } catch (Throwable throwable) {
                KeshavOwner7.getInstance().playError();
                Toast.makeText(this, "Launch failed: " + safeMessage(throwable),
                        Toast.LENGTH_LONG).show();
            }
        });
    }

    /**
     * Debug libraries are never injected by the host. If a .so is selected, the
     * launch Intent receives a one-time FileProvider URI and metadata. A developer-owned
     * debug build must explicitly opt in and load that URI itself.
     */
    private boolean launchWithOptionalDebugBridge(String packageName) {
        File debugLibrary = getDebugLibraryFile(packageName);
        if (debugLibrary == null) {
            return BlackBoxCore.get().launchApk(packageName, USER_ID);
        }

        Intent launchIntent = BlackBoxCore.getBPackageManager()
                .getLaunchIntentForPackage(packageName, USER_ID);
        if (launchIntent == null) return false;
        if (launchIntent.getComponent() == null && launchIntent.getPackage() == null) return false;

        Uri uri = FileProvider.getUriForFile(
                this,
                getPackageName() + ".debugfiles",
                debugLibrary);

        launchIntent.putExtra(EXTRA_DEBUG_ENABLED, true);
        launchIntent.putExtra(EXTRA_DEBUG_LIBRARY_URI, uri.toString());
        launchIntent.putExtra(EXTRA_DEBUG_LIBRARY_NAME, debugLibrary.getName());
        try {
            launchIntent.putExtra(EXTRA_DEBUG_LIBRARY_SHA256,
                    DebugLibraryInspector.sha256(debugLibrary));
        } catch (Exception exception) {
            return false;
        }
        launchIntent.putExtra(EXTRA_DEBUG_TARGET_PACKAGE, packageName);
        launchIntent.putExtra(EXTRA_DEBUG_SESSION_ID, UUID.randomUUID().toString());
        launchIntent.setClipData(ClipData.newRawUri("Parallax Debug Library", uri));
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_GRANT_READ_URI_PERMISSION);

        BlackBoxCore.get().onBeforeMainLaunchApk(packageName, USER_ID);
        BlackBoxCore.get().startActivity(launchIntent, USER_ID);
        return true;
    }

    private void stopVirtualApp(String packageName, String label) {
        try {
            BlackBoxCore.get().stopPackage(packageName, USER_ID);
            Toast.makeText(this, label + " stopped", Toast.LENGTH_SHORT).show();
            mainHandler.postDelayed(this::refreshClonedApps, 300L);
        } catch (Throwable throwable) {
            KeshavOwner7.getInstance().playError();
            Toast.makeText(this, "Stop failed: " + safeMessage(throwable),
                    Toast.LENGTH_LONG).show();
        }
    }

    private void confirmRemove(String packageName, String label) {
        new AlertDialog.Builder(this)
                .setTitle("Remove virtual copy?")
                .setMessage(label + " will be removed only from Parallax Virtual. "
                        + "Its Developer Lab library association will also be cleared.")
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
                clearDebugLibraryInternal(packageName);
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

    private void showDeveloperLab(String packageName, String label) {
        File selected = getDebugLibraryFile(packageName);
        String selectedText = selected == null ? "none" : selected.getName();
        String[] options = new String[]{
                "Load / replace debug .so",
                "Clear selected debug .so",
                "Show latest crash report",
                "Debug bridge integration help"
        };

        new AlertDialog.Builder(this)
                .setTitle("Developer Lab • " + label)
                .setMessage("Selected debug library: " + selectedText
                        + "\n\nLibraries are handed to developer-owned debug builds through an explicit "
                        + "launch URI. Parallax Virtual does not silently inject them into unrelated apps.")
                .setItems(options, (dialog, which) -> {
                    if (which == 0) {
                        selectDebugLibrary(packageName, label);
                    } else if (which == 1) {
                        clearDebugLibrary(packageName);
                    } else if (which == 2) {
                        showLatestCrashReport();
                    } else if (which == 3) {
                        showDebugBridgeHelp();
                    }
                })
                .setNegativeButton("Close", null)
                .show();
    }

    private void selectDebugLibrary(String packageName, String label) {
        pendingDebugPackage = packageName;
        pendingDebugLabel = label;

        Intent pick = new Intent(Intent.ACTION_OPEN_DOCUMENT);
        pick.addCategory(Intent.CATEGORY_OPENABLE);
        pick.setType("*/*");
        try {
            startActivityForResult(pick, REQUEST_DEBUG_LIBRARY);
        } catch (Throwable throwable) {
            pendingDebugPackage = null;
            pendingDebugLabel = null;
            Toast.makeText(this, "File picker unavailable: " + safeMessage(throwable),
                    Toast.LENGTH_LONG).show();
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQUEST_DEBUG_LIBRARY) return;

        final String packageName = pendingDebugPackage;
        final String label = pendingDebugLabel;
        pendingDebugPackage = null;
        pendingDebugLabel = null;

        if (resultCode != RESULT_OK || data == null || data.getData() == null
                || packageName == null) {
            return;
        }

        final Uri uri = data.getData();
        new Thread(() -> {
            String message;
            boolean success = false;
            try {
                File stored = storeDebugLibrary(packageName, uri);
                success = stored != null;
                message = success
                        ? "Debug library ready for " + (label == null ? packageName : label)
                        : "Unable to store debug library";
            } catch (Throwable throwable) {
                message = "Debug library rejected: " + safeMessage(throwable);
            }

            final boolean ok = success;
            final String uiMessage = message;
            runOnUiThread(() -> {
                if (!ok) KeshavOwner7.getInstance().playError();
                Toast.makeText(this, uiMessage, Toast.LENGTH_LONG).show();
                refreshClonedApps();
            });
        }, "pv-debug-lib-copy").start();
    }

    private File storeDebugLibrary(String packageName, Uri uri) throws Exception {
        String displayName = queryDisplayName(uri);
        if (displayName == null || displayName.trim().isEmpty()) {
            displayName = "debug-library.so";
        }
        displayName = sanitizeFileName(displayName);
        if (!displayName.toLowerCase(Locale.US).endsWith(".so")) {
            throw new IllegalArgumentException("Select an Android shared library ending in .so");
        }

        File root = new File(getFilesDir(), "debug-libs");
        File dir = new File(root, sanitizePathSegment(packageName));
        if (!dir.exists() && !dir.mkdirs()) {
            throw new IllegalStateException("Unable to create private debug library directory");
        }

        File output = new File(dir, displayName);
        long total = 0L;
        byte[] buffer = new byte[32 * 1024];
        try (InputStream input = getContentResolver().openInputStream(uri);
             FileOutputStream stream = new FileOutputStream(output, false)) {
            if (input == null) throw new IllegalArgumentException("Unable to read selected file");
            int read;
            while ((read = input.read(buffer)) != -1) {
                total += read;
                if (total > MAX_DEBUG_LIBRARY_BYTES) {
                    throw new IllegalArgumentException("Debug library exceeds 64 MB limit");
                }
                stream.write(buffer, 0, read);
            }
            stream.flush();
        } catch (Throwable throwable) {
            output.delete();
            throw throwable;
        }

        DebugLibraryInspector.Result inspection = DebugLibraryInspector.inspect(output);
        if (!inspection.compatible) {
            output.delete();
            throw new IllegalArgumentException(inspection.message);
        }

        File[] previous = dir.listFiles();
        if (previous != null) {
            for (File file : previous) {
                if (file != null && file.isFile() && !file.equals(output)) {
                    file.delete();
                }
            }
        }

        getSharedPreferences(PREFS_POLICY, MODE_PRIVATE)
                .edit()
                .putString(KEY_DEBUG_LIB_PREFIX + packageName, output.getCanonicalPath())
                .apply();
        return output;
    }

    private File getDebugLibraryFile(String packageName) {
        if (packageName == null) return null;
        String stored = getSharedPreferences(PREFS_POLICY, MODE_PRIVATE)
                .getString(KEY_DEBUG_LIB_PREFIX + packageName, null);
        if (stored == null || stored.trim().isEmpty()) return null;

        try {
            File root = new File(getFilesDir(), "debug-libs").getCanonicalFile();
            File file = new File(stored).getCanonicalFile();
            if (!file.getPath().startsWith(root.getPath() + File.separator)) return null;
            if (!file.isFile() || !file.getName().toLowerCase(Locale.US).endsWith(".so")) return null;
            return DebugLibraryInspector.inspect(file).compatible ? file : null;
        } catch (Throwable ignored) {
            return null;
        }
    }

    private void clearDebugLibrary(String packageName) {
        new Thread(() -> {
            clearDebugLibraryInternal(packageName);
            runOnUiThread(() -> {
                Toast.makeText(this, "Debug library cleared", Toast.LENGTH_SHORT).show();
                refreshClonedApps();
            });
        }, "pv-clear-debug-lib").start();
    }

    private void clearDebugLibraryInternal(String packageName) {
        if (packageName == null) return;
        File selected = getDebugLibraryFile(packageName);
        if (selected != null) {
            try {
                selected.delete();
                File parent = selected.getParentFile();
                if (parent != null) parent.delete();
            } catch (Throwable ignored) {
            }
        }
        getSharedPreferences(PREFS_POLICY, MODE_PRIVATE)
                .edit()
                .remove(KEY_DEBUG_LIB_PREFIX + packageName)
                .apply();
    }

    private void showLatestCrashReport() {
        File report = ParallaxCrashReporter.latestReport(this);
        if (report == null) {
            Toast.makeText(this, "No private crash report recorded yet", Toast.LENGTH_LONG).show();
            return;
        }

        String preview = ParallaxCrashReporter.readPreview(report);
        new AlertDialog.Builder(this)
                .setTitle("Latest crash • " + report.getName())
                .setMessage(preview)
                .setPositiveButton("Close", null)
                .show();
    }

    private void showDebugBridgeHelp() {
        new AlertDialog.Builder(this)
                .setTitle("Opt-in debug bridge")
                .setMessage("For your own debug build, read the Parallax launch extras in your launcher "
                        + "Activity, copy the content URI into your app's private code-cache directory, "
                        + "then call System.load() from your app itself. The PARALLAXvirtual SDK branch "
                        + "contains a ready-to-copy ParallaxDebugBootstrap example. Keep this enabled only "
                        + "in developer/debug builds.")
                .setPositiveButton("OK", null)
                .show();
    }

    private String queryDisplayName(Uri uri) {
        if (uri == null) return null;
        try (Cursor cursor = getContentResolver().query(
                uri,
                new String[]{OpenableColumns.DISPLAY_NAME},
                null,
                null,
                null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int index = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                if (index >= 0) return cursor.getString(index);
            }
        } catch (Throwable ignored) {
        }
        String last = uri.getLastPathSegment();
        return last == null ? null : last;
    }

    private String sanitizePathSegment(String value) {
        if (value == null || value.trim().isEmpty()) return "app";
        return value.replaceAll("[^A-Za-z0-9._-]", "_");
    }

    private String sanitizeFileName(String value) {
        String clean = value == null ? "debug-library.so"
                : value.replaceAll("[^A-Za-z0-9._-]", "_");
        if (clean.length() > 96) clean = clean.substring(clean.length() - 96);
        return clean;
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
            return throwable == null ? "unknown error" : throwable.getClass().getSimpleName();
        }
        String value = throwable.getMessage().trim();
        return value.length() > 160 ? value.substring(0, 160) : value;
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
        final boolean splitApk;

        AppChoice(String packageName, String label, boolean systemApp, boolean splitApk) {
            this.packageName = packageName;
            this.label = label;
            this.systemApp = systemApp;
            this.splitApk = splitApk;
        }
    }
}
