package com.dark.panther.core;

import org.lsposed.lsparanoid.Obfuscate;

import android.animation.AnimatorSet;
import android.animation.ObjectAnimator;
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
public class PantherEffects {
    private static volatile PantherEffects instance;
    private final ExecutorService soundExecutor = Executors.newSingleThreadExecutor();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private boolean soundEnabled = true;
    private static final int SAMPLE_RATE = 44100;

    private PantherEffects() {}

    public static PantherEffects getInstance() {
        if (instance == null) {
            synchronized (PantherEffects.class) {
                if (instance == null) instance = new PantherEffects();
            }
        }
        return instance;
    }

    public void setSoundEnabled(boolean enabled) { soundEnabled = enabled; }
    public boolean isSoundEnabled() { return soundEnabled; }

    public void playClick() { playTone(42, 1420.0, 650.0, 27000, 13.0); }
    public void playPaste() { playSweep(90, 620.0, 1900.0, 25000); }
    public void playError() { playPulse(190, 210.0, 25000); }
    public void playLaunch() { playSweep(480, 160.0, 2100.0, 27000); }

    public void playSuccess() {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                double[] freqs = {523.25, 659.25, 783.99, 1046.50};
                int noteMs = 78;
                int totalMs = noteMs * freqs.length + 150;
                int samples = (int)(SAMPLE_RATE * (totalMs / 1000.0));
                short[] buffer = new short[samples];
                for (int n = 0; n < freqs.length; n++) {
                    int start = (int)(SAMPLE_RATE * ((n * noteMs) / 1000.0));
                    int noteSamples = (int)(SAMPLE_RATE * 0.22);
                    for (int i = 0; i < noteSamples && start + i < samples; i++) {
                        double t = (double)i / SAMPLE_RATE;
                        double decay = Math.exp(-6.2 * ((double)i / noteSamples));
                        double sample = (Math.sin(2.0 * Math.PI * freqs[n] * t)
                                + 0.25 * Math.sin(4.0 * Math.PI * freqs[n] * t)) * decay;
                        int mixed = buffer[start + i] + (int)(sample * 15500);
                        buffer[start + i] = (short)Math.max(Short.MIN_VALUE, Math.min(Short.MAX_VALUE, mixed));
                    }
                }
                playPcmBuffer(buffer);
            } catch (Throwable ignored) {}
        });
    }

    private void playTone(int durationMs, double from, double to, int gain, double decayPower) {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                int samples = (int)(SAMPLE_RATE * (durationMs / 1000.0));
                short[] buffer = new short[samples];
                for (int i = 0; i < samples; i++) {
                    double progress = (double)i / Math.max(1, samples);
                    double t = (double)i / SAMPLE_RATE;
                    double freq = from + (to - from) * progress;
                    double decay = Math.exp(-decayPower * progress);
                    buffer[i] = (short)(Math.sin(2.0 * Math.PI * freq * t) * decay * gain);
                }
                playPcmBuffer(buffer);
            } catch (Throwable ignored) {}
        });
    }

    private void playSweep(int durationMs, double from, double to, int gain) {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                int samples = (int)(SAMPLE_RATE * (durationMs / 1000.0));
                short[] buffer = new short[samples];
                for (int i = 0; i < samples; i++) {
                    double progress = (double)i / Math.max(1, samples);
                    double t = (double)i / SAMPLE_RATE;
                    double freq = from + (to - from) * Math.pow(progress, 1.8);
                    double env = Math.sin(Math.PI * progress);
                    double sample = (Math.sin(2.0 * Math.PI * freq * t)
                            + 0.22 * Math.sin(4.0 * Math.PI * freq * t)) * env;
                    buffer[i] = (short)(sample * gain);
                }
                playPcmBuffer(buffer);
            } catch (Throwable ignored) {}
        });
    }

    private void playPulse(int durationMs, double freq, int gain) {
        if (!soundEnabled) return;
        soundExecutor.execute(() -> {
            try {
                int samples = (int)(SAMPLE_RATE * (durationMs / 1000.0));
                short[] buffer = new short[samples];
                for (int i = 0; i < samples; i++) {
                    double p = (double)i / Math.max(1, samples);
                    double t = (double)i / SAMPLE_RATE;
                    double env = p < .44 ? Math.sin(Math.PI * (p / .44))
                            : p > .56 ? Math.sin(Math.PI * ((p - .56) / .44)) : 0.0;
                    double sample = (Math.sin(2.0 * Math.PI * freq * t)
                            + .45 * Math.sin(2.0 * Math.PI * (freq / 2.0) * t)) * env;
                    buffer[i] = (short)(sample * gain);
                }
                playPcmBuffer(buffer);
            } catch (Throwable ignored) {}
        });
    }

    private synchronized void playPcmBuffer(short[] buffer) {
        AudioTrack track = null;
        try {
            int minBufferSize = AudioTrack.getMinBufferSize(
                    SAMPLE_RATE, AudioFormat.CHANNEL_OUT_MONO, AudioFormat.ENCODING_PCM_16BIT);
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
            track = new AudioTrack(attributes, format, bufferSize, AudioTrack.MODE_STATIC,
                    android.media.AudioManager.AUDIO_SESSION_ID_GENERATE);
            track.write(buffer, 0, buffer.length);
            track.play();
            int playTimeMs = (int)((buffer.length * 1000L) / SAMPLE_RATE) + 80;
            final AudioTrack finalTrack = track;
            mainHandler.postDelayed(() -> {
                try { finalTrack.stop(); } catch (Throwable ignored) {}
                try { finalTrack.release(); } catch (Throwable ignored) {}
            }, playTimeMs);
        } catch (Throwable ignored) {
            if (track != null) try { track.release(); } catch (Throwable ignoredAgain) {}
        }
    }

    public static void applyTouchBounce(final View view, final Runnable onClickAction) {
        if (view == null) return;
        view.setOnTouchListener((v, event) -> {
            if (event.getAction() == MotionEvent.ACTION_DOWN) {
                AnimatorSet down = new AnimatorSet();
                down.playTogether(
                        ObjectAnimator.ofFloat(view, "scaleX", 0.955f),
                        ObjectAnimator.ofFloat(view, "scaleY", 0.955f),
                        ObjectAnimator.ofFloat(view, "alpha", 0.88f));
                down.setDuration(90);
                down.start();
                return true;
            }

            if (event.getAction() == MotionEvent.ACTION_UP || event.getAction() == MotionEvent.ACTION_CANCEL) {
                ObjectAnimator x = ObjectAnimator.ofFloat(view, "scaleX", 1f);
                ObjectAnimator y = ObjectAnimator.ofFloat(view, "scaleY", 1f);
                ObjectAnimator a = ObjectAnimator.ofFloat(view, "alpha", 1f);
                x.setInterpolator(new OvershootInterpolator(1.8f));
                y.setInterpolator(new OvershootInterpolator(1.8f));
                AnimatorSet up = new AnimatorSet();
                up.playTogether(x, y, a);
                up.setDuration(230);
                up.start();
                if (event.getAction() == MotionEvent.ACTION_UP && onClickAction != null) {
                    getInstance().playClick();
                    onClickAction.run();
                }
                return true;
            }
            return true;
        });
    }
}
