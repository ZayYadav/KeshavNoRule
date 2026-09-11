package com.team.dark;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.KeyEvent;
import android.view.Window;
import android.view.WindowManager;

/**
 * Invisible framework-only launcher gate.
 *
 * Trusted release APK -> immediately routes to TeamDark2 with no visible security splash.
 * Re-signed/tampered APK -> TeamDark2/Elite/native startup stays blocked and this Activity
 * exists only as a window token for the tamper dialog.
 */
public final class TeamDarkGateActivity extends Activity {

    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private AlertDialog tamperDialog;
    private boolean trusted;
    private boolean routed;
    private boolean destroyed;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        Window window = getWindow();
        if (window != null) {
            window.addFlags(WindowManager.LayoutParams.FLAG_SECURE);
            window.setBackgroundDrawableResource(android.R.color.transparent);
        }

        // No content view on purpose: normal startup must show no security splash/page.
        trusted = verifyTrustedHost();

        if (trusted) {
            routeToLoader();
        } else {
            // Let the Activity window attach, then show only the security dialog.
            mainHandler.post(this::showTamperDialogGuaranteed);
        }
    }

    private boolean verifyTrustedHost() {
        try {
            return TeamDark1.isHostSignatureVerified() && TeamDarkSigner.verify(this);
        } catch (Throwable ignored) {
            return false;
        }
    }

    private void routeToLoader() {
        if (routed || destroyed || isFinishing() || isDestroyed()) return;

        routed = true;
        try {
            Intent intent = new Intent(this, TeamDark2.class);
            intent.addFlags(Intent.FLAG_ACTIVITY_NO_ANIMATION);
            startActivity(intent);
            overridePendingTransition(0, 0);
            finish();
        } catch (Throwable launchFailure) {
            // A trusted host that cannot enter the loader should still fail closed.
            routed = false;
            trusted = false;
            mainHandler.post(this::showTamperDialogGuaranteed);
        }
    }

    private void showTamperDialogGuaranteed() {
        if (destroyed || isFinishing() || isDestroyed() || trusted) return;
        if (tamperDialog != null && tamperDialog.isShowing()) return;

        try {
            AlertDialog dialog = new AlertDialog.Builder(
                    this,
                    android.R.style.Theme_Material_Dialog_Alert)
                    .setTitle("APK TAMPER DETECTED")
                    .setMessage(
                            "Unauthorized APK modification or signing-certificate change was detected.\n\n"
                                    + "This build is not trusted and cannot continue.")
                    .setCancelable(false)
                    .setPositiveButton("CLOSE LOADER", null)
                    .create();

            dialog.setCanceledOnTouchOutside(false);
            dialog.setOnKeyListener((d, keyCode, event) ->
                    keyCode == KeyEvent.KEYCODE_BACK);

            dialog.setOnShowListener(d -> {
                try {
                    dialog.getButton(AlertDialog.BUTTON_POSITIVE)
                            .setOnClickListener(v -> closeLoader());
                } catch (Throwable ignored) {
                    closeLoader();
                }
            });

            dialog.setOnDismissListener(d -> {
                tamperDialog = null;
                if (!destroyed && !isFinishing()) {
                    closeLoader();
                }
            });

            dialog.show();

            Window dialogWindow = dialog.getWindow();
            if (dialogWindow != null) {
                dialogWindow.addFlags(WindowManager.LayoutParams.FLAG_SECURE);
                dialogWindow.addFlags(WindowManager.LayoutParams.FLAG_DIM_BEHIND);
                dialogWindow.setDimAmount(0.82f);
            }

            tamperDialog = dialog;
        } catch (Throwable ignored) {
            // Some ROMs reject an ultra-early dialog before the window token settles.
            // Never crash/finish here; retry until the Activity can host the dialog.
            mainHandler.postDelayed(this::showTamperDialogGuaranteed, 180L);
        }
    }

    private void closeLoader() {
        try {
            if (tamperDialog != null) {
                tamperDialog.setOnDismissListener(null);
                if (tamperDialog.isShowing()) tamperDialog.dismiss();
            }
        } catch (Throwable ignored) {}
        tamperDialog = null;

        try {
            finishAffinity();
        } catch (Throwable ignored) {
            try {
                finish();
            } catch (Throwable ignoredAgain) {}
        }
    }

    @Override
    protected void onPostResume() {
        super.onPostResume();
        if (!trusted && !routed) {
            mainHandler.post(this::showTamperDialogGuaranteed);
        }
    }

    @Override
    public void onBackPressed() {
        if (trusted) {
            super.onBackPressed();
        } else {
            showTamperDialogGuaranteed();
        }
    }

    @Override
    protected void onDestroy() {
        destroyed = true;
        mainHandler.removeCallbacksAndMessages(null);
        try {
            if (tamperDialog != null) {
                tamperDialog.setOnDismissListener(null);
                if (tamperDialog.isShowing()) tamperDialog.dismiss();
            }
        } catch (Throwable ignored) {}
        tamperDialog = null;
        super.onDestroy();
    }
}
