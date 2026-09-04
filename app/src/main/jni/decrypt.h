// decrypt.h (FINAL HARDENED)
#pragma once

#include <string>
#include <vector>
#include <cstring>
#include <openssl/evp.h>
#include <openssl/sha.h>
#include <openssl/hmac.h>

/* ================= BASE64 DECODE ================= */

static std::vector<unsigned char> base64_decode(const std::string& in) {
    static const char tbl[] =
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::vector<int> rev(256, -1);
    for (int i = 0; i < 64; ++i) rev[(unsigned char)tbl[i]] = i;

    std::vector<unsigned char> out;
    int val = 0, bits = 0;
    for (unsigned char c : in) {
        if (c == '=') break;
        int v = rev[c];
        if (v < 0) continue;
        val = (val << 6) | v;
        bits += 6;
        if (bits >= 8) {
            out.push_back((val >> (bits - 8)) & 0xFF);
            bits -= 8;
        }
    }
    return out;
}

/* ================= DECRYPT ================= */

static std::string DecryptServerResponse(const char* resp) {
    if (!resp || strlen(resp) == 0)
        return "{}";

    // 🚫 Hard reject plain JSON
    if (resp[0] == '{' || resp[0] == '[')
        return "{}";

    std::string input(resp);
    std::vector<unsigned char> raw = base64_decode(input);

    // Must be: IV(16) + CIPHER + HMAC(32)
    if (raw.size() <= 16 + 32)
        return "{}";

    const size_t HMAC_LEN = 32;

    // Split
    std::vector<unsigned char> payload(raw.begin(), raw.end() - HMAC_LEN);
    std::vector<unsigned char> recvHmac(raw.end() - HMAC_LEN, raw.end());

    /* ---------- KEYS ---------- */
    const char* BASE_SECRET = "123456";

    unsigned char aesKey[32];
    SHA256((const unsigned char*)BASE_SECRET, strlen(BASE_SECRET), aesKey);

    unsigned char hmacKey[32];
    std::string hmacSeed = std::string(BASE_SECRET) + "_HMAC";
    SHA256((const unsigned char*)hmacSeed.data(), hmacSeed.size(), hmacKey);

    /* ---------- VERIFY HMAC ---------- */
    unsigned int outLen = 0;
    unsigned char calcHmac[32];
    HMAC(EVP_sha256(),
         hmacKey, 32,
         payload.data(), payload.size(),
         calcHmac, &outLen);

    if (outLen != 32 || CRYPTO_memcmp(calcHmac, recvHmac.data(), 32) != 0) {
        // ❌ Tampered
        return "{}";
    }

    /* ---------- SPLIT IV + CIPHER ---------- */
    unsigned char iv[16];
    memcpy(iv, payload.data(), 16);
    std::vector<unsigned char> cipher(payload.begin() + 16, payload.end());

    /* ---------- AES DECRYPT ---------- */
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) return "{}";

    if (!EVP_DecryptInit_ex(ctx, EVP_aes_256_cbc(), nullptr, aesKey, iv)) {
        EVP_CIPHER_CTX_free(ctx);
        return "{}";
    }

    std::vector<unsigned char> plain(cipher.size() + 16);
    int len = 0, total = 0;

    if (!EVP_DecryptUpdate(ctx, plain.data(), &len, cipher.data(), (int)cipher.size())) {
        EVP_CIPHER_CTX_free(ctx);
        return "{}";
    }
    total = len;

    if (!EVP_DecryptFinal_ex(ctx, plain.data() + len, &len)) {
        EVP_CIPHER_CTX_free(ctx);
        return "{}";
    }
    total += len;

    EVP_CIPHER_CTX_free(ctx);

    return std::string((char*)plain.data(), total);
}