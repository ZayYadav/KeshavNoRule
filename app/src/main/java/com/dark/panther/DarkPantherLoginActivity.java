package com.dark.panther;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;

import android.Manifest;
import android.animation.AnimatorSet;
import android.animation.ObjectAnimator;
import android.animation.PropertyValuesHolder;
import android.app.Dialog;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Debug;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.os.Message;
import android.provider.Settings;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.view.animation.Animation;
import android.view.animation.AnimationUtils;
import android.widget.EditText;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import com.dark.panther.core.PantherEffects;
import com.dark.panther.core.PantherIntegrity;
import com.dark.panther.core.PantherLoaderUpdater;
import com.dark.panther.core.PantherPrefs;
import com.dark.panther.core.PantherSecurity;
import com.team.dark.TeamDark2;

import org.lsposed.lsparanoid.Obfuscate;

@Obfuscate
public class DarkPantherLoginActivity extends AppCompatActivity {
    private final Handler securityHandler = new Handler(Looper.getMainLooper());
    private Runnable securityGuard;

    private static final boolean NATIVE_READY;
    static {
        boolean loaded;
        try {
            System.loadLibrary("DarkPantherLoader");
            loaded = true;
        } catch (Throwable ignored) {
            loaded = false;
        }
        NATIVE_READY = loaded;
    }

    private PantherPrefs prefs;
    private static final String USER = "USER";
    private EditText textUsername;
    private View btnLogin;
    private View pasteBtn;
    private View getKey;
    private Dialog loadingDialog;

    private static final int REQUEST_MANAGE_STORAGE_PERMISSION = 100;
    private static final int REQUEST_MANAGE_UNKNOWN_APP_SOURCES = 200;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);

        if (Debug.isDebuggerConnected() || Debug.waitingForDebugger()) {
            PantherSecurity.showIntegrityFailure(this, "Dark Panther blocked a debugger-attached session.");
            return;
        }
        if (!NATIVE_READY) {
            PantherSecurity.showIntegrityFailure(this, "Dark Panther native engine could not be initialized.");
            return;
        }
        if (!PantherIntegrity.verify(this)) {
            PantherSecurity.showIntegrityFailure(this, "Dark Panther package integrity verification failed.");
            return;
        }

        boolean integrityOk;
        try {
            integrityOk = TeamDark2.nativeVerifySignature(this) && TeamDark2.nativeCustomIntegrity(this);
        } catch (Throwable ignored) {
            integrityOk = false;
        }
        if (!integrityOk) {
            PantherSecurity.showIntegrityFailure(this, "Dark Panther native integrity verification failed.");
            return;
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            Window window = getWindow();
            window.getDecorView().setSystemUiVisibility(
                    View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN);
            window.setStatusBarColor(Color.TRANSPARENT);
            window.setNavigationBarColor(Color.rgb(3, 5, 8));
        }

        setContentView(R.layout.activity_login);
        securityGuard = PantherSecurity.installRuntimeGuard(this, securityHandler);
        prefs = new PantherPrefs(this);
        checkAndRequestPermissions();

        textUsername = findViewById(R.id.userkey);
        btnLogin = findViewById(R.id.login);
        pasteBtn = findViewById(R.id.paste);
        getKey = findViewById(R.id.GetKey);
        textUsername.setText(prefs.getSt(USER, ""));

        PantherEffects.getInstance().playClick();
        animateEntrance();
        installAmbientMotion();

        if (getKey != null) {
            PantherEffects.applyTouchBounce(getKey, () -> {
                try {
                    Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(TeamDark2.GetKey()));
                    startActivity(intent);
                } catch (Exception e) {
                    Toast.makeText(this, "Unable to open official link", Toast.LENGTH_SHORT).show();
                }
            });
        }

        if (btnLogin != null) {
            PantherEffects.applyTouchBounce(btnLogin, () -> {
                String userKey = textUsername.getText().toString().trim();
                if (!userKey.isEmpty()) {
                    prefs.setSt(USER, userKey);
                    login(userKey);
                } else {
                    PantherEffects.getInstance().playError();
                    textUsername.setError("Enter your access key");
                }
            });
        }

        if (pasteBtn != null) {
            PantherEffects.applyTouchBounce(pasteBtn, () -> {
                ClipboardManager clipboard = (ClipboardManager)getSystemService(CLIPBOARD_SERVICE);
                if (clipboard != null && clipboard.hasPrimaryClip()) {
                    ClipData clip = clipboard.getPrimaryClip();
                    if (clip != null && clip.getItemCount() > 0 && clip.getItemAt(0).getText() != null) {
                        String pasted = clip.getItemAt(0).getText().toString().trim();
                        if (pasted.length() > 3) {
                            PantherEffects.getInstance().playPaste();
                            textUsername.setText(pasted);
                            textUsername.setSelection(pasted.length());
                            Toast.makeText(this, "Access key pasted", Toast.LENGTH_SHORT).show();
                            return;
                        }
                    }
                }
                PantherEffects.getInstance().playError();
                Toast.makeText(this, "Clipboard has no valid key", Toast.LENGTH_SHORT).show();
            });
        }
    }

    private void animateEntrance() {
        startEntrance(R.id.headerArea, 0);
        startEntrance(R.id.loginCard, 110);
        startEntrance(R.id.footerArea, 220);
    }

    private void startEntrance(int id, long delay) {
        View view = findViewById(id);
        if (view == null) return;
        try {
            Animation animation = AnimationUtils.loadAnimation(this, R.anim.panther_enter);
            animation.setStartOffset(delay);
            view.startAnimation(animation);
        } catch (Throwable ignored) {}
    }

    private void installAmbientMotion() {
        View logo = findViewById(R.id.logoContainer);
        if (logo != null) {
            ObjectAnimator pulse = ObjectAnimator.ofPropertyValuesHolder(
                    logo,
                    PropertyValuesHolder.ofFloat("scaleX", 1.0f, 1.055f, 1.0f),
                    PropertyValuesHolder.ofFloat("scaleY", 1.0f, 1.055f, 1.0f),
                    PropertyValuesHolder.ofFloat("rotation", -1.2f, 1.2f, -1.2f));
            pulse.setDuration(2600);
            pulse.setRepeatCount(ObjectAnimator.INFINITE);
            pulse.start();
        }

        View title = findViewById(R.id.titleBanner);
        if (title != null) {
            ObjectAnimator floatAnim = ObjectAnimator.ofFloat(title, "translationY", 0f, -6f, 0f);
            ObjectAnimator alpha = ObjectAnimator.ofFloat(title, "alpha", .88f, 1f, .88f);
            floatAnim.setDuration(2400);
            alpha.setDuration(2400);
            floatAnim.setRepeatCount(ObjectAnimator.INFINITE);
            alpha.setRepeatCount(ObjectAnimator.INFINITE);
            AnimatorSet set = new AnimatorSet();
            set.playTogether(floatAnim, alpha);
            set.start();
        }
    }

    private void checkAndRequestPermissions() {
        if (!isStoragePermissionGranted()) requestStoragePermissionDirect();
        else if (!canRequestPackageInstalls()) requestUnknownAppPermissionsDirect();
    }

    private boolean isStoragePermissionGranted() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.R || Environment.isExternalStorageManager();
    }

    private void requestStoragePermissionDirect() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            Intent intent = new Intent(Settings.ACTION_MANAGE_APP_ALL_FILES_ACCESS_PERMISSION);
            intent.setData(Uri.fromParts("package", getPackageName(), null));
            startActivityForResult(intent, REQUEST_MANAGE_STORAGE_PERMISSION);
        } else {
            ActivityCompat.requestPermissions(this,
                    new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE},
                    REQUEST_MANAGE_STORAGE_PERMISSION);
        }
    }

    private boolean canRequestPackageInstalls() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.O || getPackageManager().canRequestPackageInstalls();
    }

    private void requestUnknownAppPermissionsDirect() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent intent = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                    Uri.parse("package:" + getPackageName()));
            startActivityForResult(intent, REQUEST_MANAGE_UNKNOWN_APP_SOURCES);
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, @Nullable Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        checkAndRequestPermissions();
    }

    private void login(final String userKey) {
        showLoadingDialog("Panther verification in progress…", false);
        Handler loginHandler = new Handler(Looper.getMainLooper(), msg -> {
            dismissLoadingDialog();
            if (msg.what == 0) {
                PantherEffects.getInstance().playSuccess();
                startDownload();
            } else {
                PantherEffects.getInstance().playError();
                showLoadingDialog(String.valueOf(msg.obj), true);
            }
            return true;
        });

        new Thread(() -> {
            String result = TeamDark2.Check(DarkPantherLoginActivity.this, userKey);
            if ("OK".equals(result)) loginHandler.sendEmptyMessage(0);
            else {
                Message msg = Message.obtain();
                msg.what = 1;
                msg.obj = result;
                loginHandler.sendMessage(msg);
            }
        }, "DarkPanther-Auth").start();
    }

    private void startDownload() {
        showLoadingDialog("Preparing secure engine…", false);
        PantherLoaderUpdater task = new PantherLoaderUpdater(this, success -> {
            dismissLoadingDialog();
            if (!success) {
                PantherSecurity.showIntegrityFailure(this, "The trusted server loader could not be verified.");
                return;
            }
            if (!PantherIntegrity.verify(this)) {
                PantherSecurity.showIntegrityFailure(this, "Downloaded loader verification failed.");
                return;
            }
            Intent i = new Intent(this, DarkPantherHomeActivity.class);
            i.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
            startActivity(i);
            overridePendingTransition(R.anim.panther_slide_in, R.anim.panther_slide_out);
            finish();
        });

        task.setProgressListener(progress -> runOnUiThread(() -> {
            if (loadingDialog == null || !loadingDialog.isShowing()) return;
            ProgressBar bar = loadingDialog.findViewById(R.id.progressBar);
            TextView text = loadingDialog.findViewById(R.id.progressText);
            if (bar != null) {
                bar.setIndeterminate(false);
                bar.setMax(100);
                bar.setProgress(progress);
            }
            if (text != null) text.setText("Syncing Panther engine  •  " + progress + "%");
        }));

        try {
            task.execute(PantherLoaderUpdater.downloadUrl());
        } catch (Throwable ignored) {
            PantherSecurity.showIntegrityFailure(this, "Secure loader update could not start.");
        }
    }

    private void showLoadingDialog(String message, boolean isError) {
        if (loadingDialog == null) {
            loadingDialog = new Dialog(this);
            loadingDialog.setContentView(R.layout.ios_loading);
            loadingDialog.setCancelable(false);
            if (loadingDialog.getWindow() != null) {
                loadingDialog.getWindow().setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
            }
        }

        TextView loadingText = loadingDialog.findViewById(R.id.loadingText);
        ProgressBar progressBar = loadingDialog.findViewById(R.id.progressBar);
        View okButton = loadingDialog.findViewById(R.id.okButton);
        TextView progressText = loadingDialog.findViewById(R.id.progressText);

        if (isError) {
            if (progressBar != null) progressBar.setVisibility(View.GONE);
            if (progressText != null) progressText.setVisibility(View.GONE);
            if (okButton != null) {
                okButton.setVisibility(View.VISIBLE);
                okButton.setOnTouchListener(null);
                PantherEffects.applyTouchBounce(okButton, this::dismissLoadingDialog);
            }
            if (loadingText != null) loadingText.setText("ACCESS BLOCKED\n" + message);
        } else {
            if (progressBar != null) progressBar.setVisibility(View.VISIBLE);
            if (progressText != null) progressText.setVisibility(View.VISIBLE);
            if (okButton != null) okButton.setVisibility(View.GONE);
            if (loadingText != null) loadingText.setText(message == null ? "Dark Panther working…" : message);
        }
        loadingDialog.show();
    }

    private void dismissLoadingDialog() {
        if (loadingDialog != null && loadingDialog.isShowing()) loadingDialog.dismiss();
    }

    @Override
    protected void onDestroy() {
        try { securityHandler.removeCallbacksAndMessages(null); } catch (Throwable ignored) {}
        super.onDestroy();
    }
}
