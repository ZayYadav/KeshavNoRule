package com.bgmi;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;

import android.Manifest;
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
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import java.security.MessageDigest;

import com.bgmi.utils.KeshavOwner5;
import com.bgmi.utils.KeshavOwner6;
import com.bgmi.utils.KeshavOwner7;

import org.lsposed.lsparanoid.Obfuscate;

@Obfuscate
public class KeshavOwner2 extends AppCompatActivity {

    static {
        try {
            System.loadLibrary("akshit");
        } catch (UnsatisfiedLinkError ignored) {}
    }

    private KeshavOwner6 prefs;
    private final String USER = "USER";

    private EditText textUsername;
    private View btnLogin;
    private View pasteBtn;
    private View getKey;
    private Dialog loadingDialog;

    private static final int REQUEST_MANAGE_STORAGE_PERMISSION = 100;
    private static final int REQUEST_MANAGE_UNKNOWN_APP_SOURCES = 200;

    public static native boolean nativeVerifySignature(Context context);
    public static native boolean nativeCustomIntegrity(Context context);
    private static final String EXPECTED_SIGNATURE =
            "77f05d53ce8bf1855caef38ce87f13a8bb2b1b2cdd2d48da9d3ba897eac4549e";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Security: prevent screenshots/recording of license UI and fail closed under an attached debugger.
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);
        if (Debug.isDebuggerConnected() || Debug.waitingForDebugger()) {
            finishAndRemoveTask();
            return;
        }
        if (!nativeVerifySignature(this) || !nativeCustomIntegrity(this)) {
            Toast.makeText(this, "Integrity check failed", Toast.LENGTH_LONG).show();
            finishAffinity();
            return;
        }

        // Make status bar transparent for dark immersive cyber look
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            Window window = getWindow();
            window.getDecorView().setSystemUiVisibility(
                    View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
            );
            window.setStatusBarColor(Color.TRANSPARENT);
        }

        setContentView(R.layout.activity_login);

        prefs = new KeshavOwner6(this);
        checkAndRequestPermissions();

        textUsername = findViewById(R.id.userkey);
        btnLogin = findViewById(R.id.login);
        pasteBtn = findViewById(R.id.paste);
        getKey = findViewById(R.id.GetKey);

        textUsername.setText(prefs.getSt(USER, ""));

        // Play intro sound
        KeshavOwner7.getInstance().playClick();

        // Staggered Entrance Animations
        animateEntrance();

        // Pulse Animation on Logo
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

        // Animated Floating Glow on Title "NO RULE LOADER"
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

        // Action: Get Key (Telegram)
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

        // Action: Login Button
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

        // Action: Paste Button
        if (pasteBtn != null) {
            KeshavOwner7.applyTouchBounce(pasteBtn, () -> {
                ClipboardManager clipboard = (ClipboardManager) getSystemService(CLIPBOARD_SERVICE);
                if (clipboard != null && clipboard.hasPrimaryClip()) {
                    ClipData clip = clipboard.getPrimaryClip();
                    if (clip != null && clip.getItemCount() > 0) {
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
        } catch (Exception ignored) {}
    }

    private void checkAndRequestPermissions() {
        if (!isStoragePermissionGranted()) {
            requestStoragePermissionDirect();
        } else if (!canRequestPackageInstalls()) {
            requestUnknownAppPermissionsDirect();
        }
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
            ActivityCompat.requestPermissions(this, new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQUEST_MANAGE_STORAGE_PERMISSION);
        }
    }

    private boolean canRequestPackageInstalls() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.O || getPackageManager().canRequestPackageInstalls();
    }

    private void requestUnknownAppPermissionsDirect() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent intent = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getPackageName()));
            startActivityForResult(intent, REQUEST_MANAGE_UNKNOWN_APP_SOURCES);
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, @Nullable Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        checkAndRequestPermissions();
    }

    private void Login(final Context m_Context, final String userKey) {
        showLoadingDialog("Verifying License...", false);

        Handler loginHandler = new Handler(Looper.getMainLooper(), msg -> {
            dismissLoadingDialog();
            if (msg.what == 0) {
                // Play Success Fanfare Chime
                KeshavOwner7.getInstance().playSuccess();

                // Do not copy the license back to the system clipboard after authentication.
                startDownload(m_Context);
            } else if (msg.what == 1) {
                // Play Error Buzz
                KeshavOwner7.getInstance().playError();
                showLoadingDialog((String) msg.obj, true);
            }
            return true;
        });

        new Thread(() -> {
            String result = Check(m_Context, userKey);
            if ("OK".equals(result)) {
                loginHandler.sendEmptyMessage(0);
            } else {
                Message msg = Message.obtain();
                msg.what = 1;
                msg.obj = result;
                loginHandler.sendMessage(msg);
            }
        }).start();
    }

    private void startDownload(Context m_Context) {
        showLoadingDialog("Checking Security Assets...", false);

        KeshavOwner5 task = new KeshavOwner5(KeshavOwner2.this, success -> {
            dismissLoadingDialog();

            if (!success) {
                Toast.makeText(KeshavOwner2.this, "Download failed! Proceeding anyway...", Toast.LENGTH_SHORT).show();
            }

            Intent i = new Intent(m_Context, KeshavOwner3.class);
            i.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
            m_Context.startActivity(i);
            overridePendingTransition(R.anim.anim_slide_in_right, R.anim.anim_slide_out_left);
            finish();
        });

        task.setProgressListener(progress -> runOnUiThread(() -> {
            if (loadingDialog != null && loadingDialog.isShowing()) {
                ProgressBar progressBar = loadingDialog.findViewById(R.id.progressBar);
                TextView progressText = loadingDialog.findViewById(R.id.progressText);
                if (progressBar != null) {
                    progressBar.setIndeterminate(false);
                    progressBar.setMax(100);
                    progressBar.setProgress(progress);
                }
                if (progressText != null) {
                    progressText.setText("Downloading Engine... " + progress + "%");
                }
            }
        }));

        try {
            task.execute(KeshavOwner5.Link());
        } catch (Exception e) {
            Intent i = new Intent(m_Context, KeshavOwner3.class);
            startActivity(i);
            overridePendingTransition(R.anim.anim_slide_in_right, R.anim.anim_slide_out_left);
            finish();
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

        if (isError) {
            if (progressBar != null) progressBar.setVisibility(View.GONE);
            if (okButton != null) {
                okButton.setVisibility(View.VISIBLE);
                KeshavOwner7.applyTouchBounce(okButton, () -> dismissLoadingDialog());
            }
            if (loadingText != null) loadingText.setText("Access Denied: " + message);
        } else {
            if (progressBar != null) progressBar.setVisibility(View.VISIBLE);
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

    private static native String Check(Context mContext, String userKey);
    private native String GetKey();
}
