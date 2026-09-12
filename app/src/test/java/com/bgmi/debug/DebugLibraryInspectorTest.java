package com.bgmi.debug;

import org.junit.Test;

import java.io.File;
import java.io.FileOutputStream;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public class DebugLibraryInspectorTest {

    @Test
    public void acceptsArm64SharedObject() throws Exception {
        File file = elf("valid.so", 2, 1, 3, 183);
        DebugLibraryInspector.Result result = DebugLibraryInspector.inspect(file);
        assertTrue(result.message, result.compatible);
        assertEquals("arm64-v8a ELF shared object", result.message);
        assertEquals(64, DebugLibraryInspector.sha256(file).length());
    }

    @Test
    public void rejectsExecutableAndWrongAbi() throws Exception {
        DebugLibraryInspector.Result executable =
                DebugLibraryInspector.inspect(elf("exec.so", 2, 1, 2, 183));
        assertFalse(executable.compatible);
        assertEquals("ELF is not a shared object", executable.message);

        DebugLibraryInspector.Result arm32 =
                DebugLibraryInspector.inspect(elf("arm32.so", 1, 1, 3, 40));
        assertFalse(arm32.compatible);
        assertEquals("library is not 64-bit", arm32.message);
    }

    @Test
    public void rejectsMagicOnlyFile() throws Exception {
        File file = File.createTempFile("magic", ".so");
        file.deleteOnExit();
        try (FileOutputStream output = new FileOutputStream(file)) {
            output.write(new byte[]{0x7f, 'E', 'L', 'F'});
        }
        DebugLibraryInspector.Result result = DebugLibraryInspector.inspect(file);
        assertFalse(result.compatible);
        assertEquals("ELF header is truncated", result.message);
    }

    private static File elf(String name, int elfClass, int byteOrder, int type, int machine)
            throws Exception {
        File dir = new File(System.getProperty("java.io.tmpdir"), "parallax-debug-tests");
        assertTrue(dir.isDirectory() || dir.mkdirs());
        File file = new File(dir, name);
        file.deleteOnExit();
        byte[] header = new byte[64];
        header[0] = 0x7f;
        header[1] = 'E';
        header[2] = 'L';
        header[3] = 'F';
        header[4] = (byte) elfClass;
        header[5] = (byte) byteOrder;
        header[16] = (byte) type;
        header[17] = (byte) (type >>> 8);
        header[18] = (byte) machine;
        header[19] = (byte) (machine >>> 8);
        try (FileOutputStream output = new FileOutputStream(file, false)) {
            output.write(header);
        }
        return file;
    }
}
