package com.dark.panther;

import android.animation.AnimatorSet;
import android.animation.ObjectAnimator;
import android.animation.PropertyValuesHolder;
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

import com.dark.panther.core.PantherEffects;
import com.dark.panther.core.PantherIntegrity;
import com.dark.panther.core.PantherNative;
import com.dark.panther.core.PantherSecurity;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.nio.channels.FileChannel;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.concurrent.atomic.AtomicBoolean;

import top.niunaijun.blackbox.BlackBoxCore;
import top.niunaijun.blackbox.entity.pm.InstallResult;

@Obfuscate
public class DarkPantherHomeActivity extends AppCompatActivity {
    private static final String PKG_BGMI = "com.pubg.imobile";
    private static final int USER_ID = 0;

    private final Handler securityHandler = new Handler(Looper.getMainLooper());
    private final Handler timerHandler = new Handler(Looper.getMainLooper());
    private Runnable securityGuard;
    private boolean doubleBackExit;

    private TextView tvExpires;
    private TextView tvDays;
    private TextView tvHours;
    private TextView tvMins;
    private TextView tvSecs;

    static {
        try {
            System.loadLibrary("DarkPantherLoader");
        } catch (Throwable ignored) {}
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);

        if (Debug.isDebuggerConnected() || Debug.waitingForDebugger()) {
            PantherSecurity.showIntegrityFailure(this, "Dark Panther blocked a debugger-attached session.");
            return;
        }

        if (!PantherIntegrity.verify(this)) {
            PantherSecurity.showIntegrityFailure(this, "Dark Panther package integrity verification failed.");
            return;
        }

        boolean nativeIntegrityOk;
        try {
            nativeIntegrityOk = PantherNative.verifySignature(this)
                    && PantherNative.customIntegrity(this);
        } catch (Throwable ignored) {
            nativeIntegrityOk = false;
        }

        if (!nativeIntegrityOk) {
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

        setContentView(R.layout.activity_main);
        securityGuard = PantherSecurity.installRuntimeGuard(this, securityHandler);

        tvExpires = findViewById(R.id.tvExpires);
        tvDays = findViewById(R.id.tvDays);
        tvHours = findViewById(R.id.tvHours);
        tvMins = findViewById(R.id.tvMins);
        tvSecs = findViewById(R.id.tvSecs);

        animateEntrance();
        installAmbientMotion();

        View btnStart = findViewById(R.id.btnStart);
        if (btnStart != null) {
            PantherEffects.applyTouchBounce(btnStart, () -> {
                PantherEffects.getInstance().playLaunch();
                handleStart();
            });
        }

        doCountTimerAccount();
    }

    private void animateEntrance() {
        startEntrance(R.id.mainHeader, 0);
        startEntrance(R.id.timerCard, 90);
        startEntrance(R.id.gameCard, 180);
        startEntrance(R.id.tipsCard, 270);
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
        View title = findViewById(R.id.tvMainTitle);
        if (title != null) {
            ObjectAnimator drift = ObjectAnimator.ofPropertyValuesHolder(
                    title,
                    PropertyValuesHolder.ofFloat("translationY", 0f, -4f, 0f),
                    PropertyValuesHolder.ofFloat("alpha", .9f, 1f, .9f));
            drift.setDuration(2600);
            drift.setRepeatCount(ObjectAnimator.INFINITE);
            drift.start();
        }

        View startContainer = findViewById(R.id.btnStartContainer);
        if (startContainer != null) {
            ObjectAnimator pulse = ObjectAnimator.ofPropertyValuesHolder(
                    startContainer,
                    PropertyValuesHolder.ofFloat("scaleX", 1f, 1.025f, 1f),
                    PropertyValuesHolder.ofFloat("scaleY", 1f, 1.025f, 1f));
            ObjectAnimator glow = ObjectAnimator.ofFloat(startContainer, "alpha", .88f, 1f, .88f);
            pulse.setDuration(1800);
            glow.setDuration(1800);
            pulse.setRepeatCount(ObjectAnimator.INFINITE);
            glow.setRepeatCount(ObjectAnimator.INFINITE);
            AnimatorSet set = new AnimatorSet();
            set.playTogether(pulse, glow);
            set.start();
        }
    }

    private void handleStart() {
        if (BlackBoxCore.get() == null) {
            PantherEffects.getInstance().playError();
            Toast.makeText(this, "Panther core is unavailable", Toast.LENGTH_SHORT).show();
            return;
        }

        if (!BlackBoxCore.get().isInstalled(PKG_BGMI, USER_ID)) {
            Toast.makeText(this, "Preparing BGMI chamber…", Toast.LENGTH_SHORT).show();
            InstallResult result = BlackBoxCore.get().installPackageAsUser(PKG_BGMI, USER_ID);
            if (result.success) {
                forceAutoCopyObb();
            } else {
                PantherEffects.getInstance().playError();
                Toast.makeText(this, "BGMI prepare failed: " + result.msg, Toast.LENGTH_SHORT).show();
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

        Toast.makeText(this, "Synchronizing game data…", Toast.LENGTH_SHORT).show();
        AtomicBoolean finished = new AtomicBoolean(false);

        timerHandler.postDelayed(() -> {
            if (!finished.getAndSet(true)) {
                PantherEffects.getInstance().playError();
                Toast.makeText(this, "Data sync timed out. Check OBB manually.", Toast.LENGTH_LONG).show();
            }
        }, 60000L);

        new Thread(() -> {
            try {
                File[] sourceFiles = sourceFolder.listFiles((dir, name) -> name.endsWith(".obb"));
                if (sourceFiles == null || sourceFiles.length == 0) {
                    if (!finished.getAndSet(true)) {
                        runOnUiThread(() -> {
                            PantherEffects.getInstance().playError();
                            Toast.makeText(this, "BGMI OBB source not found", Toast.LENGTH_LONG).show();
                        });
                    }
                    return;
                }

                File srcFile = sourceFiles[0];
                File destFile = new File(destFolder, srcFile.getName());
                try (FileChannel src = new FileInputStream(srcFile).getChannel();
                     FileChannel dst = new FileOutputStream(destFile).getChannel()) {
                    long position = 0L;
                    long size = src.size();
                    while (position < size) {
                        long moved = src.transferTo(position, size - position, dst);
                        if (moved <= 0) break;
                        position += moved;
                    }
                }

                if (!finished.getAndSet(true)) {
                    runOnUiThread(() -> {
                        Toast.makeText(this, "Panther chamber ready", Toast.LENGTH_SHORT).show();
                        launchGame();
                    });
                }
            } catch (Throwable error) {
                if (!finished.getAndSet(true)) {
                    runOnUiThread(() -> {
                        PantherEffects.getInstance().playError();
                        Toast.makeText(this, "Game data error", Toast.LENGTH_LONG).show();
                    });
                }
            }
        }, "DarkPanther-ObbSync").start();
    }

    private void launchGame() {
        try {
            BlackBoxCore.get().launchApk(PKG_BGMI, USER_ID);
        } catch (Throwable error) {
            PantherEffects.getInstance().playError();
            Toast.makeText(this, "BGMI launch failed", Toast.LENGTH_SHORT).show();
        }
    }

    @Override
    public void onBackPressed() {
        if (doubleBackExit) {
            finishAffinity();
            return;
        }
        doubleBackExit = true;
        PantherEffects.getInstance().playClick();
        Toast.makeText(this, "Press BACK again to leave Dark Panther", Toast.LENGTH_SHORT).show();
        timerHandler.postDelayed(() -> doubleBackExit = false, 2000L);
    }

    private void doCountTimerAccount() {
        timerHandler.post(new Runnable() {
            @Override
            public void run() {
                try {
                    SimpleDateFormat sdf = new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault());
                    Date expiry = sdf.parse(PantherNative.expiryDate());
                    if (expiry == null) throw new IllegalStateException("No expiry");

                    long diff = expiry.getTime() - System.currentTimeMillis();
                    if (diff <= 0) {
                        if (tvExpires != null) tvExpires.setText("EXPIRED");
                        Toast.makeText(DarkPantherHomeActivity.this,
                                "Dark Panther access expired", Toast.LENGTH_SHORT).show();
                        finish();
                        return;
                    }

                    long d = diff / 86400000L;
                    long h = (diff / 3600000L) % 24L;
                    long m = (diff / 60000L) % 60L;
                    long s = (diff / 1000L) % 60L;

                    if (tvExpires != null) {
                        tvExpires.setText(String.format(Locale.getDefault(), "%dd %dh %dm", d, h, m));
                    }
                    if (tvDays != null) tvDays.setText(String.format(Locale.getDefault(), "%02d", d));
                    if (tvHours != null) tvHours.setText(String.format(Locale.getDefault(), "%02d", h));
                    if (tvMins != null) tvMins.setText(String.format(Locale.getDefault(), "%02d", m));
                    if (tvSecs != null) tvSecs.setText(String.format(Locale.getDefault(), "%02d", s));

                    timerHandler.postDelayed(this, 1000L);
                } catch (Throwable ignored) {
                    if (tvExpires != null) tvExpires.setText("ACTIVE");
                    timerHandler.postDelayed(this, 1000L);
                }
            }
        });
    }

    @Override
    protected void onDestroy() {
        try { securityHandler.removeCallbacksAndMessages(null); } catch (Throwable ignored) {}
        try { timerHandler.removeCallbacksAndMessages(null); } catch (Throwable ignored) {}
        super.onDestroy();
    }
}
