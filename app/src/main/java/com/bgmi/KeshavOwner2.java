package com.bgmi;

import androidx.appcompat.app.AppCompatActivity;

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
import android.os.Handler;
import android.os.Looper;
import android.os.Message;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.view.animation.Animation;
import android.view.animation.AnimationUtils;
import android.widget.EditText;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import com.bgmi.utils.KeshavOwner6;
import com.bgmi.utils.KeshavOwner7;

import org.lsposed.lsparanoid.Obfuscate;

@Obfuscate
public class KeshavOwner2 extends AppCompatActivity {
    private final Handler securityHandler = new Handler(Looper.getMainLooper());
    private Runnable securityGuard;

    private static final boolean NATIVE_READY;

    static {
        boolean loaded;
        try {
            System.loadLibrary("KeshavLoader");
            loaded = true;
        } catch (Throwable ignored) {
            loaded = false;
        }
        NATIVE_READY = loaded;
    }

    private KeshavOwner6 prefs;
    private static final String USER = "USER";

    private EditText textUsername;
    private View btnLogin;
    private View pasteBtn;
    private View getKey;
    private Dialog loadingDialog;

    public static native boolean nativeVerifySignature(Context context);
    public static native boolean nativeCustomIntegrity(Context context);

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
        if (!NATIVE_READY) {
            KeshavOwner9.showIntegrityFailure(this,
                    "The native security engine could not be initialized safely.");
            return;
        }

        boolean integrityOk;
        try {
            integrityOk = nativeVerifySignature(this) && nativeCustomIntegrity(this);
        } catch (Throwable ignored) {
            integrityOk = false;
        }

        if (!integrityOk) {
            KeshavOwner9.showIntegrityFailure(this,
                    "Native integrity validation rejected the current runtime.");
            return;
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            Window window = getWindow();
            window.getDecorView().setSystemUiVisibility(
                    View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
            );
            window.setStatusBarColor(Color.TRANSPARENT);
        }

        setContentView(R.layout.activity_login);
        securityGuard = KeshavOwner9.installRuntimeGuard(this, securityHandler);

        prefs = new KeshavOwner6(this);
        textUsername = findViewById(R.id.userkey);
        btnLogin = findViewById(R.id.login);
        pasteBtn = findViewById(R.id.paste);
        getKey = findViewById(R.id.GetKey);

        textUsername.setText(prefs.getSt(USER, ""));
        KeshavOwner7.getInstance().playClick();
        animateEntrance();

        View logoContainer = findViewById(R.id.logoContainer);
        if (logoContainer != null) {
            ObjectAnimator pulseAnim = ObjectAnimator.ofPropertyValuesHolder(
                    logoContainer,
                    PropertyValuesHolder.ofFloat("scaleX", 1.0f, 1.05f),
                    PropertyValuesHolder.ofFloat("scaleY", 1.0f, 1.05f)
            );
            pulseAnim.setDuration(1400);
            pulseAnim.setRepeatCount(ObjectAnimator.INFINITE);
            pulseAnim.setRepeatMode(ObjectAnimator.REVERSE);
            pulseAnim.start();
        }

        View titleBanner = findViewById(R.id.titleBanner);
        if (titleBanner != null) {
            ObjectAnimator titleAnim = ObjectAnimator.ofPropertyValuesHolder(
                    titleBanner,
                    PropertyValuesHolder.ofFloat("scaleX", 1.0f, 1.04f),
                    PropertyValuesHolder.ofFloat("scaleY", 1.0f, 1.04f),
                    PropertyValuesHolder.ofFloat("translationY", 0f, -4f)
            );
            titleAnim.setDuration(1600);
            titleAnim.setRepeatCount(ObjectAnimator.INFINITE);
            titleAnim.setRepeatMode(ObjectAnimator.REVERSE);
            titleAnim.start();
        }

        if (getKey != null) {
            KeshavOwner7.applyTouchBounce(getKey, () -> {
                try {
                    Intent intent = new Intent(Intent.ACTION_VIEW);
                    intent.setData(Uri.parse(GetKey()));
                    startActivity(intent);
                } catch (Exception e) {
                    Toast.makeText(this, "Cannot open link", Toast.LENGTH_SHORT).show();
                }
            });
        }

        if (btnLogin != null) {
            KeshavOwner7.applyTouchBounce(btnLogin, () -> {
                String userKey = textUsername.getText().toString().trim();
                if (!userKey.isEmpty()) {
                    prefs.setSt(USER, userKey);
                    Login(this, userKey);
                } else {
                    KeshavOwner7.getInstance().playError();
                    textUsername.setError("Please enter license key");
                }
            });
        }

        if (pasteBtn != null) {
            KeshavOwner7.applyTouchBounce(pasteBtn, () -> {
                ClipboardManager clipboard = (ClipboardManager) getSystemService(CLIPBOARD_SERVICE);
                if (clipboard != null && clipboard.hasPrimaryClip()) {
                    ClipData clip = clipboard.getPrimaryClip();
                    if (clip != null && clip.getItemCount() > 0 && clip.getItemAt(0).getText() != null) {
                        String pasted = clip.getItemAt(0).getText().toString().trim();
                        if (pasted.length() > 3) {
                            KeshavOwner7.getInstance().playPaste();
                            textUsername.setText(pasted);
                            Toast.makeText(this, "Key pasted from clipboard!", Toast.LENGTH_SHORT).show();
                        } else {
                            KeshavOwner7.getInstance().playError();
                            Toast.makeText(this, "Invalid key in clipboard", Toast.LENGTH_SHORT).show();
                        }
                    }
                } else {
                    KeshavOwner7.getInstance().playError();
                    Toast.makeText(this, "Clipboard empty", Toast.LENGTH_SHORT).show();
                }
            });
        }
    }

    private void animateEntrance() {
        try {
            View headerArea = findViewById(R.id.headerArea);
            View loginCard = findViewById(R.id.loginCard);
            View footerArea = findViewById(R.id.footerArea);

            if (headerArea != null) {
                Animation anim1 = AnimationUtils.loadAnimation(this, R.anim.anim_fade_slide_up);
                headerArea.startAnimation(anim1);
            }

            if (loginCard != null) {
                Animation anim2 = AnimationUtils.loadAnimation(this, R.anim.anim_fade_slide_up);
                anim2.setStartOffset(150);
                loginCard.startAnimation(anim2);
            }

            if (footerArea != null) {
                Animation anim3 = AnimationUtils.loadAnimation(this, R.anim.anim_fade_slide_up);
                anim3.setStartOffset(300);
                footerArea.startAnimation(anim3);
            }
        } catch (Exception ignored) {
        }
    }

    private void Login(final Context context, final String userKey) {
        showLoadingDialog("Verifying Parallax Virtual license...", false);

        Handler loginHandler = new Handler(Looper.getMainLooper(), msg -> {
            dismissLoadingDialog();
            if (msg.what == 0) {
                KeshavOwner7.getInstance().playSuccess();
                openVirtualDashboard(context);
            } else if (msg.what == 1) {
                KeshavOwner7.getInstance().playError();
                showLoadingDialog((String) msg.obj, true);
            }
            return true;
        });

        new Thread(() -> {
            String result = Check(context, userKey);
            if ("OK".equals(result)) {
                loginHandler.sendEmptyMessage(0);
            } else {
                Message msg = Message.obtain();
                msg.what = 1;
                msg.obj = result;
                loginHandler.sendMessage(msg);
            }
        }, "pv-license-check").start();
    }

    private void openVirtualDashboard(Context context) {
        // Parallax Virtual no longer downloads/extracts a ZIP or stages a
        // game-specific shared library after login. A successful license check
        // goes directly to the generic installed-app virtualization dashboard.
        Intent intent = new Intent(context, KeshavOwner3.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        context.startActivity(intent);
        overridePendingTransition(R.anim.anim_slide_in_right, R.anim.anim_slide_out_left);
        finish();
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

        if (isError) {
            if (progressBar != null) progressBar.setVisibility(View.GONE);
            if (okButton != null) {
                okButton.setVisibility(View.VISIBLE);
                okButton.setOnTouchListener(null);
                okButton.setOnClickListener(v -> {
                    KeshavOwner7.getInstance().playClick();
                    dismissLoadingDialog();
                });
            }
            if (loadingText != null) loadingText.setText("Access Denied: " + message);
        } else {
            if (progressBar != null) {
                progressBar.setVisibility(View.VISIBLE);
                progressBar.setIndeterminate(true);
            }
            if (okButton != null) okButton.setVisibility(View.GONE);
            if (loadingText != null) loadingText.setText(message != null ? message : "Authenticating...");
        }

        loadingDialog.show();
    }

    private void dismissLoadingDialog() {
        if (loadingDialog != null && loadingDialog.isShowing()) {
            loadingDialog.dismiss();
        }
    }

    private static native String Check(Context context, String userKey);
    private native String GetKey();

    @Override
    protected void onDestroy() {
        try {
            securityHandler.removeCallbacksAndMessages(null);
        } catch (Throwable ignored) {
        }
        super.onDestroy();
    }
}
