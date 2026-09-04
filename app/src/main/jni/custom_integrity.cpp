#include "custom_integrity.h"
#include <android/log.h>
#include <dirent.h>
#include <fstream>
#include <string>
#include <algorithm>

#define KESHAV_INTEGRITY_TAG "KeshavIntegrity"

namespace {

static bool isAllowedLibName(const std::string &name) {
    return name == "libKeshavOwner.so" || name == "libKESHAVXOWNERCore.so";
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
    if (!appInfo || env->ExceptionCheck()) {
        env->ExceptionClear();
        return {};
    }

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
        if (name.size() < 3 || name.rfind(".so") != name.size() - 3) continue;

        if (!isAllowedLibName(name)) {
            ok = false;
            break;
        }

        if (name == "libKeshavOwner.so") foundOwner = true;
        if (name == "libKESHAVXOWNERCore.so") foundCore = true;
    }

    closedir(dir);
    return ok && foundOwner && foundCore;
}

static std::string lowerCopy(std::string value) {
    std::transform(value.begin(), value.end(), value.begin(),
                   [](unsigned char c) { return static_cast<char>(::tolower(c)); });
    return value;
}

static bool verifyProcessMaps(const std::string &nativeDir) {
    std::ifstream maps("/proc/self/maps");
    if (!maps.is_open()) return false;

    std::string line;
    while (std::getline(maps, line)) {
        std::string lower = lowerCopy(line);

        // Common dynamic instrumentation / hook frameworks.
        if (lower.find("frida") != std::string::npos ||
            lower.find("gadget") != std::string::npos ||
            lower.find("substrate") != std::string::npos ||
            lower.find("xposed") != std::string::npos ||
            lower.find("lsposed") != std::string::npos) {
            return false;
        }

        // Reject an unexpected shared library loaded from this app's native directory.
        if (!nativeDir.empty() &&
            line.find(nativeDir) != std::string::npos &&
            lower.find(".so") != std::string::npos) {

            const auto slash = line.find_last_of('/');
            if (slash != std::string::npos) {
                std::string base = line.substr(slash + 1);
                const auto space = base.find(' ');
                if (space != std::string::npos) base = base.substr(0, space);
                const auto deleted = base.find(" (deleted)");
                if (deleted != std::string::npos) base = base.substr(0, deleted);

                if (!isAllowedLibName(base)) {
                    return false;
                }
            }
        }

        // App-private injected library from common temporary locations.
        if ((lower.find("/data/local/tmp/") != std::string::npos ||
             lower.find("/dev/shm/") != std::string::npos) &&
            lower.find(".so") != std::string::npos) {
            return false;
        }
    }

    return true;
}

} // namespace

namespace keshav_integrity {

bool run(JNIEnv *env, jobject context) {
    if (env == nullptr || context == nullptr) {
        return false;
    }

    const std::string nativeDir = getNativeLibraryDir(env, context);
    if (!verifyNativeDirectory(nativeDir)) {
        return false;
    }

    if (!verifyProcessMaps(nativeDir)) {
        return false;
    }

    /*
     * ============================================================
     * KESHAV CUSTOM INTEGRITY ZONE
     * ============================================================
     * Add your own app-integrity checks below.
     *
     * Contract:
     *   return true  -> integrity accepted
     *   return false -> loader closes before showing login
     *
     * Built-in signature/repack/native-injection checks run above.
     * Keep your private checks self-contained in this function.
     * ============================================================
     */

    return true;
}

} // namespace keshav_integrity
