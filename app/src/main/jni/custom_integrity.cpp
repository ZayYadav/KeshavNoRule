#include "custom_integrity.h"
#include <android/log.h>

#define KESHAV_INTEGRITY_TAG "KeshavIntegrity"

namespace keshav_integrity {

bool run(JNIEnv *env, jobject context) {
    if (env == nullptr || context == nullptr) {
        return false;
    }

    /*
     * ============================================================
     * KESHAV CUSTOM INTEGRITY ZONE
     * ============================================================
     * Add your own app-integrity checks here.
     *
     * Contract:
     *   return true  -> integrity accepted
     *   return false -> loader closes before showing login
     *
     * Keep this file self-contained so future security changes do
     * not require touching the panel/login implementation.
     * ============================================================
     */

    return true;
}

} // namespace keshav_integrity

extern "C"
JNIEXPORT jboolean JNICALL
Java_com_bgmi_KeshavOwner2_nativeCustomIntegrity(
        JNIEnv *env,
        jclass,
        jobject context) {
    return keshav_integrity::run(env, context) ? JNI_TRUE : JNI_FALSE;
}
