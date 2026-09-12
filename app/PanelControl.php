<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class PanelControl
{
    public const DEFAULTS = [
        'panel_online'=>true,
        'registration_open'=>true,
        'generation_open'=>true,
        'message'=>'The panel is temporarily under maintenance. Please try again later.',
        'announcement'=>'',
        'announcement_published_at'=>'',
        // Splash has been permanently retired. Keep compatibility keys so any
        // legacy settings page/database reads remain harmless, but it can never render.
        'splash_enabled'=>false,
        'splash_title'=>'TEAM DARK',
        'splash_subtitle'=>'Secure control plane',
        'splash_duration_ms'=>0,
        'splash_version'=>1,
        'default_max_devices'=>10,
        'force_one_device_new_keys'=>false,
        'generated_key_prefix'=>'Team-Dark-',
        'generated_key_length'=>16,
        'key_cost_per_day'=>-1,
        'blocked_ip_rules'=>[],
        'brand_name'=>'',
        'brand_subtitle'=>'Secure control plane',
        'brand_footer'=>'TeamDark secure control plane',
        'brand_mark'=>'TD',
        'package_enabled'=>false,
        'package_name'=>'',
        'package_version'=>'',
        'package_notes'=>'',
        'package_file_id'=>0,
        'panel_release_label'=>'',
    ];

    private static function disableSplash(array $settings): array
    {
        // Hard fail-open: old database values or stale forms can never reactivate it.
        $settings['splash_enabled'] = false;
        $settings['splash_duration_ms'] = 0;
        return $settings;
    }

    public static function settings(): array
    {
        try {
            $row = Database::pdo()->query('SELECT settings_json,revision FROM panel_settings WHERE id=1')->fetch();
        } catch (\PDOException $e) {
            // Existing installations stay operational until the single SQL upgrade is imported.
            if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
            return self::disableSplash(self::DEFAULTS + ['revision'=>0, 'installed'=>false]);
        }

        $settings = array_replace(self::DEFAULTS, $row ? (json_decode($row['settings_json'], true) ?: []) : [], [
            'revision'=>(int)($row['revision'] ?? 0), 'installed'=>true,
        ]);

        return self::disableSplash($settings);
    }

    public static function blocked(?array $actor): bool
    {
        return ($actor['role'] ?? '') !== 'owner' && !self::settings()['panel_online'];
    }

    public static function assertGeneration(array $actor, bool $guest = false): void
    {
        $settings = self::settings();
        if (($guest || ($actor['role'] ?? '') !== 'owner') &&
            (!$settings['panel_online'] || !$settings['generation_open'])) {
            throw new \RuntimeException('Key generation is paused by the owner.');
        }
    }

    public static function setAnnouncement(array $actor, string $message): void
    {
        if (($actor['role'] ?? '') !== 'owner') {
            throw new \RuntimeException('Owner access required.');
        }

        $message = trim($message);
        if (strlen($message) > 1000) {
            throw new \RuntimeException('Announcement must be 1000 bytes or fewer.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $q = $pdo->query(
                'SELECT settings_json FROM panel_settings WHERE id=1 FOR UPDATE'
            );
            $row = $q->fetch();
            $settings = self::disableSplash(array_replace(
                self::DEFAULTS,
                $row ? (json_decode((string)$row['settings_json'], true) ?: []) : []
            ));

            $settings['announcement'] = $message;
            $settings['announcement_published_at'] = $message === ''
                ? ''
                : date('Y-m-d H:i:s');

            $pdo->prepare(
                'UPDATE panel_settings
                 SET settings_json=?,revision=revision+1,updated_by=?
                 WHERE id=1'
            )->execute([
                json_encode($settings, JSON_THROW_ON_ERROR),
                (int)$actor['id'],
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function save(array $actor, array $input): void
    {
        if (($actor['role'] ?? '') !== 'owner') throw new \RuntimeException('Owner access required.');
        $current = self::settings();
        $settings = array_replace(self::DEFAULTS, $current);
        $settings['announcement'] = (string)($current['announcement'] ?? '');
        $settings['announcement_published_at'] = (string)($current['announcement_published_at'] ?? '');

        foreach (['panel_online','registration_open','generation_open'] as $key) {
            $settings[$key] = ($input[$key] ?? '') === '1';
        }

        // Ignore every legacy splash form field permanently.
        $settings = self::disableSplash($settings);
        $settings['splash_title'] = self::DEFAULTS['splash_title'];
        $settings['splash_subtitle'] = self::DEFAULTS['splash_subtitle'];
        $settings['splash_version'] = max(1, (int)($current['splash_version'] ?? 1));

        $message = trim((string)($input['message'] ?? ''));
        if (strlen($message) > 500) {
            throw new \RuntimeException('Maintenance message must be 500 bytes or fewer.');
        }
        $settings['message'] = $message === ''
            ? self::DEFAULTS['message']
            : $message;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('UPDATE panel_settings SET settings_json=?, revision=revision+1, updated_by=? WHERE id=1 AND revision=?');
            $q->execute([json_encode($settings, JSON_THROW_ON_ERROR), $actor['id'], (int)($input['revision'] ?? -1)]);
            if ($q->rowCount() !== 1) throw new \RuntimeException('Settings changed in another session. Reload and try again.');
            Security::audit((int)$actor['id'], 'panel_settings_changed', $settings);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
