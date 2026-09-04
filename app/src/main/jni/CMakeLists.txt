cmake_minimum_required(VERSION 3.10.2)

project("akshit")

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

set(CMAKE_C_FLAGS "${CMAKE_C_FLAGS} -Wno-error=format-security -fvisibility=hidden -ffunction-sections -fdata-sections -Os -w -fstack-protector-strong -D_FORTIFY_SOURCE=2 -fno-ident")
set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} -Wno-error=format-security -fvisibility=hidden -fvisibility-inlines-hidden -ffunction-sections -fdata-sections -Os -w -fpermissive -fstack-protector-strong -D_FORTIFY_SOURCE=2 -fno-ident")

include_directories(
    ${CMAKE_CURRENT_SOURCE_DIR}
    ${CMAKE_CURRENT_SOURCE_DIR}/includes
    ${CMAKE_CURRENT_SOURCE_DIR}/includes/curl/curl-android-${ANDROID_ABI}/include
    ${CMAKE_CURRENT_SOURCE_DIR}/includes/curl/openssl-android-${ANDROID_ABI}/include
)

add_library(libcurl STATIC IMPORTED)
set_target_properties(libcurl PROPERTIES IMPORTED_LOCATION
    ${CMAKE_CURRENT_SOURCE_DIR}/includes/curl/curl-android-${ANDROID_ABI}/lib/libcurl.a)

add_library(libssl STATIC IMPORTED)
set_target_properties(libssl PROPERTIES IMPORTED_LOCATION
    ${CMAKE_CURRENT_SOURCE_DIR}/includes/curl/openssl-android-${ANDROID_ABI}/lib/libssl.a)

add_library(libcrypto STATIC IMPORTED)
set_target_properties(libcrypto PROPERTIES IMPORTED_LOCATION
    ${CMAKE_CURRENT_SOURCE_DIR}/includes/curl/openssl-android-${ANDROID_ABI}/lib/libcrypto.a)

add_library(akshit SHARED
    main.cpp
    oxorany.cpp
)

target_link_libraries(akshit
    libcurl
    libssl
    libcrypto
    log
    android
    z
)

# Unused code strip karne ke liye linker flag (APK size kam)
target_link_options(akshit PRIVATE
    -Wl,--gc-sections
    -Wl,--strip-all
    -Wl,--exclude-libs,ALL
    -Wl,-z,relro
    -Wl,-z,now
    -Wl,--build-id=none
)

