package com.bgmi;

import android.animation.ObjectAnimator;
import android.animation.PropertyValuesHolder;
import android.content.Intent;
import android.graphics.Color;
import android.os.Build;
import android.os.Bundle;
import android.os.Debug;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.view.animation.Animation;
import android.view.animation.AnimationUtils;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import com.bgmi.utils.KeshavOwner4;
import com.bgmi.utils.KeshavOwner7;
import top.niunaijun.blackbox.BlackBoxCore;
import top.niunaijun.blackbox.entity.pm.InstallResult;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.nio.channels.FileChannel;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.concurrent.atomic.AtomicBoolean;

@Obfuscate
public class KeshavOwner3 extends AppCompatActivity {
    private final Handler securityHandler = new Handler(Looper.getMainLooper());
    private Runnable securityGuard;

    static {
        try {
            System.loadLibrary("KeshavLoader");
        } catch (Throwable ignored) {}
    }

    private static final String PKG_BGMI = "com.pubg.imobile";
    private static final int USER_ID = 0;
    private final Handler timerHandler = new Handler(Looper.getMainLooper());
    private boolean doubleBackExit = false;

    private TextView tvExpires;
    private TextView tvDays;
    private TextView tvHours;
    private TextView tvMins;
    private TextView tvSecs;

    public static native String exdate();

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
                    "APK signature, package, native library, or loader integrity validation failed.");
            return;
        }

        boolean nativeIntegrityOk = false;
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

        // Immersive Cyber Transparent Status Bar
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            Window window = getWindow();
            window.getDecorView().setSystemUiVisibility(
                    View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
            );
            window.setStatusBarColor(Color.TRANSPARENT);
        }

        setContentView(R.layout.activity_main);

        securityGuard = KeshavOwner9.installRuntimeGuard(this, securityHandler);

        tvExpires = findViewById(R.id.tvExpires);
        tvDays = findViewById(R.id.tvDays);
        tvHours = findViewById(R.id.tvHours);
        tvMins = findViewById(R.id.tvMins);
        tvSecs = findViewById(R.id.tvSecs);

        // Animate Entrance
        animateEntrance();

        // Animate Title
        View tvMainTitle = findViewById(R.id.tvMainTitle);
        if (tvMainTitle != null) {
            ObjectAnimator titleAnim = ObjectAnimator.ofPropertyValuesHolder(
                    tvMainTitle,
                    PropertyValuesHolder.ofFloat("scaleX", 1.0f, 1.03f),
                    PropertyValuesHolder.ofFloat("scaleY", 1.0f, 1.03f)
            );
            titleAnim.setDuration(1500);
            titleAnim.setRepeatCount(ObjectAnimator.INFINITE);
            titleAnim.setRepeatMode(ObjectAnimator.REVERSE);
            titleAnim.start();
        }

        // Start Button Setup
        View btnStart = findViewById(R.id.btnStart);
        View btnStartContainer = findViewById(R.id.btnStartContainer);

        if (btnStartContainer != null) {
            // Pulse animation on start button
            ObjectAnimator pulseAnim = ObjectAnimator.ofPropertyValuesHolder(
                    btnStartContainer,
                    PropertyValuesHolder.ofFloat("scaleX", 1.0f, 1.025f),
                    PropertyValuesHolder.ofFloat("scaleY", 1.0f, 1.025f)
            );
            pulseAnim.setDuration(1200);
            pulseAnim.setRepeatCount(ObjectAnimator.INFINITE);
            pulseAnim.setRepeatMode(ObjectAnimator.REVERSE);
            pulseAnim.start();
        }

        if (btnStart != null) {
            KeshavOwner7.applyTouchBounce(btnStart, () -> {
                KeshavOwner7.getInstance().playLaunch();
                handleStart();
            });
        }

        doCountTimerAccount();
    }

    private void animateEntrance() {
        try {
            View mainHeader = findViewById(R.id.mainHeader);
            View timerCard = findViewById(R.id.timerCard);
            View gameCard = findViewById(R.id.gameCard);
            View tipsCard = findViewById(R.id.tipsCard);

            if (mainHeader != null) {
                Animation anim = AnimationUtils.loadAnimation(this, R.anim.anim_fade_slide_up);
                mainHeader.startAnimation(anim);
            }
            if (timerCard != null) {
                Animation anim = AnimationUtils.loadAnimation(this, R.anim.anim_fade_slide_up);
                anim.setStartOffset(100);
                timerCard.startAnimation(anim);
            }
            if (gameCard != null) {
                Animation anim = AnimationUtils.loadAnimation(this, R.anim.anim_fade_slide_up);
                anim.setStartOffset(200);
                gameCard.startAnimation(anim);
            }
            if (tipsCard != null) {
                Animation anim = AnimationUtils.loadAnimation(this, R.anim.anim_fade_slide_up);
                anim.setStartOffset(300);
                tipsCard.startAnimation(anim);
            }
        } catch (Exception ignored) {}
    }

    private void handleStart() {
        if (BlackBoxCore.get() == null) {
            KeshavOwner7.getInstance().playError();
            Toast.makeText(this, "Core is null!", Toast.LENGTH_SHORT).show();
            return;
        }

        if (!BlackBoxCore.get().isInstalled(PKG_BGMI, USER_ID)) {
            Toast.makeText(this, "Installing BGMI in Virtual Space...", Toast.LENGTH_SHORT).show();
            InstallResult res = BlackBoxCore.get().installPackageAsUser(PKG_BGMI, USER_ID);
            if (res.success) {
                forceAutoCopyObb();
            } else {
                KeshavOwner7.getInstance().playError();
                Toast.makeText(this, "Install Failed: " + res.msg, Toast.LENGTH_SHORT).show();
            }
        } else {
            forceAutoCopyObb();
        }
    }

    private void forceAutoCopyObb() {
        String internalRoot = Environment.getExternalStorageDirectory().getAbsolutePath();
        File sourceFolder = new File(internalRoot + "/Android/obb/" + PKG_BGMI);
        File destFolder = new File(internalRoot + "/Sdcard/Android/obb/" + PKG_BGMI);

        if (!destFolder.exists()) destFolder.mkdirs();

        File[] existingFiles = destFolder.listFiles((dir, name) -> name.endsWith(".obb"));
        if (existingFiles != null && existingFiles.length > 0) {
            launchGame();
            return;
        }

        Toast.makeText(this, "OBB Copying... Please wait", Toast.LENGTH_SHORT).show();
        AtomicBoolean isFinished = new AtomicBoolean(false);

        timerHandler.postDelayed(() -> {
            if (!isFinished.get()) {
                isFinished.set(true);
                Toast.makeText(KeshavOwner3.this, "Copy Timeout! Check manually.", Toast.LENGTH_LONG).show();
            }
        }, 60000);

        new Thread(() -> {
            try {
                File[] sourceFiles = sourceFolder.listFiles((dir, name) -> name.endsWith(".obb"));
                if (sourceFiles == null || sourceFiles.length == 0) {
                    if (!isFinished.get()) {
                        isFinished.set(true);
                        runOnUiThread(() -> {
                            KeshavOwner7.getInstance().playError();
                            Toast.makeText(KeshavOwner3.this, "Source OBB missing!", Toast.LENGTH_LONG).show();
                        });
                    }
                    return;
                }

                File srcFile = sourceFiles[0];
                File destFile = new File(destFolder, srcFile.getName());

                try (FileChannel srcChannel = new FileInputStream(srcFile).getChannel();
                     FileChannel destChannel = new FileOutputStream(destFile).getChannel()) {
                    srcChannel.transferTo(0, srcChannel.size(), destChannel);
                }

                if (!isFinished.get()) {
                    isFinished.set(true);
                    runOnUiThread(() -> {
                        Toast.makeText(KeshavOwner3.this, "OBB Ready! Launching...", Toast.LENGTH_SHORT).show();
                        launchGame();
                    });
                }
            } catch (Exception e) {
                if (!isFinished.get()) {
                    isFinished.set(true);
                    runOnUiThread(() -> {
                        KeshavOwner7.getInstance().playError();
                        Toast.makeText(KeshavOwner3.this, "Error: " + e.getMessage(), Toast.LENGTH_LONG).show();
                    });
                }
            }
        }).start();
    }

    private void launchGame() {
        try {
            BlackBoxCore.get().launchApk(PKG_BGMI, USER_ID);
        } catch (Exception e) {
            KeshavOwner7.getInstance().playError();
            Toast.makeText(this, "Launch Error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        }
    }

    @Override
    public void onBackPressed() {
        if (doubleBackExit) {
            finishAffinity();
            return;
        }
        this.doubleBackExit = true;
        KeshavOwner7.getInstance().playClick();
        Toast.makeText(this, "Press BACK again to exit", Toast.LENGTH_SHORT).show();
        timerHandler.postDelayed(() -> doubleBackExit = false, 2000);
    }

    private void doCountTimerAccount() {
        timerHandler.post(new Runnable() {
            @Override
            public void run() {
                try {
                    SimpleDateFormat sdf = new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault());
                    Date expiry = sdf.parse(exdate());
                    if (expiry != null) {
                        long diff = expiry.getTime() - System.currentTimeMillis();

                        if (diff > 0) {
                            long d = diff / 86400000;
                            long h = (diff / 3600000) % 24;
                            long m = (diff / 60000) % 60;
                            long s = (diff / 1000) % 60;

                            String timeLeft = String.format(Locale.getDefault(), "%dd %dh %dm %ds", d, h, m, s);
                            if (tvExpires != null) tvExpires.setText(timeLeft);

                            if (tvDays != null) tvDays.setText(String.format(Locale.getDefault(), "%02d", d));
                            if (tvHours != null) tvHours.setText(String.format(Locale.getDefault(), "%02d", h));
                            if (tvMins != null) tvMins.setText(String.format(Locale.getDefault(), "%02d", m));
                            if (tvSecs != null) tvSecs.setText(String.format(Locale.getDefault(), "%02d", s));

                            timerHandler.postDelayed(this, 1000);
                        } else {
                            if (tvExpires != null) tvExpires.setText("Expired");
                            Toast.makeText(KeshavOwner3.this, "Subscription Expired!", Toast.LENGTH_SHORT).show();
                            finish();
                        }
                    }
                } catch (Throwable ignored) {
                    if (tvExpires != null) tvExpires.setText("Active");
                }
            }
        });
    }

    @Override
    protected void onDestroy() {
        try {
            securityHandler.removeCallbacksAndMessages(null);
        } catch (Throwable ignored) {}
        super.onDestroy();
    }

}
