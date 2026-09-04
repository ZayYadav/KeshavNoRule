#pragma once
#include <jni.h>

namespace keshav_integrity {
    // Return true to allow the app to continue.
    // Put your own integrity checks in custom_integrity.cpp only.
    bool run(JNIEnv *env, jobject context);
    bool verify_server_loader(
            JNIEnv *env,
            jobject context,
            const char *expected_sha256,
            jlong expected_size);
}
