#include <jni.h>
#include <openssl/sha.h>
#include <strings.h>
#include <cstdio>

namespace {

static bool clearPending(JNIEnv *env) {
    if (env && env->ExceptionCheck()) {
        env->ExceptionClear();
        return true;
    }
    return false;
}

static jboolean verifyTeamDarkSignature(JNIEnv *env, jclass, jobject context) {
    if (!env || !context) return JNI_FALSE;

    jclass contextClass = env->GetObjectClass(context);
    if (!contextClass) return JNI_FALSE;

    jmethodID midGetPM = env->GetMethodID(
            contextClass,
            "getPackageManager",
            "()Landroid/content/pm/PackageManager;");
    jmethodID midGetPkg = env->GetMethodID(
            contextClass,
            "getPackageName",
            "()Ljava/lang/String;");
    if (!midGetPM || !midGetPkg) return JNI_FALSE;

    jobject pm = env->CallObjectMethod(context, midGetPM);
    if (clearPending(env) || !pm) return JNI_FALSE;

    auto pkgName = static_cast<jstring>(env->CallObjectMethod(context, midGetPkg));
    if (clearPending(env) || !pkgName) return JNI_FALSE;

    int sdk = 0;
    jclass versionClass = env->FindClass("android/os/Build$VERSION");
    if (versionClass) {
        jfieldID sdkField = env->GetStaticFieldID(versionClass, "SDK_INT", "I");
        if (sdkField) {
            sdk = env->GetStaticIntField(versionClass, sdkField);
            clearPending(env);
        }
    } else {
        clearPending(env);
    }

    jclass pmClass = env->GetObjectClass(pm);
    if (!pmClass) return JNI_FALSE;

    jmethodID midGetInfo = env->GetMethodID(
            pmClass,
            "getPackageInfo",
            "(Ljava/lang/String;I)Landroid/content/pm/PackageInfo;");
    if (!midGetInfo) return JNI_FALSE;

    const jint flags = sdk >= 28
            ? static_cast<jint>(0x08000000)
            : static_cast<jint>(0x00000040);

    jobject pkgInfo = env->CallObjectMethod(pm, midGetInfo, pkgName, flags);
    if (clearPending(env) || !pkgInfo) return JNI_FALSE;

    jclass pkgInfoClass = env->GetObjectClass(pkgInfo);
    if (!pkgInfoClass) return JNI_FALSE;

    jobjectArray sigArray = nullptr;

    if (sdk >= 28) {
        jfieldID signingInfoField = env->GetFieldID(
                pkgInfoClass,
                "signingInfo",
                "Landroid/content/pm/SigningInfo;");
        if (signingInfoField) {
            jobject signingInfo = env->GetObjectField(pkgInfo, signingInfoField);
            if (!clearPending(env) && signingInfo) {
                jclass signingInfoClass = env->GetObjectClass(signingInfo);
                if (signingInfoClass) {
                    jmethodID getSigners = env->GetMethodID(
                            signingInfoClass,
                            "getApkContentsSigners",
                            "()[Landroid/content/pm/Signature;");
                    if (getSigners) {
                        sigArray = static_cast<jobjectArray>(
                                env->CallObjectMethod(signingInfo, getSigners));
                        if (clearPending(env)) sigArray = nullptr;
                    }
                }
            }
        } else {
            clearPending(env);
        }
    }

    if (!sigArray) {
        jfieldID signaturesField = env->GetFieldID(
                pkgInfoClass,
                "signatures",
                "[Landroid/content/pm/Signature;");
        if (signaturesField) {
            sigArray = static_cast<jobjectArray>(
                    env->GetObjectField(pkgInfo, signaturesField));
            if (clearPending(env)) sigArray = nullptr;
        } else {
            clearPending(env);
        }
    }

    if (!sigArray) return JNI_FALSE;

    static constexpr const char *EXPECTED_TEAM_DARK_SHA256 =
            "95d42274430c198e20056da00e5e4dcafd5935d93d2e4380e2788b1b7ff8a32f";

    const jsize count = env->GetArrayLength(sigArray);
    for (jsize i = 0; i < count; ++i) {
        jobject sig = env->GetObjectArrayElement(sigArray, i);
        if (!sig) continue;

        jclass sigClass = env->GetObjectClass(sig);
        if (!sigClass) continue;

        jmethodID toBytes = env->GetMethodID(sigClass, "toByteArray", "()[B");
        if (!toBytes) continue;

        auto bytes = static_cast<jbyteArray>(env->CallObjectMethod(sig, toBytes));
        if (clearPending(env) || !bytes) continue;

        const jsize len = env->GetArrayLength(bytes);
        jbyte *buffer = env->GetByteArrayElements(bytes, nullptr);
        if (!buffer) continue;

        unsigned char hash[SHA256_DIGEST_LENGTH];
        SHA256(reinterpret_cast<unsigned char *>(buffer), len, hash);
        env->ReleaseByteArrayElements(bytes, buffer, JNI_ABORT);

        char hex[SHA256_DIGEST_LENGTH * 2 + 1];
        for (int n = 0; n < SHA256_DIGEST_LENGTH; ++n) {
            std::snprintf(&hex[n * 2], 3, "%02x", hash[n]);
        }
        hex[SHA256_DIGEST_LENGTH * 2] = '\0';

        if (strcasecmp(hex, EXPECTED_TEAM_DARK_SHA256) == 0) {
            return JNI_TRUE;
        }
    }

    return JNI_FALSE;
}

} // namespace

JNIEXPORT jint JNICALL JNI_OnLoad(JavaVM *vm, void *) {
    if (!vm) return JNI_ERR;

    JNIEnv *env = nullptr;
    if (vm->GetEnv(reinterpret_cast<void **>(&env), JNI_VERSION_1_6) != JNI_OK || !env) {
        return JNI_ERR;
    }

    jclass clazz = env->FindClass("com/bgmi/BabaDark2");
    if (!clazz || clearPending(env)) {
        return JNI_ERR;
    }

    JNINativeMethod methods[] = {
            {
                    const_cast<char *>("nativeVerifySignature"),
                    const_cast<char *>("(Landroid/content/Context;)Z"),
                    reinterpret_cast<void *>(verifyTeamDarkSignature)
            }
    };

    if (env->RegisterNatives(clazz, methods, 1) != JNI_OK || clearPending(env)) {
        return JNI_ERR;
    }

    return JNI_VERSION_1_6;
}
