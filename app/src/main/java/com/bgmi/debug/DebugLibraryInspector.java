package com.bgmi.debug;

import java.io.File;
import java.io.FileInputStream;
import java.security.MessageDigest;
import java.util.Locale;

/** Validates opt-in debug artifacts before they are offered to a virtual app. */
public final class DebugLibraryInspector {

    public static final long MAX_BYTES = 64L * 1024L * 1024L;
    private static final int ELF_HEADER_BYTES = 20;
    private static final int ELFCLASS64 = 2;
    private static final int ELFDATA2LSB = 1;
    private static final int ET_DYN = 3;
    private static final int EM_AARCH64 = 183;

    private DebugLibraryInspector() {
    }

    public static Result inspect(File file) {
        if (file == null || !file.isFile()) return Result.reject("file is missing");
        if (!file.getName().toLowerCase(Locale.US).endsWith(".so")) {
            return Result.reject("file name must end in .so");
        }
        long size = file.length();
        if (size < ELF_HEADER_BYTES) return Result.reject("ELF header is truncated");
        if (size > MAX_BYTES) return Result.reject("debug library exceeds 64 MB");

        byte[] header = new byte[ELF_HEADER_BYTES];
        try (FileInputStream input = new FileInputStream(file)) {
            int offset = 0;
            while (offset < header.length) {
                int read = input.read(header, offset, header.length - offset);
                if (read < 0) break;
                offset += read;
            }
            if (offset != header.length) return Result.reject("ELF header is truncated");
        } catch (Exception exception) {
            return Result.reject("cannot read debug library");
        }

        if ((header[0] & 0xff) != 0x7f || header[1] != 'E'
                || header[2] != 'L' || header[3] != 'F') {
            return Result.reject("file is not ELF");
        }
        if ((header[4] & 0xff) != ELFCLASS64) {
            return Result.reject("library is not 64-bit");
        }
        if ((header[5] & 0xff) != ELFDATA2LSB) {
            return Result.reject("library uses unsupported byte order");
        }

        int type = u16le(header, 16);
        if (type != ET_DYN) return Result.reject("ELF is not a shared object");
        int machine = u16le(header, 18);
        if (machine != EM_AARCH64) return Result.reject("library ABI is not arm64-v8a");

        return Result.accept(size);
    }

    public static String sha256(File file) throws Exception {
        MessageDigest digest = MessageDigest.getInstance("SHA-256");
        byte[] buffer = new byte[32 * 1024];
        try (FileInputStream input = new FileInputStream(file)) {
            int read;
            while ((read = input.read(buffer)) != -1) digest.update(buffer, 0, read);
        }
        StringBuilder out = new StringBuilder(64);
        for (byte value : digest.digest()) {
            out.append(String.format(Locale.US, "%02x", value & 0xff));
        }
        return out.toString();
    }

    private static int u16le(byte[] value, int offset) {
        return (value[offset] & 0xff) | ((value[offset + 1] & 0xff) << 8);
    }

    public static final class Result {
        public final boolean compatible;
        public final String message;
        public final long size;

        private Result(boolean compatible, String message, long size) {
            this.compatible = compatible;
            this.message = message;
            this.size = size;
        }

        private static Result accept(long size) {
            return new Result(true, "arm64-v8a ELF shared object", size);
        }

        private static Result reject(String message) {
            return new Result(false, message, 0L);
        }
    }
}
