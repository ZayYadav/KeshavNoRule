package com.team.dark;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.graphics.Color;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.widget.FrameLayout;
import android.widget.TextView;

/**
 * Lightweight launcher gate used before the full AppCompat/Elite login activity.
 *
 * A re-signed/tampered APK fails TeamDark1's pre-Elite host-signature verification.
 * In that state we deliberately avoid entering TeamDark2 (which loads the heavier
 * UI/native/SDK stack) and show a framework-only dialog from a real Activity window.
 * This prevents a tamper failure from looking like an unexplained startup crash.
 */
public final class TeamDarkGateActivity extends Activity {

    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private AlertDialog tamperDialog;
    private boolean routed;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);

        // Give Android a real, attached window before attempting to present a dialog.
        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.rgb(5, 7, 12));

        TextView status = new TextView(this);
        status.setText("TEAM DARK\nSECURITY CHECK");
        status.setTextColor(Color.WHITE);
        status.setTextSize(16f);
        status.setGravity(Gravity.CENTER);
        status.setAlpha(0.82f);
        root.addView(status, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT));

        setContentView(root);

        root.post(this::routeAfterWindowReady);
    }

    private void routeAfterWindowReady() {
        if (routed || isFinishing() || isDestroyed()) return;

        final boolean trusted;
        try {
            trusted = TeamDark1.isHostSignatureVerified();
        } catch (Throwable ignored) {
            showTamperDialog();
            return;
        }

        if (!trusted) {
            showTamperDialog();
            return;
        }

        routed = true;
        try {
            Intent intent = new Intent(this, TeamDark2.class);
            intent.addFlags(Intent.FLAG_ACTIVITY_NO_ANIMATION);
            startActivity(intent);
            finish();
            overridePendingTransition(0, 0);
        } catch (Throwable launchFailure) {
            routed = false;
            showTamperDialog();
        }
    }

    private void showTamperDialog() {
        if (isFinishing() || isDestroyed()) return;
        if (tamperDialog != null && tamperDialog.isShowing()) return;

        try {
            tamperDialog = new AlertDialog.Builder(this)
                    .setTitle("APK TAMPER DETECTED")
                    .setMessage(
                            "Unauthorized APK modification or re-signing was detected.\n\n"
                                    + "This build is not trusted and cannot continue.\n\n"
                                    + "SESSION TERMINATED SAFELY")
                    .setCancelable(false)
                    .setPositiveButton("CLOSE LOADER", (dialog, which) -> closeLoader())
                    .create();

            tamperDialog.setCanceledOnTouchOutside(false);
            tamperDialog.setOnDismissListener(dialog -> {
                if (!isFinishing()) closeLoader();
            });
            tamperDialog.show();
        } catch (Throwable firstFailure) {
            // A second attempt after the Activity has fully resumed covers vendor ROMs
            // that reject an extremely early dialog/window token.
            mainHandler.postDelayed(() -> {
                if (isFinishing() || isDestroyed()) return;
                try {
                    AlertDialog fallback = new AlertDialog.Builder(this)
                            .setTitle("SECURITY ALERT")
                            .setMessage("APK integrity verification failed. CLOSE LOADER to exit safely.")
                            .setCancelable(false)
                            .setPositiveButton("CLOSE LOADER", (dialog, which) -> closeLoader())
                            .create();
                    fallback.setCanceledOnTouchOutside(false);
                    fallback.setOnDismissListener(dialog -> {
                        if (!isFinishing()) closeLoader();
                    });
                    fallback.show();
                    tamperDialog = fallback;
                } catch (Throwable ignored) {
                    // Keep the Activity alive instead of crashing. Another resume will retry.
                }
            }, 250L);
        }
    }

    private void closeLoader() {
        try {
            finishAffinity();
        } catch (Throwable ignored) {
            try {
                finish();
            } catch (Throwable ignoredAgain) {
                // No-op: never turn a tamper dialog path into a process crash.
            }
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (!routed && !TeamDark1.isHostSignatureVerified()) {
            mainHandler.post(this::showTamperDialog);
        }
    }

    @Override
    public void onBackPressed() {
        if (TeamDark1.isHostSignatureVerified()) {
            super.onBackPressed();
        } else {
            showTamperDialog();
        }
    }

    @Override
    protected void onDestroy() {
        mainHandler.removeCallbacksAndMessages(null);
        try {
            if (tamperDialog != null && tamperDialog.isShowing()) {
                tamperDialog.dismiss();
            }
        } catch (Throwable ignored) {}
        tamperDialog = null;
        super.onDestroy();
    }
}
