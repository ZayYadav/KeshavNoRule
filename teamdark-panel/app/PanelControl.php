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

    public static function save(array $actor, array $input): void
    {
        if (($actor['role'] ?? '') !== 'owner') throw new \RuntimeException('Owner access required.');
        $settings = [];
        foreach (['panel_online','registration_open','generation_open'] as $key) {
            $settings[$key] = ($input[$key] ?? '') === '1';
        }
        foreach (['message','announcement'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if (strlen($value) > 500) throw new \RuntimeException('Messages must be 500 bytes or fewer.');
            $settings[$key] = $value;
        }
        if ($settings['message'] === '') $settings['message'] = self::DEFAULTS['message'];
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
