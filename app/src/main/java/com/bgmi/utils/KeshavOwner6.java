package com.bgmi.utils;

import android.app.Activity;
import android.app.Service;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.res.Configuration;
import android.content.res.Resources;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import org.lsposed.lsparanoid.Obfuscate;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.util.Locale;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

@Obfuscate
public class KeshavOwner6 {
    private static final String KEY_ALIAS = "KeshavOwnerStoreV1";
    private static final String PREFIX = "K1:";

    public Context context;
    SharedPreferences sp;

    public KeshavOwner6(Context context) {
        this.context = context.getApplicationContext();
        sp = this.context.getSharedPreferences("settings", Context.MODE_PRIVATE);
    }

    private SecretKey getOrCreateKey() throws Exception {
        KeyStore ks = KeyStore.getInstance("AndroidKeyStore");
        ks.load(null);
        if (ks.containsAlias(KEY_ALIAS)) {
            return ((KeyStore.SecretKeyEntry) ks.getEntry(KEY_ALIAS, null)).getSecretKey();
        }

        KeyGenerator generator = KeyGenerator.getInstance(
                KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore");
        generator.init(new KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .build());
        return generator.generateKey();
    }

    private String protect(String plain) {
        if (plain == null) return null;
        try {
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
            byte[] iv = cipher.getIV();
            byte[] encrypted = cipher.doFinal(plain.getBytes(StandardCharsets.UTF_8));
            return PREFIX
                    + Base64.encodeToString(iv, Base64.NO_WRAP)
                    + ":"
                    + Base64.encodeToString(encrypted, Base64.NO_WRAP);
        } catch (Exception ignored) {
            return null;
        }
    }

    private String reveal(String value, String fallback) {
        if (value == null) return fallback;
        if (!value.startsWith(PREFIX)) {
            // Backward compatibility for a pre-hardening plaintext preference.
            return value;
        }
        try {
            String[] parts = value.split(":", 3);
            if (parts.length != 3) return fallback;

            byte[] iv = Base64.decode(parts[1], Base64.NO_WRAP);
            byte[] encrypted = Base64.decode(parts[2], Base64.NO_WRAP);

            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), new GCMParameterSpec(128, iv));
            return new String(cipher.doFinal(encrypted), StandardCharsets.UTF_8);
        } catch (Exception ignored) {
            return fallback;
        }
    }

    public String getSt(String map, String ori) {
        return reveal(sp.getString(map, null), ori);
    }

    public void setSt(String map, String write) {
        String encrypted = protect(write);
        if (encrypted != null) {
            sp.edit().putString(map, encrypted).apply();
        }
    }

    public boolean getBool(String map, boolean ori) {
        return sp.getBoolean(map, ori);
    }

    public void setBool(String map, boolean write) {
        sp.edit().putBoolean(map, write).apply();
    }

    public int getInt(String map, int ori) {
        return sp.getInt(map, ori);
    }

    public void setInt(String map, int write) {
        sp.edit().putInt(map, write).apply();
    }

    public void setBool(String file, String map, boolean write) {
        context.getSharedPreferences(file, Context.MODE_PRIVATE)
                .edit().putBoolean(map, write).apply();
    }

    public void setSt(String file, String map, String write) {
        String encrypted = protect(write);
        if (encrypted != null) {
            context.getSharedPreferences(file, Context.MODE_PRIVATE)
                    .edit().putString(map, encrypted).apply();
        }
    }

    public String getSt(String file, String map, String ori) {
        SharedPreferences p = context.getSharedPreferences(file, Context.MODE_PRIVATE);
        return reveal(p.getString(map, null), ori);
    }

    public void setInt(String file, String map, int write) {
        context.getSharedPreferences(file, Context.MODE_PRIVATE)
                .edit().putInt(map, write).apply();
    }

    public int getInt(String file, String map, int ori) {
        return context.getSharedPreferences(file, Context.MODE_PRIVATE).getInt(map, ori);
    }

    public void setLocale(Activity act, String cd) {
        Locale loc = new Locale(cd);
        Locale.setDefault(loc);
        Resources ress = act.getResources();
        Configuration cfg = ress.getConfiguration();
        cfg.setLocale(loc);
        ress.updateConfiguration(cfg, ress.getDisplayMetrics());
    }

    public void setLocale(Service act, String cd) {
        Locale loc = new Locale(cd);
        Locale.setDefault(loc);
        Resources ress = act.getResources();
        Configuration cfg = ress.getConfiguration();
        cfg.setLocale(loc);
        ress.updateConfiguration(cfg, ress.getDisplayMetrics());
    }
}
