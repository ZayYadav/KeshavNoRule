#pragma once
#include <jni.h>

namespace keshav_integrity {
    // Return true to allow the app to continue.
    // Put your own integrity checks in custom_integrity.cpp only.
    bool run(JNIEnv *env, jobject context);
}
