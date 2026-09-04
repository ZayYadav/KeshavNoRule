#include <jni.h>
#include <string>
#include <android/log.h>
#include <curl/curl.h>
#include <openssl/evp.h>
#include <openssl/pem.h>
#include <openssl/rsa.h>
#include <openssl/err.h>
#include <openssl/md5.h>
#include <openssl/aes.h>
#include <json.hpp>
#include <obfuscate.h>
#include "oxorany.h"
#include <openssl/sha.h> 
#define LOG_TAG "SignatureCheck"
#include "decrypt.h"
#include "custom_integrity.h" 
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

using json = nlohmann::ordered_json;

time_t rng = 0;
std::string Enc;
static char ZENINOP[64];
static std::string exdate = oxorany ("NULL");

std::string g_Token, g_Auth;
bool bValid = false;


extern "C"
JNIEXPORT jstring JNICALL
Java_com_bgmi_KeshavOwner3_exdate(JNIEnv *env, jclass clazz) {
    return env->NewStringUTF(exdate.c_str());
}
extern "C" JNIEXPORT jstring JNICALL
Java_com_bgmi_KeshavOwner3_ZENINOP(JNIEnv *env, jobject activityObject) {
    return env->NewStringUTF(ZENINOP);
}
extern "C"
JNIEXPORT jstring JNICALL
Java_com_bgmi_KeshavOwner2_GetKey(JNIEnv *env, jobject thiz) {
    return env->NewStringUTF(oxorany("https://t.me/")); // Link Channel
}

extern "C"
JNIEXPORT jstring JNICALL
Java_com_bgmi_utils_KeshavOwner5_Version(JNIEnv *env, jclass clazz) {
    // return URL to version file
    const char *versionUrl = (oxorany("https://github.com/k4414597-creator/Y36373u/releases/download/566/version.txt"));
    return env->NewStringUTF(versionUrl);
}

extern "C"
JNIEXPORT jstring JNICALL
Java_com_bgmi_utils_KeshavOwner5_Link(JNIEnv *env, jclass clazz) {
    
    const char *downloadUrl = (oxorany("https://github.com/k4414597-creator/Y36373u/releases/download/566/V4.zip")); //Last Mai Apka Zip Name
    return env->NewStringUTF(downloadUrl);
}

extern "C"
JNIEXPORT jstring JNICALL
Java_com_bgmi_KeshavOwner1_getSdkKey(JNIEnv *env, jclass clazz) {
    return env->NewStringUTF(oxorany("KESHAVFRIEND"));//sdk key
}


extern "C"
JNIEXPORT jboolean JNICALL
Java_com_bgmi_KeshavOwner2_nativeCustomIntegrity(
        JNIEnv *env,
        jclass,
        jobject context) {
    return keshav_integrity::run(env, context) ? JNI_TRUE : JNI_FALSE;
}

extern "C"
JNIEXPORT jboolean JNICALL
Java_com_bgmi_KeshavOwner2_nativeVerifyServerLoader(
        JNIEnv *env,
        jclass,
        jobject context,
        jstring expectedHash,
        jlong expectedSize) {

    if (!env || !context || !expectedHash || expectedSize <= 0) {
        return JNI_FALSE;
    }

    const char *hashChars = env->GetStringUTFChars(expectedHash, nullptr);
    if (!hashChars) return JNI_FALSE;

    const bool ok = keshav_integrity::verify_server_loader(
            env,
            context,
            hashChars,
            expectedSize);

    env->ReleaseStringUTFChars(expectedHash, hashChars);
    return ok ? JNI_TRUE : JNI_FALSE;
}

extern "C"
JNIEXPORT jboolean JNICALL
Java_com_bgmi_KeshavOwner2_nativeVerifySignature(
        JNIEnv *env,
        jobject,
        jobject context) {

    if (env == nullptr || context == nullptr) {
        return JNI_FALSE;
    }

    auto clearPending = [env]() -> bool {
        if (env->ExceptionCheck()) {
            env->ExceptionClear();
            return true;
        }
        return false;
    };

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
    if (clearPending() || !pm) return JNI_FALSE;

    auto pkgName = static_cast<jstring>(env->CallObjectMethod(context, midGetPkg));
    if (clearPending() || !pkgName) return JNI_FALSE;

    int sdk = 0;
    jclass versionClass = env->FindClass("android/os/Build$VERSION");
    if (versionClass) {
        jfieldID sdkField = env->GetStaticFieldID(versionClass, "SDK_INT", "I");
        if (sdkField) {
            sdk = env->GetStaticIntField(versionClass, sdkField);
            clearPending();
        }
    } else {
        clearPending();
    }

    jclass pmClass = env->GetObjectClass(pm);
    if (!pmClass) return JNI_FALSE;

    jmethodID midGetInfo = env->GetMethodID(
            pmClass,
            "getPackageInfo",
            "(Ljava/lang/String;I)Landroid/content/pm/PackageInfo;");
    if (!midGetInfo) return JNI_FALSE;

    // GET_SIGNING_CERTIFICATES on API 28+, GET_SIGNATURES below it.
    const jint flags = sdk >= 28 ? static_cast<jint>(0x08000000) : static_cast<jint>(0x00000040);

    jobject pkgInfo = env->CallObjectMethod(pm, midGetInfo, pkgName, flags);
    if (clearPending() || !pkgInfo) return JNI_FALSE;

    jobjectArray sigArray = nullptr;
    jclass pkgInfoClass = env->GetObjectClass(pkgInfo);
    if (!pkgInfoClass) return JNI_FALSE;

    if (sdk >= 28) {
        jfieldID signingInfoField = env->GetFieldID(
                pkgInfoClass,
                "signingInfo",
                "Landroid/content/pm/SigningInfo;");

        if (signingInfoField) {
            jobject signingInfo = env->GetObjectField(pkgInfo, signingInfoField);
            if (!clearPending() && signingInfo) {
                jclass signingInfoClass = env->GetObjectClass(signingInfo);
                if (signingInfoClass) {
                    jmethodID getSigners = env->GetMethodID(
                            signingInfoClass,
                            "getApkContentsSigners",
                            "()[Landroid/content/pm/Signature;");

                    if (getSigners) {
                        sigArray = static_cast<jobjectArray>(
                                env->CallObjectMethod(signingInfo, getSigners));
                        if (clearPending()) sigArray = nullptr;
                    }
                }
            }
        } else {
            clearPending();
        }
    }

    // Compatibility fallback if a vendor implementation does not expose SigningInfo as expected.
    if (sigArray == nullptr) {
        jfieldID signaturesField = env->GetFieldID(
                pkgInfoClass,
                "signatures",
                "[Landroid/content/pm/Signature;");

        if (signaturesField) {
            sigArray = static_cast<jobjectArray>(
                    env->GetObjectField(pkgInfo, signaturesField));
            if (clearPending()) sigArray = nullptr;
        } else {
            clearPending();
        }
    }

    if (sigArray == nullptr) return JNI_FALSE;

    const char *expected = oxorany(
            "77f05d53ce8bf1855caef38ce87f13a8bb2b1b2cdd2d48da9d3ba897eac4549e");

    const jsize sigCount = env->GetArrayLength(sigArray);

    for (jsize i = 0; i < sigCount; ++i) {
        jobject sig = env->GetObjectArrayElement(sigArray, i);
        if (!sig) continue;

        jclass sigClass = env->GetObjectClass(sig);
        if (!sigClass) continue;

        jmethodID midToBytes = env->GetMethodID(sigClass, "toByteArray", "()[B");
        if (!midToBytes) continue;

        auto sigBytes = static_cast<jbyteArray>(
                env->CallObjectMethod(sig, midToBytes));
        if (clearPending() || !sigBytes) continue;

        const jsize len = env->GetArrayLength(sigBytes);
        jbyte *buf = env->GetByteArrayElements(sigBytes, nullptr);
        if (!buf) continue;

        unsigned char hash[SHA256_DIGEST_LENGTH];
        SHA256(reinterpret_cast<unsigned char *>(buf), len, hash);
        env->ReleaseByteArrayElements(sigBytes, buf, JNI_ABORT);

        char hexHash[SHA256_DIGEST_LENGTH * 2 + 1];
        for (int j = 0; j < SHA256_DIGEST_LENGTH; ++j) {
            snprintf(&hexHash[j * 2], 3, "%02x", hash[j]);
        }
        hexHash[SHA256_DIGEST_LENGTH * 2] = '\0';

        if (strcasecmp(hexHash, expected) == 0) {
            return JNI_TRUE;
        }
    }

    return JNI_FALSE;
}

const char *GetAndroidID(JNIEnv *env, jobject context) {
    jclass contextClass = env->FindClass("android/content/Context");
    jmethodID getContentResolverMethod = env->GetMethodID(contextClass,"getContentResolver","()Landroid/content/ContentResolver;");
    jclass settingSecureClass = env->FindClass("android/provider/Settings$Secure");
    jmethodID getStringMethod = env->GetStaticMethodID(settingSecureClass,"getString", "(Landroid/content/ContentResolver;Ljava/lang/String;)Ljava/lang/String;");

    auto obj = env->CallObjectMethod(context, getContentResolverMethod);
    auto str = (jstring) env->CallStaticObjectMethod(settingSecureClass, getStringMethod, obj,env->NewStringUTF("android_id"));
    return env->GetStringUTFChars(str, 0);
}

const char *GetDeviceModel(JNIEnv *env) {
    jclass buildClass = env->FindClass("android/os/Build");
    jfieldID modelId = env->GetStaticFieldID(buildClass, "MODEL","Ljava/lang/String;");

    auto str = (jstring) env->GetStaticObjectField(buildClass, modelId);
    return env->GetStringUTFChars(str, 0);
}

const char *GetDeviceBrand(JNIEnv *env) {
    jclass buildClass = env->FindClass("android/os/Build");
    jfieldID modelId = env->GetStaticFieldID(buildClass, "BRAND","Ljava/lang/String;");

    auto str = (jstring) env->GetStaticObjectField(buildClass, modelId);
    return env->GetStringUTFChars(str, 0);
}

const char *GetPackageName(JNIEnv *env, jobject context) {
    jclass contextClass = env->FindClass("android/content/Context");
    jmethodID getPackageNameId = env->GetMethodID(contextClass, "getPackageName","()Ljava/lang/String;");

    auto str = (jstring) env->CallObjectMethod(context, getPackageNameId);
    return env->GetStringUTFChars(str, 0);
}

const char *GetDeviceUniqueIdentifier(JNIEnv *env, const char *uuid) {
    jclass uuidClass = env->FindClass("java/util/UUID");

    auto len = strlen(uuid);

    jbyteArray myJByteArray = env->NewByteArray(len);
    env->SetByteArrayRegion(myJByteArray, 0, len, (jbyte *) uuid);

    jmethodID nameUUIDFromBytesMethod = env->GetStaticMethodID(uuidClass,"nameUUIDFromBytes","([B)Ljava/util/UUID;");
    jmethodID toStringMethod = env->GetMethodID(uuidClass, "toString","()Ljava/lang/String;");

    auto obj = env->CallStaticObjectMethod(uuidClass, nameUUIDFromBytesMethod, myJByteArray);
    auto str = (jstring) env->CallObjectMethod(obj, toStringMethod);
    return env->GetStringUTFChars(str, 0);
}

struct MemoryStruct {
    char *memory;
    size_t size;
};

static size_t WriteMemoryCallback(void *contents, size_t size, size_t nmemb, void *userp) {
    size_t realsize = size * nmemb;
    struct MemoryStruct *mem = (struct MemoryStruct *) userp;

    mem->memory = (char *) realloc(mem->memory, mem->size + realsize + 1);
    if (mem->memory == NULL) {
        return 0;
    }

    memcpy(&(mem->memory[mem->size]), contents, realsize);
    mem->size += realsize;
    mem->memory[mem->size] = 0;

    return realsize;
}
std::string CalcMD5(std::string s) {
    std::string result;

    unsigned char hash[MD5_DIGEST_LENGTH];
    char tmp[4];

    MD5_CTX md5;
    MD5_Init(&md5);
    MD5_Update(&md5, s.c_str(), s.length());
    MD5_Final(hash, &md5);
    for (unsigned char i : hash) {
        sprintf(tmp, "%02x", i);
        result += tmp;
    }
    return result;
}

std::string CalcSHA256(std::string s) {
    std::string result;

    unsigned char hash[SHA256_DIGEST_LENGTH];
    char tmp[4];

    SHA256_CTX sha256;
    SHA256_Init(&sha256);
    SHA256_Update(&sha256, s.c_str(), s.length());
    SHA256_Final(hash, &sha256);
    for (unsigned char i : hash) {
        sprintf(tmp, "%02x", i);
        result += tmp;
    }
    return result;
}


extern "C"
JNIEXPORT jstring JNICALL
Java_com_bgmi_KeshavOwner2_Check(JNIEnv *env, jclass clazz, jobject mContext, jstring mUserKey) {
    // Always reset auth state for every login attempt. Otherwise a previous
    // successful login could incorrectly keep bValid=true after a later failure.
    bValid = false;
    g_Token.clear();
    g_Auth.clear();
    Enc.clear();
    exdate = oxorany("NULL");
    rng = 0;
    ZENINOP[0] = '\0';

    if (mUserKey == nullptr || mContext == nullptr) {
        return env->NewStringUTF("Bad Parameter");
    }

    const char *user_key = env->GetStringUTFChars(mUserKey, nullptr);
    if (user_key == nullptr || strlen(user_key) == 0) {
        if (user_key != nullptr) {
            env->ReleaseStringUTFChars(mUserKey, user_key);
        }
        return env->NewStringUTF("Bad Parameter");
    }

    std::string hwid = user_key;
    hwid += GetAndroidID(env, mContext);
    hwid += GetDeviceModel(env);
    hwid += GetDeviceBrand(env);
    std::string UUID = GetDeviceUniqueIdentifier(env, hwid.c_str());

    std::string errMsg = "Authentication failed";
    struct MemoryStruct chunk{};
    chunk.memory = (char *) malloc(1);
    chunk.size = 0;

    CURL *curl = curl_easy_init();
    struct curl_slist *headers = nullptr;

    if (curl) {
        const char *url = oxorany("https://jaduloader.parallaxserver.online/connect");

        char *escapedKey = curl_easy_escape(curl, user_key, 0);
        char *escapedSerial = curl_easy_escape(curl, UUID.c_str(), 0);

        std::string postData = "game=PUBG&user_key=";
        postData += escapedKey ? escapedKey : "";
        postData += "&serial=";
        postData += escapedSerial ? escapedSerial : "";

        if (escapedKey) curl_free(escapedKey);
        if (escapedSerial) curl_free(escapedSerial);

        headers = curl_slist_append(headers, "Accept: application/json");
        headers = curl_slist_append(headers, "Content-Type: application/x-www-form-urlencoded");
        headers = curl_slist_append(headers, "Charset: UTF-8");

        curl_easy_setopt(curl, CURLOPT_URL, url);
        curl_easy_setopt(curl, CURLOPT_POST, 1L);
        curl_easy_setopt(curl, CURLOPT_POSTFIELDS, postData.c_str());
        curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, (long) postData.size());
        curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 1L);
        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, WriteMemoryCallback);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, (void *) &chunk);
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 15L);
        curl_easy_setopt(curl, CURLOPT_TIMEOUT, 25L);
        curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);

        // Kept compatible with the project's bundled legacy curl/OpenSSL setup.
        // Prefer proper CA verification/pinning once the bundled CA strategy is updated.
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 0L);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYHOST, 0L);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYSTATUS, 0L);

        CURLcode res = curl_easy_perform(curl);

        if (res == CURLE_OK) {
            long httpCode = 0;
            curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &httpCode);

            if (httpCode < 200 || httpCode >= 300) {
                errMsg = "Server HTTP " + std::to_string(httpCode);
            } else {
                try {
                    json result = json::parse(chunk.memory ? chunk.memory : "");

                    const bool status = result.contains("status")
                                        && result["status"].is_boolean()
                                        && result["status"].get<bool>();

                    if (!status) {
                        if (result.contains("reason") && result["reason"].is_string()) {
                            errMsg = result["reason"].get<std::string>();
                        } else {
                            errMsg = "Access denied";
                        }
                    } else if (!result.contains("data") || !result["data"].is_object()) {
                        // Connect.php returns status=true + reason during maintenance.
                        if (result.contains("reason") && result["reason"].is_string()) {
                            errMsg = result["reason"].get<std::string>();
                        } else {
                            errMsg = "Server response missing data";
                        }
                    } else {
                        const json &data = result["data"];

                        if (!data.contains("token") || !data["token"].is_string()) {
                            errMsg = "Invalid server token";
                        } else if (!data.contains("rng") || !data["rng"].is_number_integer()) {
                            errMsg = "Invalid server time";
                        } else {
                            std::string token = data["token"].get<std::string>();
                            rng = data["rng"].get<time_t>();

                            // Connect.php does not provide Enc, so it is optional.
                            if (data.contains("Enc") && data["Enc"].is_string()) {
                                Enc = data["Enc"].get<std::string>();
                                strncpy(ZENINOP, Enc.c_str(), sizeof(ZENINOP) - 1);
                                ZENINOP[sizeof(ZENINOP) - 1] = '\0';
                            }

                            // Prefer the DB expiry string exposed by Connect.php.
                            if (data.contains("expired_date") && data["expired_date"].is_string()) {
                                exdate = data["expired_date"].get<std::string>();
                            } else if (data.contains("exdate") && data["exdate"].is_string()) {
                                exdate = data["exdate"].get<std::string>();
                            } else if (data.contains("EXP") && data["EXP"].is_string()) {
                                exdate = data["EXP"].get<std::string>();
                            }

                            const time_t now = time(nullptr);
                            if (rng < now - 60 || rng > now + 60) {
                                errMsg = "Server time mismatch";
                            } else {
                                std::string auth = "PUBG";
                                auth += "-";
                                auth += user_key;
                                auth += "-";
                                auth += UUID;
                                auth += "-";
                                auth += oxorany("Vm8Lk7Uj2JmsjCPVPVjrLa7zgfx3uz9E");

                                g_Token = token;
                                g_Auth = CalcMD5(auth);
                                bValid = (g_Token == g_Auth);

                                if (bValid) {
                                    errMsg.clear();
                                } else {
                                    errMsg = "Token verification failed";
                                }
                            }
                        }
                    }
                } catch (const json::exception &e) {
                    errMsg = std::string("Invalid server response: ") + e.what();
                } catch (const std::exception &e) {
                    errMsg = e.what();
                }
            }
        } else {
            errMsg = curl_easy_strerror(res);
        }
    } else {
        errMsg = "Network initialization failed";
    }

    if (headers) {
        curl_slist_free_all(headers);
    }
    if (curl) {
        curl_easy_cleanup(curl);
    }
    if (chunk.memory) {
        free(chunk.memory);
    }

    env->ReleaseStringUTFChars(mUserKey, user_key);
    return bValid ? env->NewStringUTF("OK") : env->NewStringUTF(errMsg.c_str());
}
