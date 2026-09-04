#include "custom_integrity.h"
#include "oxorany.h"

#include <android/log.h>
#include <openssl/sha.h>
#include <strings.h>
#include <cstdio>
#include <dirent.h>
#include <fstream>
#include <string>
#include <algorithm>

#define KESHAV_INTEGRITY_TAG "KeshavIntegrity"

namespace {

static bool endsWith(const std::string &value, const std::string &suffix) {
    if (value.size() < suffix.size()) return false;
    return value.compare(value.size() - suffix.size(), suffix.size(), suffix) == 0;
}

static std::string baseName(const std::string &path) {
    const auto slash = path.find_last_of('/');
    return slash == std::string::npos ? path : path.substr(slash + 1);
}

static bool isAllowedPackagedLibName(const std::string &name) {
    return name == std::string(oxorany("libKeshavOwner.so"))
        || name == std::string(oxorany("libKESHAVXOWNERCore.so"));
}

static std::string getJavaFilePath(
        JNIEnv *env,
        jobject context,
        const char *contextMethodName) {

    if (!env || !context || !contextMethodName) return {};

    jclass contextClass = env->GetObjectClass(context);
    if (!contextClass) return {};

    jmethodID method = env->GetMethodID(
            contextClass,
            contextMethodName,
            "()Ljava/io/File;");
    if (!method) return {};

    jobject fileObj = env->CallObjectMethod(context, method);
    if (env->ExceptionCheck()) {
        env->ExceptionClear();
        return {};
    }
    if (!fileObj) return {};

    jclass fileClass = env->GetObjectClass(fileObj);
    if (!fileClass) return {};

    jmethodID canonicalMethod = env->GetMethodID(
            fileClass,
            "getCanonicalPath",
            "()Ljava/lang/String;");
    if (!canonicalMethod) return {};

    auto pathString = static_cast<jstring>(
            env->CallObjectMethod(fileObj, canonicalMethod));
    if (env->ExceptionCheck()) {
        env->ExceptionClear();
        return {};
    }
    if (!pathString) return {};

    const char *chars = env->GetStringUTFChars(pathString, nullptr);
    if (!chars) return {};

    std::string result(chars);
    env->ReleaseStringUTFChars(pathString, chars);
    return result;
}

static std::string getNativeLibraryDir(JNIEnv *env, jobject context) {
    if (!env || !context) return {};

    jclass contextClass = env->GetObjectClass(context);
    if (!contextClass) return {};

    jmethodID getApplicationInfo = env->GetMethodID(
            contextClass,
            "getApplicationInfo",
            "()Landroid/content/pm/ApplicationInfo;");
    if (!getApplicationInfo) return {};

    jobject appInfo = env->CallObjectMethod(context, getApplicationInfo);
    if (env->ExceptionCheck()) {
        env->ExceptionClear();
        return {};
    }
    if (!appInfo) return {};

    jclass appInfoClass = env->GetObjectClass(appInfo);
    if (!appInfoClass) return {};

    jfieldID nativeLibraryDirField = env->GetFieldID(
            appInfoClass,
            "nativeLibraryDir",
            "Ljava/lang/String;");
    if (!nativeLibraryDirField) return {};

    auto dirString = static_cast<jstring>(
            env->GetObjectField(appInfo, nativeLibraryDirField));
    if (!dirString) return {};

    const char *chars = env->GetStringUTFChars(dirString, nullptr);
    if (!chars) return {};

    std::string result(chars);
    env->ReleaseStringUTFChars(dirString, chars);
    return result;
}

static bool verifyNativeDirectory(const std::string &dirPath) {
    if (dirPath.empty()) return false;

    DIR *dir = opendir(dirPath.c_str());
    if (!dir) return false;

    bool foundOwner = false;
    bool foundCore = false;
    bool ok = true;

    while (dirent *entry = readdir(dir)) {
        if (!entry || !entry->d_name) continue;

        std::string name(entry->d_name);
        if (name == "." || name == "..") continue;
        if (!endsWith(name, std::string(oxorany(".so")))) continue;

        if (!isAllowedPackagedLibName(name)) {
            ok = false;
            break;
        }

        if (name == std::string(oxorany("libKeshavOwner.so"))) foundOwner = true;
        if (name == std::string(oxorany("libKESHAVXOWNERCore.so"))) foundCore = true;
    }

    closedir(dir);
    return ok && foundOwner && foundCore;
}

static std::string lowerCopy(std::string value) {
    std::transform(
            value.begin(),
            value.end(),
            value.begin(),
            [](unsigned char c) {
                return static_cast<char>(::tolower(c));
            });
    return value;
}

static std::string mappedPathFromLine(const std::string &line) {
    const auto slash = line.find('/');
    if (slash == std::string::npos) return {};

    std::string path = line.substr(slash);

    const auto deleted = path.find(std::string(oxorany(" (deleted)")));
    if (deleted != std::string::npos) {
        path = path.substr(0, deleted);
    }

    return path;
}

static bool containsSuspiciousRuntimeMarker(const std::string &lower) {
    return lower.find(std::string(oxorany("frida"))) != std::string::npos
        || lower.find(std::string(oxorany("gadget"))) != std::string::npos
        || lower.find(std::string(oxorany("substrate"))) != std::string::npos
        || lower.find(std::string(oxorany("xposed"))) != std::string::npos
        || lower.find(std::string(oxorany("lsposed"))) != std::string::npos;
}

static bool verifyProcessMaps(
        const std::string &nativeDir,
        const std::string &filesDir) {

    if (nativeDir.empty() || filesDir.empty()) return false;

    const std::string trustedServerLoader =
            filesDir
            + std::string(oxorany("/loader/"))
            + std::string(oxorany("libbgmi.so"));

    std::ifstream maps(std::string(oxorany("/proc/self/maps")));
    if (!maps.is_open()) return false;

    std::string line;

    while (std::getline(maps, line)) {
        std::string lower = lowerCopy(line);

        if (containsSuspiciousRuntimeMarker(lower)) {
            return false;
        }

        if (lower.find(std::string(oxorany(".so"))) == std::string::npos) {
            continue;
        }

        std::string mappedPath = mappedPathFromLine(line);
        if (mappedPath.empty()) {
            continue;
        }

        // Native libs shipped in the APK are allowed only by exact name.
        if (mappedPath.find(nativeDir + "/") == 0) {
            if (!isAllowedPackagedLibName(baseName(mappedPath))) {
                return false;
            }
            continue;
        }

        // The only runtime-downloaded native library allowed in this process.
        if (mappedPath == trustedServerLoader) {
            continue;
        }

        // Any other shared object mapped from our private files/data area is rejected.
        if (mappedPath.find(filesDir + "/") == 0) {
            return false;
        }

        // Reject typical external/temp injection locations.
        const std::string lowerPath = lowerCopy(mappedPath);
        if (lowerPath.find(std::string(oxorany("/data/local/tmp/"))) == 0
            || lowerPath.find(std::string(oxorany("/dev/shm/"))) == 0
            || lowerPath.find(std::string(oxorany("/sdcard/"))) == 0
            || lowerPath.find(std::string(oxorany("/storage/emulated/"))) == 0) {
            return false;
        }
    }

    return true;
}

} // namespace

namespace keshav_integrity {

bool verify_server_loader(
        JNIEnv *env,
        jobject context,
        const char *expected_sha256,
        jlong expected_size) {

    if (!env || !context || !expected_sha256 || expected_size <= 0) {
        return false;
    }

    const std::string filesDir = getJavaFilePath(
            env,
            context,
            oxorany("getFilesDir"));

    if (filesDir.empty()) return false;

    const std::string loaderPath =
            filesDir
            + std::string(oxorany("/loader/"))
            + std::string(oxorany("libbgmi.so"));

    std::ifstream file(loaderPath, std::ios::binary | std::ios::ate);
    if (!file.is_open()) return false;

    const std::streamoff fileSize = file.tellg();
    if (fileSize <= 0 || static_cast<jlong>(fileSize) != expected_size) {
        return false;
    }

    file.seekg(0, std::ios::beg);

    SHA256_CTX sha;
    SHA256_Init(&sha);

    char buffer[8192];
    while (file.good()) {
        file.read(buffer, sizeof(buffer));
        const std::streamsize count = file.gcount();
        if (count > 0) {
            SHA256_Update(
                    &sha,
                    reinterpret_cast<const unsigned char *>(buffer),
                    static_cast<size_t>(count));
        }
    }

    if (!file.eof() && file.fail()) {
        return false;
    }

    unsigned char digest[SHA256_DIGEST_LENGTH];
    SHA256_Final(digest, &sha);

    char actual[SHA256_DIGEST_LENGTH * 2 + 1];
    for (int i = 0; i < SHA256_DIGEST_LENGTH; ++i) {
        snprintf(&actual[i * 2], 3, "%02x", digest[i]);
    }
    actual[SHA256_DIGEST_LENGTH * 2] = '\0';

    return strcasecmp(actual, expected_sha256) == 0;
}

bool run(JNIEnv *env, jobject context) {
    if (env == nullptr || context == nullptr) {
        return false;
    }

    const std::string nativeDir = getNativeLibraryDir(env, context);
    const std::string filesDir = getJavaFilePath(
            env,
            context,
            oxorany("getFilesDir"));

    if (!verifyNativeDirectory(nativeDir)) {
        return false;
    }

    if (!verifyProcessMaps(nativeDir, filesDir)) {
        return false;
    }

    /*
     * ============================================================
     * KESHAV CUSTOM INTEGRITY ZONE
     * ============================================================
     * Put your private integrity code below.
     *
     * Built-in checks above already enforce:
     * - exact packaged native library allowlist
     * - exact server-loader path exception
     * - common runtime instrumentation markers
     * - rejection of other private/external/temp mapped .so files
     *
     * return true  -> integrity accepted
     * return false -> stylish integrity dialog + safe shutdown
     * ============================================================
     */

    return true;
}

} // namespace keshav_integrity
