package com.bgmi.utils;

import org.lsposed.lsparanoid.Obfuscate;

import android.animation.AnimatorSet;
import android.animation.ObjectAnimator;
import android.content.Context;
import android.media.AudioAttributes;
import android.media.AudioFormat;
import android.media.AudioTrack;
import android.os.Handler;
import android.os.Looper;
import android.view.MotionEvent;
import android.view.View;
import android.view.animation.OvershootInterpolator;

import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

@Obfuscate
public class KeshavOwner7 {

    private static volatile KeshavOwner7 instance;
    private final ExecutorService soundExecutor = Executors.newSingleThreadExecutor();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private boolean soundEnabled = true;

    private static final int SAMPLE_RATE = 44100;

    private KeshavOwner7() {}

    public static KeshavOwner7 getInstance() {
        if (instance == null) {
            synchronized (KeshavOwner7.class) {
                if (instance == null) {
                    instance = new KeshavOwner7();
                }
            }
        }
        return instance;
    }

    public void setSoundEnabled(boolean enabled) {
        this.soundEnabled = enabled;
    }

    public boolean isSoundEnabled() {
        return soundEnabled;
    }

    /* ================= SOUND PLAYERS ================= */

    /**
     * Crisp cyber click sound for buttons
     */
    public void playClick() {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                int durationMs = 35;
                int numSamples = (int) (SAMPLE_RATE * (durationMs / 1000.0));
                short[] buffer = new short[numSamples];

                for (int i = 0; i < numSamples; i++) {
                    double t = (double) i / SAMPLE_RATE;
                    double freq = 1400.0 - (900.0 * ((double) i / numSamples));
                    double decay = Math.exp(-12.0 * ((double) i / numSamples));
                    double sample = Math.sin(2.0 * Math.PI * freq * t) * decay;
                    buffer[i] = (short) (sample * 28000);
                }
                playPcmBuffer(buffer);
            } catch (Exception ignored) {}
        });
    }

    /**
     * Modern digital swoosh / blip for pasting key
     */
    public void playPaste() {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                int durationMs = 80;
                int numSamples = (int) (SAMPLE_RATE * (durationMs / 1000.0));
                short[] buffer = new short[numSamples];

                for (int i = 0; i < numSamples; i++) {
                    double t = (double) i / SAMPLE_RATE;
                    double progress = (double) i / numSamples;
                    double freq = 800.0 + (1400.0 * Math.sqrt(progress));
                    double envelope = Math.sin(Math.PI * progress);
                    double sample = Math.sin(2.0 * Math.PI * freq * t) * envelope;
                    buffer[i] = (short) (sample * 26000);
                }
                playPcmBuffer(buffer);
            } catch (Exception ignored) {}
        });
    }

    /**
     * Ascending Harmonic Sci-Fi Chime for Login Success
     */
    public void playSuccess() {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                // Notes: E5 (659.25), G#5 (830.61), B5 (987.77), E6 (1318.51)
                double[] freqs = {659.25, 830.61, 987.77, 1318.51};
                int noteDurationMs = 80;
                int totalDurationMs = noteDurationMs * freqs.length + 150;
                int numSamples = (int) (SAMPLE_RATE * (totalDurationMs / 1000.0));
                short[] buffer = new short[numSamples];

                for (int n = 0; n < freqs.length; n++) {
                    int startSample = (int) (SAMPLE_RATE * ((n * noteDurationMs) / 1000.0));
                    int noteSamples = (int) (SAMPLE_RATE * (0.22)); // with sustain
                    double freq = freqs[n];

                    for (int i = 0; i < noteSamples && (startSample + i) < numSamples; i++) {
                        double t = (double) i / SAMPLE_RATE;
                        double decay = Math.exp(-6.0 * ((double) i / noteSamples));
                        double sample = (Math.sin(2.0 * Math.PI * freq * t) + 0.3 * Math.sin(4.0 * Math.PI * freq * t)) * decay;
                        buffer[startSample + i] = (short) Math.max(Short.MIN_VALUE, Math.min(Short.MAX_VALUE, buffer[startSample + i] + (sample * 16000)));
                    }
                }
                playPcmBuffer(buffer);
            } catch (Exception ignored) {}
        });
    }

    /**
     * Low warning double pulse for Login / Validation Error
     */
    public void playError() {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                int durationMs = 180;
                int numSamples = (int) (SAMPLE_RATE * (durationMs / 1000.0));
                short[] buffer = new short[numSamples];

                for (int i = 0; i < numSamples; i++) {
                    double t = (double) i / SAMPLE_RATE;
                    double progress = (double) i / numSamples;
                    // Two pulses
                    double pulseEnv = (progress < 0.45) ? Math.sin(Math.PI * (progress / 0.45)) :
                                      (progress > 0.55) ? Math.sin(Math.PI * ((progress - 0.55) / 0.45)) : 0;
                    double sample = (Math.sin(2.0 * Math.PI * 220.0 * t) + 0.5 * Math.sin(2.0 * Math.PI * 110.0 * t)) * pulseEnv;
                    buffer[i] = (short) (sample * 25000);
                }
                playPcmBuffer(buffer);
            } catch (Exception ignored) {}
        });
    }

    /**
     * High-energy Sci-Fi warp power-up sound for Start Game
     */
    public void playLaunch() {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                int durationMs = 450;
                int numSamples = (int) (SAMPLE_RATE * (durationMs / 1000.0));
                short[] buffer = new short[numSamples];

                for (int i = 0; i < numSamples; i++) {
                    double t = (double) i / SAMPLE_RATE;
                    double progress = (double) i / numSamples;
                    double freq = 180.0 + (1600.0 * Math.pow(progress, 2.2));
                    double env = (progress < 0.8) ? Math.min(1.0, progress * 4.0) : (1.0 - progress) * 5.0;
                    double sample = (Math.sin(2.0 * Math.PI * freq * t) + 0.35 * Math.sin(4.0 * Math.PI * freq * t)) * env;
                    buffer[i] = (short) (sample * 27000);
                }
                playPcmBuffer(buffer);
            } catch (Exception ignored) {}
        });
    }

    private synchronized void playPcmBuffer(short[] buffer) {
        AudioTrack track = null;
        try {
            int minBufferSize = AudioTrack.getMinBufferSize(
                    SAMPLE_RATE,
                    AudioFormat.CHANNEL_OUT_MONO,
                    AudioFormat.ENCODING_PCM_16BIT
            );

            int bufferSize = Math.max(buffer.length * 2, minBufferSize);

            AudioAttributes attributes = new AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_GAME)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build();

            AudioFormat format = new AudioFormat.Builder()
                    .setSampleRate(SAMPLE_RATE)
                    .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
                    .setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                    .build();

            track = new AudioTrack(
                    attributes,
                    format,
                    bufferSize,
                    AudioTrack.MODE_STATIC,
                    android.media.AudioManager.AUDIO_SESSION_ID_GENERATE
            );

            track.write(buffer, 0, buffer.length);
            track.play();

            // Allow playback to finish then release
            int playTimeMs = (int) ((buffer.length * 1000L) / SAMPLE_RATE) + 60;
            final AudioTrack finalTrack = track;
            mainHandler.postDelayed(() -> {
                try {
                    finalTrack.stop();
                    finalTrack.release();
                } catch (Exception ignored) {}
            }, playTimeMs);

        } catch (Exception e) {
            if (track != null) {
                try { track.release(); } catch (Exception ignored) {}
            }
        }
    }

    /* ================= TOUCH BOUNCE ANIMATION HELPER ================= */

    /**
     * Attaches interactive spring-bounce animation & cyber click sound to any view
     */
    public static void applyTouchBounce(final View view, final Runnable onClickAction) {
        if (view == null) return;

        view.setOnTouchListener((v, event) -> {
            switch (event.getAction()) {
                case MotionEvent.ACTION_DOWN:
                    ObjectAnimator scaleDownX = ObjectAnimator.ofFloat(view, "scaleX", 0.94f);
                    ObjectAnimator scaleDownY = ObjectAnimator.ofFloat(view, "scaleY", 0.94f);
                    scaleDownX.setDuration(100);
                    scaleDownY.setDuration(100);
                    scaleDownX.start();
                    scaleDownY.start();
                    break;

                case MotionEvent.ACTION_UP:
                case MotionEvent.ACTION_CANCEL:
                    ObjectAnimator scaleUpX = ObjectAnimator.ofFloat(view, "scaleX", 1.0f);
                    ObjectAnimator scaleUpY = ObjectAnimator.ofFloat(view, "scaleY", 1.0f);
                    scaleUpX.setDuration(220);
                    scaleUpY.setDuration(220);
                    scaleUpX.setInterpolator(new OvershootInterpolator(2.5f));
                    scaleUpY.setInterpolator(new OvershootInterpolator(2.5f));

                    AnimatorSet set = new AnimatorSet();
                    set.playTogether(scaleUpX, scaleUpY);
                    set.start();

                    if (event.getAction() == MotionEvent.ACTION_UP && onClickAction != null) {
                        try {
                            getInstance().playClick();
                            onClickAction.run();
                        } catch (Throwable ignored) {
                            // Button callbacks must never terminate the main/UI thread.
                        }
                    }
                    break;
            }
            return true;
        });
    }
}
