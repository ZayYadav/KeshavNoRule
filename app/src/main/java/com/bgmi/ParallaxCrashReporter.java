package com.bgmi;

import android.app.ActivityManager;
import android.content.Context;
import android.os.Build;
import android.os.Process;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.FileReader;
import java.io.PrintWriter;
import java.io.StringWriter;
import java.nio.charset.StandardCharsets;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.atomic.AtomicBoolean;

/**
 * Private crash diagnostics for the Parallax Virtual host and virtual processes.
 * Reports never grant process/root access and remain inside the host app sandbox.
 */
public final class ParallaxCrashReporter {

    private static final AtomicBoolean INSTALLED = new AtomicBoolean(false);
    private static final int MAX_REPORTS = 12;
    private static final int MAX_PREVIEW_CHARS = 12000;

    private ParallaxCrashReporter() {
    }

    public static void install(Context context) {
        if (context == null || !INSTALLED.compareAndSet(false, true)) return;

        final Context app = context.getApplicationContext();
        final Thread.UncaughtExceptionHandler previous =
                Thread.getDefaultUncaughtExceptionHandler();

        Thread.setDefaultUncaughtExceptionHandler(new Thread.UncaughtExceptionHandler() {
            @Override
            public void uncaughtException(Thread thread, Throwable throwable) {
                try {
                    writeReport(app, thread, throwable);
                } catch (Throwable ignored) {
                }

                if (previous != null && previous != this) {
                    previous.uncaughtException(thread, throwable);
                    return;
                }

                Process.killProcess(Process.myPid());
                System.exit(10);
            }
        });
    }

    private static void writeReport(Context context, Thread thread, Throwable throwable)
            throws Exception {
        File dir = reportDir(context);
        if (!dir.exists() && !dir.mkdirs()) return;

        String process = sanitize(processName(context));
        String stamp = new SimpleDateFormat("yyyyMMdd-HHmmss-SSS", Locale.US)
                .format(new Date());
        File report = new File(dir, stamp + "-" + process + ".txt");

        StringWriter stack = new StringWriter();
        PrintWriter printer = new PrintWriter(stack);
        if (throwable != null) throwable.printStackTrace(printer);
        printer.flush();

        StringBuilder body = new StringBuilder(4096);
        body.append("Parallax Virtual Crash Report\n")
                .append("timestamp=").append(System.currentTimeMillis()).append('\n')
                .append("host_package=").append(context.getPackageName()).append('\n')
                .append("process=").append(processName(context)).append('\n')
                .append("pid=").append(Process.myPid()).append('\n')
                .append("thread=").append(thread == null ? "unknown" : thread.getName()).append('\n')
                .append("sdk=").append(Build.VERSION.SDK_INT).append('\n')
                .append("release=").append(Build.VERSION.RELEASE).append('\n')
                .append("manufacturer=").append(Build.MANUFACTURER).append('\n')
                .append("model=").append(Build.MODEL).append('\n')
                .append("abi=").append(Build.SUPPORTED_ABIS != null && Build.SUPPORTED_ABIS.length > 0
                        ? Build.SUPPORTED_ABIS[0] : "unknown")
                .append("\n\n")
                .append(stack);

        try (FileOutputStream out = new FileOutputStream(report, false)) {
            out.write(body.toString().getBytes(StandardCharsets.UTF_8));
            out.flush();
        }

        trimOldReports(dir);
    }

    public static File latestReport(Context context) {
        if (context == null) return null;
        File dir = reportDir(context);
        File[] files = dir.listFiles((parent, name) -> name != null && name.endsWith(".txt"));
        if (files == null || files.length == 0) return null;

        File latest = null;
        for (File file : files) {
            if (file == null || !file.isFile()) continue;
            if (latest == null || file.lastModified() > latest.lastModified()) latest = file;
        }
        return latest;
    }

    public static String readPreview(File report) {
        if (report == null || !report.isFile()) return "No crash report available.";
        StringBuilder out = new StringBuilder();
        try (BufferedReader reader = new BufferedReader(new FileReader(report))) {
            String line;
            while ((line = reader.readLine()) != null) {
                if (out.length() + line.length() + 1 > MAX_PREVIEW_CHARS) {
                    out.append("\n… report truncated …");
                    break;
                }
                out.append(line).append('\n');
            }
        } catch (Throwable throwable) {
            return "Unable to read crash report: " + throwable.getClass().getSimpleName();
        }
        return out.toString().trim();
    }

    private static File reportDir(Context context) {
        return new File(context.getFilesDir(), "crash-reports");
    }

    private static void trimOldReports(File dir) {
        File[] files = dir.listFiles((parent, name) -> name != null && name.endsWith(".txt"));
        if (files == null || files.length <= MAX_REPORTS) return;

        List<File> ordered = new ArrayList<>();
        Collections.addAll(ordered, files);
        ordered.sort(Comparator.comparingLong(File::lastModified).reversed());
        for (int i = MAX_REPORTS; i < ordered.size(); i++) {
            try {
                ordered.get(i).delete();
            } catch (Throwable ignored) {
            }
        }
    }

    private static String processName(Context context) {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                String name = android.app.Application.getProcessName();
                if (name != null && !name.trim().isEmpty()) return name;
            }
            ActivityManager am = (ActivityManager) context.getSystemService(Context.ACTIVITY_SERVICE);
            if (am != null) {
                List<ActivityManager.RunningAppProcessInfo> list = am.getRunningAppProcesses();
                if (list != null) {
                    int pid = Process.myPid();
                    for (ActivityManager.RunningAppProcessInfo info : list) {
                        if (info != null && info.pid == pid && info.processName != null) {
                            return info.processName;
                        }
                    }
                }
            }
        } catch (Throwable ignored) {
        }
        return context.getPackageName();
    }

    private static String sanitize(String value) {
        if (value == null || value.trim().isEmpty()) return "process";
        return value.replaceAll("[^A-Za-z0-9._-]", "_");
    }
}
