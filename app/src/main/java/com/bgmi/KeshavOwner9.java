package com.bgmi;

import android.app.Activity;
import android.app.Dialog;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.widget.TextView;

import org.lsposed.lsparanoid.Obfuscate;

import java.util.concurrent.atomic.AtomicBoolean;

@Obfuscate
public final class KeshavOwner9 {

    private static final AtomicBoolean SHOWING = new AtomicBoolean(false);

    private KeshavOwner9() {}

    public static void showIntegrityFailure(Activity activity, String detail) {
        if (activity == null || activity.isFinishing() || activity.isDestroyed()) return;

        activity.runOnUiThread(() -> {
            if (!SHOWING.compareAndSet(false, true)) return;

            try {
                Dialog dialog = new Dialog(activity);
                dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);
                dialog.setContentView(R.layout.integrity_failure_dialog);
                dialog.setCancelable(false);
                dialog.setCanceledOnTouchOutside(false);

                Window window = dialog.getWindow();
                if (window != null) {
                    window.setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
                    window.addFlags(WindowManager.LayoutParams.FLAG_SECURE);
                    window.setDimAmount(0.78f);
                    window.addFlags(WindowManager.LayoutParams.FLAG_DIM_BEHIND);
                }

                TextView detailView = dialog.findViewById(R.id.integrityDetail);
                if (detailView != null) {
                    detailView.setText(detail == null || detail.trim().isEmpty()
                            ? "Unauthorized modification or runtime injection was detected."
                            : detail);
                }

                View close = dialog.findViewById(R.id.integrityClose);
                if (close != null) {
                    close.setOnClickListener(v -> safeExit(activity, dialog));
                }

                dialog.setOnDismissListener(d -> {
                    SHOWING.set(false);
                    safeFinish(activity);
                });

                dialog.show();
            } catch (Throwable ignored) {
                SHOWING.set(false);
                safeFinish(activity);
            }
        });
    }

    public static Runnable installRuntimeGuard(Activity activity, Handler handler) {
        Runnable guard = new Runnable() {
            @Override
            public void run() {
                if (activity == null || activity.isFinishing() || activity.isDestroyed()) return;

                boolean ok = false;
                try {
                    ok = KeshavOwner8.verify(activity)
                            && KeshavOwner2.nativeVerifySignature(activity)
                            && KeshavOwner2.nativeCustomIntegrity(activity);
                } catch (Throwable ignored) {
                    ok = false;
                }

                if (!ok) {
                    showIntegrityFailure(activity,
                            "Security integrity changed while the loader was running.");
                    return;
                }

                try {
                    handler.postDelayed(this, 3000L);
                } catch (Throwable ignored) {
                    // Do not crash if the Activity is already shutting down.
                }
            }
        };

        try {
            handler.postDelayed(guard, 3000L);
        } catch (Throwable ignored) {}
        return guard;
    }

    private static void safeExit(Activity activity, Dialog dialog) {
        try {
            if (dialog != null && dialog.isShowing()) dialog.dismiss();
        } catch (Throwable ignored) {}
        safeFinish(activity);
    }

    private static void safeFinish(Activity activity) {
        try {
            activity.finishAffinity();
        } catch (Throwable ignored) {
            try { activity.finish(); } catch (Throwable ignoredAgain) {}
        }
    }
}
