LOCAL_PATH:= $(call my-dir)

include $(CLEAR_VARS)

LOCAL_MODULE := KeshavOwner

LOCAL_CFLAGS := -Wno-error=format-security -fvisibility=hidden -ffunction-sections -fdata-sections -w -fstack-protector-strong -D_FORTIFY_SOURCE=2 -fno-ident
LOCAL_CFLAGS += -fno-rtti -fno-exceptions -fpermissive
LOCAL_CPPFLAGS += -Wno-error=format-security -fvisibility=hidden -fvisibility-inlines-hidden -ffunction-sections -fdata-sections -w -Werror -s -std=c++17 -fstack-protector-strong -D_FORTIFY_SOURCE=2 -fno-ident
LOCAL_CPPFLAGS += -Wno-error=c++11-narrowing -fms-extensions -fno-rtti -fno-exceptions -fpermissive
LOCAL_LDFLAGS += -Wl,--gc-sections -Wl,--strip-all -Wl,--exclude-libs,ALL -Wl,-z,relro -Wl,-z,now -Wl,--build-id=none -llog
LOCAL_ARM_MODE := arm

LOCAL_C_INCLUDES := $(LOCAL_PATH)/
LOCAL_C_INCLUDES += $(LOCAL_PATH)/includes
LOCAL_C_INCLUDES += $(LOCAL_PATH)/includes/curl/curl-android-$(TARGET_ARCH_ABI)/include
LOCAL_C_INCLUDES += $(LOCAL_PATH)/includes/curl/openssl-android-$(TARGET_ARCH_ABI)/include
	
CLIENT_SRC_FILES := $(wildcard $(LOCAL_PATH)/*.cpp)

LOCAL_SRC_FILES := $(CLIENT_SRC_FILES:$(LOCAL_PATH)/%=%)

LOCAL_CPP_FEATURES := exceptions
LOCAL_LDLIBS    := -llog -landroid -lz

LOCAL_STATIC_LIBRARIES := libcurl libssl libcrypto

include $(BUILD_SHARED_LIBRARY)

include $(CLEAR_VARS)
LOCAL_MODULE := libcurl
LOCAL_SRC_FILES := includes/curl/curl-android-$(TARGET_ARCH_ABI)/lib/libcurl.a
include $(PREBUILT_STATIC_LIBRARY)

include $(CLEAR_VARS)
LOCAL_MODULE := libssl
LOCAL_SRC_FILES := includes/curl/openssl-android-$(TARGET_ARCH_ABI)/lib/libssl.a
include $(PREBUILT_STATIC_LIBRARY)

include $(CLEAR_VARS)
LOCAL_MODULE := libcrypto
LOCAL_SRC_FILES := includes/curl/openssl-android-$(TARGET_ARCH_ABI)/lib/libcrypto.a
include $(PREBUILT_STATIC_LIBRARY)
