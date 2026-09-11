package com.dark.panther.core;

import android.app.Activity;
import android.app.Dialog;
import android.content.Context;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.media.AudioAttributes;
import android.media.MediaPlayer;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.widget.TextView;

import com.dark.panther.R;
import com.team.dark.TeamDark2;

import org.lsposed.lsparanoid.Obfuscate;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicLong;

@Obfuscate
public final class PantherSecurity {
    private static final AtomicBoolean SHOWING = new AtomicBoolean(false);
    private static final String INTEGRITY_AUDIO_URL =
            "https://drive.google.com/uc?export=download&id=1ziE2Lfh4dHqYFWwAEY3nX_LCAvomyxep";
    private static final long AUDIO_VOLUME_REFRESH_MS = 20_000L;
    private static final Handler AUDIO_HANDLER = new Handler(Looper.getMainLooper());
    private static final AtomicLong AUDIO_SESSION = new AtomicLong(0L);
    private static MediaPlayer integrityPlayer;
    private static Runnable playerVolumeRefresh;

    private PantherSecurity() {}

    public static void showIntegrityFailure(Activity activity, String detail) {
        if (activity == null || activity.isFinishing() || activity.isDestroyed()) return;
        activity.runOnUiThread(() -> {
            if (!SHOWING.compareAndSet(false, true)) return;
            final long audioSession = AUDIO_SESSION.incrementAndGet();
            startIntegrityAudio(activity.getApplicationContext(), audioSession);
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
                    window.setDimAmount(0.82f);
                    window.addFlags(WindowManager.LayoutParams.FLAG_DIM_BEHIND);
                }
                TextView detailView = dialog.findViewById(R.id.integrityDetail);
                if (detailView != null) {
                    detailView.setText(detail == null || detail.trim().isEmpty()
                            ? "Dark Panther security detected an unauthorized runtime change."
                            : detail);
                }
                View close = dialog.findViewById(R.id.integrityClose);
                if (close != null) {
                    PantherEffects.applyTouchBounce(close, () -> safeExit(activity, dialog));
                }
                dialog.setOnDismissListener(d -> {
                    SHOWING.set(false);
                    stopIntegrityAudio();
                    safeFinish(activity);
                });
                dialog.show();
            } catch (Throwable ignored) {
                SHOWING.set(false);
                stopIntegrityAudio();
                safeFinish(activity);
            }
        });
    }

    public static Runnable installRuntimeGuard(Activity activity, Handler handler) {
        Runnable guard = new Runnable() {
            @Override
            public void run() {
                if (activity == null || activity.isFinishing() || activity.isDestroyed()) return;
                boolean ok;
                try {
                    ok = PantherIntegrity.verify(activity)
                            && TeamDark2.nativeVerifySignature(activity)
                            && TeamDark2.nativeCustomIntegrity(activity);
                } catch (Throwable ignored) {
                    ok = false;
                }
                if (!ok) {
                    showIntegrityFailure(activity, "Dark Panther security state changed while the loader was running.");
                    return;
                }
                try { handler.postDelayed(this, 3000L); } catch (Throwable ignored) {}
            }
        };
        try { handler.postDelayed(guard, 3000L); } catch (Throwable ignored) {}
        return guard;
    }

    private static void startIntegrityAudio(Context context, long session) {
        if (context == null) return;
        Thread worker = new Thread(() -> {
            File audioFile;
            try {
                File cacheDir = new File(context.getCacheDir(), "darkpanther_integrity");
                if (!cacheDir.exists() && !cacheDir.mkdirs()) return;
                audioFile = new File(cacheDir, "integrity_alert.mp3");
                if (!audioFile.isFile() || audioFile.length() < 1024L) {
                    File temp = new File(cacheDir, "integrity_alert.tmp");
                    downloadSilently(INTEGRITY_AUDIO_URL, temp);
                    if (!temp.isFile() || temp.length() < 1024L) {
                        try { temp.delete(); } catch (Throwable ignored) {}
                        return;
                    }
                    if (audioFile.exists()) try { audioFile.delete(); } catch (Throwable ignored) {}
                    if (!temp.renameTo(audioFile)) {
                        copyFile(temp, audioFile);
                        try { temp.delete(); } catch (Throwable ignored) {}
                    }
                }
            } catch (Throwable ignored) {
                return;
            }

            final File readyFile = audioFile;
            AUDIO_HANDLER.post(() -> {
                if (session != AUDIO_SESSION.get() || !SHOWING.get()) return;
                try {
                    releasePlayerOnly();
                    MediaPlayer player = new MediaPlayer();
                    player.setAudioAttributes(new AudioAttributes.Builder()
                            .setUsage(AudioAttributes.USAGE_MEDIA)
                            .setContentType(AudioAttributes.CONTENT_TYPE_MUSIC)
                            .build());
                    player.setDataSource(readyFile.getAbsolutePath());
                    player.setLooping(true);
                    player.setVolume(1.0f, 1.0f);
                    player.prepare();
                    player.start();
                    integrityPlayer = player;
                    playerVolumeRefresh = new Runnable() {
                        @Override public void run() {
                            if (session != AUDIO_SESSION.get() || !SHOWING.get()) return;
                            try { if (integrityPlayer != null) integrityPlayer.setVolume(1.0f, 1.0f); } catch (Throwable ignored) {}
                            try { AUDIO_HANDLER.postDelayed(this, AUDIO_VOLUME_REFRESH_MS); } catch (Throwable ignored) {}
                        }
                    };
                    AUDIO_HANDLER.postDelayed(playerVolumeRefresh, AUDIO_VOLUME_REFRESH_MS);
                } catch (Throwable ignored) {
                    releasePlayerOnly();
                }
            });
        }, "DarkPanther-IntegrityAudio");
        worker.setDaemon(true);
        worker.start();
    }

    private static void downloadSilently(String source, File target) throws Exception {
        URL current = new URL(source);
        for (int redirects = 0; redirects < 6; redirects++) {
            HttpURLConnection connection = null;
            try {
                connection = (HttpURLConnection) current.openConnection();
                connection.setInstanceFollowRedirects(false);
                connection.setConnectTimeout(12_000);
                connection.setReadTimeout(25_000);
                connection.setRequestProperty("User-Agent", "Mozilla/5.0 Android DarkPanther");
                connection.setRequestProperty("Accept", "audio/*,application/octet-stream,*/*");
                connection.connect();
                int code = connection.getResponseCode();
                if (code >= 300 && code < 400) {
                    String location = connection.getHeaderField("Location");
                    if (location == null || location.trim().isEmpty()) throw new IllegalStateException("Redirect without location");
                    current = new URL(current, location);
                    continue;
                }
                if (code < 200 || code >= 300) throw new IllegalStateException("HTTP " + code);
                try (InputStream input = connection.getInputStream(); FileOutputStream output = new FileOutputStream(target, false)) {
                    byte[] buffer = new byte[32 * 1024];
                    int read;
                    while ((read = input.read(buffer)) != -1) output.write(buffer, 0, read);
                    output.flush();
                }
                return;
            } finally {
                if (connection != null) try { connection.disconnect(); } catch (Throwable ignored) {}
            }
        }
        throw new IllegalStateException("Too many redirects");
    }

    private static void copyFile(File from, File to) throws Exception {
        try (InputStream input = new java.io.FileInputStream(from); FileOutputStream output = new FileOutputStream(to, false)) {
            byte[] buffer = new byte[32 * 1024];
            int read;
            while ((read = input.read(buffer)) != -1) output.write(buffer, 0, read);
            output.flush();
        }
    }

    private static void stopIntegrityAudio() {
        AUDIO_SESSION.incrementAndGet();
        try { if (playerVolumeRefresh != null) AUDIO_HANDLER.removeCallbacks(playerVolumeRefresh); } catch (Throwable ignored) {}
        playerVolumeRefresh = null;
        releasePlayerOnly();
    }

    private static void releasePlayerOnly() {
        MediaPlayer player = integrityPlayer;
        integrityPlayer = null;
        if (player != null) {
            try { player.stop(); } catch (Throwable ignored) {}
            try { player.reset(); } catch (Throwable ignored) {}
            try { player.release(); } catch (Throwable ignored) {}
        }
    }

    private static void safeExit(Activity activity, Dialog dialog) {
        stopIntegrityAudio();
        try { if (dialog != null && dialog.isShowing()) dialog.dismiss(); } catch (Throwable ignored) {}
        safeFinish(activity);
    }

    private static void safeFinish(Activity activity) {
        try { activity.finishAffinity(); }
        catch (Throwable ignored) { try { activity.finish(); } catch (Throwable ignoredAgain) {} }
    }
}
