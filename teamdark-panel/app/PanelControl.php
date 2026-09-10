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
        'splash_enabled'=>false,
        'splash_title'=>'TEAM DARK',
        'splash_subtitle'=>'Secure control plane',
        'splash_duration_ms'=>2400,
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

    public static function settings(): array
    {
        try {
            $row = Database::pdo()->query('SELECT settings_json,revision FROM panel_settings WHERE id=1')->fetch();
        } catch (\PDOException $e) {
            // Existing installations stay operational until the single SQL upgrade is imported.
            if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
            return self::DEFAULTS + ['revision'=>0, 'installed'=>false];
        }
        return array_replace(self::DEFAULTS, $row ? (json_decode($row['settings_json'], true) ?: []) : [], [
            'revision'=>(int)($row['revision'] ?? 0), 'installed'=>true,
        ]);
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
            $settings = array_replace(
                self::DEFAULTS,
                $row ? (json_decode((string)$row['settings_json'], true) ?: []) : []
            );

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

        if (($input['splash_present'] ?? '') === '1') {
            $splashEnabled = ($input['splash_enabled'] ?? '') === '1';
            $splashTitle = trim((string)($input['splash_title'] ?? self::DEFAULTS['splash_title']));
            $splashSubtitle = trim((string)($input['splash_subtitle'] ?? self::DEFAULTS['splash_subtitle']));
            $splashDuration = (int)($input['splash_duration_ms'] ?? self::DEFAULTS['splash_duration_ms']);

            if ($splashTitle === '' || strlen($splashTitle) > 60) {
                throw new \RuntimeException('Splash title must be 1–60 bytes.');
            }
            if (strlen($splashSubtitle) > 160) {
                throw new \RuntimeException('Splash subtitle must be 160 bytes or fewer.');
            }
            if (!in_array($splashDuration, [1400,2000,2400,3200,4200], true)) {
                throw new \RuntimeException('Invalid splash duration.');
            }

            $settings['splash_enabled'] = $splashEnabled;
            $settings['splash_title'] = $splashTitle;
            $settings['splash_subtitle'] = $splashSubtitle;
            $settings['splash_duration_ms'] = $splashDuration;

            $splashChanged =
                (bool)($current['splash_enabled'] ?? false) !== $splashEnabled
                || (string)($current['splash_title'] ?? '') !== $splashTitle
                || (string)($current['splash_subtitle'] ?? '') !== $splashSubtitle
                || (int)($current['splash_duration_ms'] ?? 0) !== $splashDuration;

            $settings['splash_version'] = $splashChanged
                ? max(1, (int)($current['splash_version'] ?? 1) + 1)
                : max(1, (int)($current['splash_version'] ?? 1));
        } else {
            foreach ([
                'splash_enabled',
                'splash_title',
                'splash_subtitle',
                'splash_duration_ms',
                'splash_version',
            ] as $key) {
                $settings[$key] = $current[$key] ?? self::DEFAULTS[$key];
            }
        }

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
