#include <jni.h>

// Existing implementation symbols from main.cpp. Keeping those implementation
// entrypoints untouched preserves the native auth/server contract byte-for-byte.
extern "C" jstring Java_com_team_dark_TeamDark1_getSdkKey(JNIEnv *, jclass);
extern "C" jstring Java_com_team_dark_TeamDark2_GetKey(JNIEnv *, jobject);
extern "C" jstring Java_com_team_dark_utils_TeamDark5_Version(JNIEnv *, jclass);
extern "C" jstring Java_com_team_dark_utils_TeamDark5_Link(JNIEnv *, jclass);
extern "C" jstring Java_com_team_dark_TeamDark3_exdate(JNIEnv *, jclass);
extern "C" jstring Java_com_team_dark_TeamDark3_ZENINOP(JNIEnv *, jobject);
extern "C" jstring Java_com_team_dark_TeamDark2_Check(JNIEnv *, jclass, jobject, jstring);
extern "C" jboolean Java_com_team_dark_TeamDark2_nativeVerifySignature(JNIEnv *, jobject, jobject);
extern "C" jboolean Java_com_team_dark_TeamDark2_nativeCustomIntegrity(JNIEnv *, jclass, jobject);
extern "C" jboolean Java_com_team_dark_TeamDark2_nativeVerifyServerLoader(JNIEnv *, jclass, jobject, jstring, jlong);

extern "C" JNIEXPORT jstring JNICALL
Java_com_dark_panther_core_PantherNative_getSdkKey(JNIEnv *env, jclass clazz) {
    return Java_com_team_dark_TeamDark1_getSdkKey(env, clazz);
}

extern "C" JNIEXPORT jstring JNICALL
Java_com_dark_panther_core_PantherNative_getKeyLink(JNIEnv *env, jclass clazz) {
    return Java_com_team_dark_TeamDark2_GetKey(env, clazz);
}

extern "C" JNIEXPORT jstring JNICALL
Java_com_dark_panther_core_PantherNative_versionUrl(JNIEnv *env, jclass clazz) {
    return Java_com_team_dark_utils_TeamDark5_Version(env, clazz);
}

extern "C" JNIEXPORT jstring JNICALL
Java_com_dark_panther_core_PantherNative_downloadUrl(JNIEnv *env, jclass clazz) {
    return Java_com_team_dark_utils_TeamDark5_Link(env, clazz);
}

extern "C" JNIEXPORT jstring JNICALL
Java_com_dark_panther_core_PantherNative_expiryDate(JNIEnv *env, jclass clazz) {
    return Java_com_team_dark_TeamDark3_exdate(env, clazz);
}

extern "C" JNIEXPORT jstring JNICALL
Java_com_dark_panther_core_PantherNative_sessionToken(JNIEnv *env, jclass clazz) {
    return Java_com_team_dark_TeamDark3_ZENINOP(env, clazz);
}

extern "C" JNIEXPORT jstring JNICALL
Java_com_dark_panther_core_PantherNative_check(
        JNIEnv *env,
        jclass clazz,
        jobject context,
        jstring userKey) {
    return Java_com_team_dark_TeamDark2_Check(env, clazz, context, userKey);
}

extern "C" JNIEXPORT jboolean JNICALL
Java_com_dark_panther_core_PantherNative_verifySignature(
        JNIEnv *env,
        jclass clazz,
        jobject context) {
    return Java_com_team_dark_TeamDark2_nativeVerifySignature(env, clazz, context);
}

extern "C" JNIEXPORT jboolean JNICALL
Java_com_dark_panther_core_PantherNative_customIntegrity(
        JNIEnv *env,
        jclass clazz,
        jobject context) {
    return Java_com_team_dark_TeamDark2_nativeCustomIntegrity(env, clazz, context);
}

extern "C" JNIEXPORT jboolean JNICALL
Java_com_dark_panther_core_PantherNative_verifyServerLoader(
        JNIEnv *env,
        jclass clazz,
        jobject context,
        jstring expectedHash,
        jlong expectedSize) {
    return Java_com_team_dark_TeamDark2_nativeVerifyServerLoader(
            env, clazz, context, expectedHash, expectedSize);
}
