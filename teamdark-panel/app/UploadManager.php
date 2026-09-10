<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class UploadManager
{
    public const USER_FILE_LIMIT = 2;
    public const MAX_FILE_BYTES = 52428800; // 50 MiB application limit.
    private const ALLOWED_EXTENSIONS = ['so', 'zip'];

    public static function ensureSchema(): void
    {
        $pdo = Database::pdo();

        // Do not run CREATE TABLE on every /files request. Shared-hosting DB users
        // often have normal DML access but no CREATE privilege after deployment.
        try {
            $pdo->query('SELECT 1 FROM user_uploads LIMIT 1');
            return;
        } catch (PDOException $probe) {
            $message = strtolower($probe->getMessage());
            $missingTable = $probe->getCode() === '42S02'
                || str_contains($message, "doesn't exist")
                || str_contains($message, 'does not exist')
                || str_contains($message, 'base table or view not found');

            if (!$missingTable) {
                throw $probe;
            }
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS user_uploads (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    original_name VARCHAR(255) NOT NULL,
                    storage_name VARCHAR(96) NOT NULL UNIQUE,
                    extension VARCHAR(8) NOT NULL,
                    mime_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
                    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    sha256 CHAR(64) NOT NULL,
                    version INT UNSIGNED NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_upload_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_upload_user(user_id),
                    INDEX idx_upload_updated(updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (PDOException $e) {
            error_log(
                'TeamDark Binary Vault schema initialization failed: SQLSTATE '
                .$e->getCode()
            );
            throw new RuntimeException(
                'Binary Vault database is not initialized. Import the latest database/schema.sql once or allow CREATE TABLE for the panel database user.',
                0,
                $e
            );
        }
    }

    public static function listOwn(array $actor): array
    {
        self::ensureSchema();
        $q = Database::pdo()->prepare(
            'SELECT f.*,u.username,u.name,u.role FROM user_uploads f JOIN users u ON u.id=f.user_id WHERE f.user_id=? ORDER BY f.updated_at DESC,f.id DESC'
        );
        $q->execute([(int)$actor['id']]);
        return $q->fetchAll() ?: [];
    }

    public static function listAll(array $actor): array
    {
        if (($actor['role'] ?? '') !== 'owner') {
            throw new RuntimeException('Owner access required.');
        }

        self::ensureSchema();
        return Database::pdo()->query(
            'SELECT f.*,u.username,u.name,u.role FROM user_uploads f JOIN users u ON u.id=f.user_id ORDER BY f.updated_at DESC,f.id DESC LIMIT 5000'
        )->fetchAll() ?: [];
    }

    public static function upload(array $actor, array $file): array
    {
        self::ensureSchema();
        $meta = self::validateUpload($file);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $stored = null;

        try {
            // Lock the account row so two simultaneous uploads cannot bypass the two-file limit.
            $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([(int)$actor['id']]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Account unavailable.');
            }

            if (($actor['role'] ?? '') !== 'owner') {
                $countQ = $pdo->prepare('SELECT COUNT(*) FROM user_uploads WHERE user_id=?');
                $countQ->execute([(int)$actor['id']]);
                if ((int)$countQ->fetchColumn() >= self::USER_FILE_LIMIT) {
                    throw new RuntimeException('Your private vault already contains the maximum 2 files. Replace or delete an existing file first.');
                }
            }

            $stored = self::moveIntoStorage((int)$actor['id'], $meta['tmp_name']);
            $q = $pdo->prepare(
                'INSERT INTO user_uploads(user_id,original_name,storage_name,extension,mime_type,size_bytes,sha256,version) VALUES(?,?,?,?,?,?,?,1)'
            );
            $q->execute([
                (int)$actor['id'],
                $meta['name'],
                $stored,
                $meta['extension'],
                $meta['mime'],
                $meta['size'],
                $meta['sha256'],
            ]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();

            Security::audit((int)$actor['id'], 'private_file_uploaded', [
                'file_id'=>$id,
                'name'=>$meta['name'],
                'extension'=>$meta['extension'],
                'size'=>$meta['size'],
            ]);
            self::purgeCdn($id);

            return ['id'=>$id] + $meta;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($stored !== null) self::unlinkStored((int)$actor['id'], $stored);
            throw $e;
        }
    }

    public static function replace(array $actor, int $fileId, array $file): array
    {
        self::ensureSchema();
        $meta = self::validateUpload($file);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $newStored = null;

        try {
            $row = self::rowForActor($pdo, $actor, $fileId, true);
            if (!$row) throw new RuntimeException('File not found or not allowed.');

            $newStored = self::moveIntoStorage((int)$row['user_id'], $meta['tmp_name']);
            $q = $pdo->prepare(
                'UPDATE user_uploads SET original_name=?,storage_name=?,extension=?,mime_type=?,size_bytes=?,sha256=?,version=version+1 WHERE id=?'
            );
            $q->execute([
                $meta['name'],
                $newStored,
                $meta['extension'],
                $meta['mime'],
                $meta['size'],
                $meta['sha256'],
                $fileId,
            ]);
            $pdo->commit();

            self::unlinkStored((int)$row['user_id'], (string)$row['storage_name']);
            Security::audit((int)$actor['id'], 'private_file_replaced', [
                'file_id'=>$fileId,
                'owner_id'=>(int)$row['user_id'],
                'name'=>$meta['name'],
                'size'=>$meta['size'],
            ]);
            self::purgeCdn($fileId);

            return ['id'=>$fileId] + $meta;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newStored !== null) {
                $ownerId = isset($row['user_id']) ? (int)$row['user_id'] : (int)$actor['id'];
                self::unlinkStored($ownerId, $newStored);
            }
            throw $e;
        }
    }

    public static function delete(array $actor, int $fileId): void
    {
        self::ensureSchema();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = self::rowForActor($pdo, $actor, $fileId, true);
            if (!$row) throw new RuntimeException('File not found or not allowed.');
            $pdo->prepare('DELETE FROM user_uploads WHERE id=?')->execute([$fileId]);
            $pdo->commit();

            self::unlinkStored((int)$row['user_id'], (string)$row['storage_name']);
            Security::audit((int)$actor['id'], 'private_file_deleted', [
                'file_id'=>$fileId,
                'owner_id'=>(int)$row['user_id'],
                'name'=>$row['original_name'],
            ]);
            self::purgeCdn($fileId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function download(array $actor, int $fileId): never
    {
        self::ensureSchema();
        $row = self::rowForActor(Database::pdo(), $actor, $fileId, false);
        if (!$row) {
            http_response_code(404);
            exit('File not found.');
        }

        $path = self::storedPath((int)$row['user_id'], (string)$row['storage_name']);
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            exit('Stored file is unavailable.');
        }

        $name = (string)$row['original_name'];
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'download.'.(string)$row['extension'];
        $type = $row['extension'] === 'zip' ? 'application/zip' : 'application/octet-stream';

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: '.$type);
        header('X-Content-Type-Options: nosniff');
        // Downloads remain private/authenticated. A Cloudflare Cache Everything rule
        // must not bypass panel authorization. CDN integration only purges stale URLs.
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Length: '.(string)filesize($path));
        header('Content-Disposition: attachment; filename="'.addcslashes($fallback, "\\\"").'"; filename*=UTF-8\'\''.rawurlencode($name));
        readfile($path);
        exit;
    }

    private static function rowForActor(PDO $pdo, array $actor, int $fileId, bool $forUpdate): ?array
    {
        if ($fileId <= 0) return null;
        $sql = 'SELECT f.*,u.username,u.name,u.role owner_role FROM user_uploads f JOIN users u ON u.id=f.user_id WHERE f.id=?';
        $params = [$fileId];
        if (($actor['role'] ?? '') !== 'owner') {
            $sql .= ' AND f.user_id=?';
            $params[] = (int)$actor['id'];
        }
        $sql .= ' LIMIT 1'.($forUpdate ? ' FOR UPDATE' : '');
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->fetch() ?: null;
    }

    private static function validateUpload(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server upload size limit. Server PHP must allow at least 50 MB uploads.',
                UPLOAD_ERR_PARTIAL => 'File upload was interrupted.',
                UPLOAD_ERR_NO_FILE => 'Choose a .so or .zip file.',
                default => 'File upload failed.',
            };
            throw new RuntimeException($message);
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload source.');
        }

        $name = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || strlen($name) > 240) {
            throw new RuntimeException('Invalid file name.');
        }

        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Only .so and .zip files are allowed.');
        }

        $size = filesize($tmp);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('The uploaded file is empty.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('File is larger than the 50 MB application limit.');
        }

        $sha = hash_file('sha256', $tmp);
        if (!is_string($sha) || strlen($sha) !== 64) {
            throw new RuntimeException('Could not verify uploaded file.');
        }

        $mime = 'application/octet-stream';
        if (class_exists('finfo')) {
            try {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected = $finfo->file($tmp);
                if (is_string($detected) && $detected !== '') $mime = substr($detected, 0, 120);
            } catch (Throwable) {
            }
        }

        return [
            'name'=>$name,
            'extension'=>$extension,
            'size'=>(int)$size,
            'sha256'=>$sha,
            'mime'=>$mime,
            'tmp_name'=>$tmp,
        ];
    }

    private static function moveIntoStorage(int $userId, string $tmp): string
    {
        $dir = self::userDirectory($userId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create private storage directory.');
        }

        for ($i = 0; $i < 8; $i++) {
            $storage = bin2hex(random_bytes(24)).'.blob';
            $path = $dir.DIRECTORY_SEPARATOR.$storage;
            if (file_exists($path)) continue;
            if (!move_uploaded_file($tmp, $path)) {
                throw new RuntimeException('Could not move uploaded file into private storage.');
            }
            @chmod($path, 0640);
            return $storage;
        }

        throw new RuntimeException('Could not allocate private storage name.');
    }

    private static function storageRoot(): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'private_uploads';
    }

    private static function userDirectory(int $userId): string
    {
        return self::storageRoot().DIRECTORY_SEPARATOR.'u'.$userId;
    }

    private static function storedPath(int $userId, string $storageName): string
    {
        if (!preg_match('/^[a-f0-9]{48}\.blob$/', $storageName)) {
            throw new RuntimeException('Invalid stored file reference.');
        }
        return self::userDirectory($userId).DIRECTORY_SEPARATOR.$storageName;
    }

    private static function unlinkStored(int $userId, string $storageName): void
    {
        try {
            $path = self::storedPath($userId, $storageName);
            if (is_file($path)) @unlink($path);
        } catch (Throwable) {
        }
    }

    private static function purgeCdn(int $fileId): void
    {
        try {
            if (class_exists(CdnCache::class)) {
                CdnCache::purgeVaultFile($fileId);
            }
        } catch (Throwable $e) {
            // CDN purge must never roll back or block a successful file operation.
            error_log('TeamDark CDN purge hook failed for vault file #'.$fileId.'.');
        }
    }
}
