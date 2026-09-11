/*
 * BEWAFA SERVER ARMv7 compatibility shim.
 *
 * The pinned legacy OpenSSL 1.1.0c libcrypto.a was built with an older
 * Android/GCC toolchain and references the legacy libgcc helper
 * __sync_add_and_fetch_4. Modern Android NDK/Clang does not export that
 * helper for this link, so provide the exact ARMv7 atomic primitive here.
 *
 * Signature expected by the legacy object:
 *   int __sync_add_and_fetch_4(volatile int *ptr, int value)
 *
 * r0 = ptr, r1 = value, return r0 = new value.
 */

.syntax unified
.text
.align 2
.thumb

.global __sync_add_and_fetch_4
.type __sync_add_and_fetch_4, %function
.thumb_func
__sync_add_and_fetch_4:
    dmb     ish
1:
    ldrex   r2, [r0]
    add     r2, r2, r1
    strex   r3, r2, [r0]
    cmp     r3, #0
    bne     1b
    mov     r0, r2
    dmb     ish
    bx      lr
.size __sync_add_and_fetch_4, .-__sync_add_and_fetch_4

.section .note.GNU-stack,"",%progbits
