<?php
declare(strict_types=1);

namespace TeamDark\Panel;

use PDO;
use RuntimeException;
use Throwable;

final class ChunkUploadManager
{
    public const CHUNK_BYTES = 262144; // 256 KiB: safely below common 1-2 MB host limits.
    public const MAX_FILE_BYTES = 52428800; // 50 MiB, matches UploadManager.
    private const MAX_CHUNKS = 220;
    private const MAX_ACTIVE_UPLOADS = 4;
    private const SESSION_TTL_SECONDS = 7200;
    private const ALLOWED_EXTENSIONS = ['so', 'zip'];

    public static function receive(array $actor, array $chunk, array $input): array
    {
        UploadManager::ensureSchema();
        $meta = self::requestMeta($actor, $input);
        self::cleanupStale((int)$actor['id']);

        $error = (int)($chunk['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'A chunk exceeded the server request limit. Please retry; chunked upload uses 256 KB pieces.',
                UPLOAD_ERR_PARTIAL => 'Upload chunk was interrupted. Please retry.',
                UPLOAD_ERR_NO_FILE => 'Upload chunk is missing.',
                default => 'Upload chunk failed.',
            };
            throw new RuntimeException($message);
        }

        $tmp = (string)($chunk['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload chunk source.');
        }

        $chunkIndex = self::parseInt($input['chunk_index'] ?? null, 0, $meta['total_chunks'] - 1, 'Invalid chunk index.');
        $size = filesize($tmp);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Upload chunk is empty.');
        }

        $expected = self::expectedChunkBytes($meta['total_size'], $meta['total_chunks'], $chunkIndex);
        if ($size !== $expected || $size > self::CHUNK_BYTES) {
            throw new RuntimeException('Upload chunk size mismatch. Please retry the file upload.');
        }

        $dir = self::sessionDir((int)$actor['id'], $meta['upload_id']);
        self::ensureSessionDir($actor, $dir, $meta);
        self::assertStoredMeta($dir, $meta);

        $target = $dir.DIRECTORY_SEPARATOR.sprintf('%04d.part', $chunkIndex);
        $incoming = $target.'.incoming-'.bin2hex(random_bytes(4));
        if (!move_uploaded_file($tmp, $incoming)) {
            throw new RuntimeException('Could not store upload chunk.');
        }
        @chmod($incoming, 0640);

        $actual = filesize($incoming);
        if ($actual !== $expected) {
            @unlink($incoming);
            throw new RuntimeException('Stored upload chunk size mismatch.');
        }

        if (!@rename($incoming, $target)) {
            @unlink($incoming);
            throw new RuntimeException('Could not finalize upload chunk.');
        }

        @touch($dir);
        return [
            'ok'=>true,
            'upload_id'=>$meta['upload_id'],
            'chunk_index'=>$chunkIndex,
            'received'=>self::countChunks($dir, $meta['total_chunks']),
            'total_chunks'=>$meta['total_chunks'],
        ];
    }

    public static function finish(array $actor, array $input): array
    {
        UploadManager::ensureSchema();
        $uploadId = strtolower(trim((string)($input['upload_id'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new RuntimeException('Invalid upload session.');
        }

        $dir = self::sessionDir((int)$actor['id'], $uploadId);
        $meta = self::loadMeta($dir);
        if ((int)($meta['user_id'] ?? 0) !== (int)$actor['id'] || ($meta['upload_id'] ?? '') !== $uploadId) {
            throw new RuntimeException('Upload session is unavailable.');
        }

        $totalChunks = (int)$meta['total_chunks'];
        $totalSize = (int)$meta['total_size'];
        if ($totalChunks < 1 || $totalChunks > self::MAX_CHUNKS || $totalSize < 1 || $totalSize > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Upload session metadata is invalid.');
        }

        if (self::countChunks($dir, $totalChunks) !== $totalChunks) {
            throw new RuntimeException('Upload is incomplete. Please retry.');
        }

        $assembled = $dir.DIRECTORY_SEPARATOR.'assembled.tmp';
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not assemble uploaded file.');
        }

        $hash = hash_init('sha256');
        $written = 0;
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = $dir.DIRECTORY_SEPARATOR.sprintf('%04d.part', $i);
                $expected = self::expectedChunkBytes($totalSize, $totalChunks, $i);
                $partSize = filesize($part);
                if ($partSize === false || $partSize !== $expected) {
                    throw new RuntimeException('One upload chunk is missing or damaged. Please retry.');
                }

                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new RuntimeException('Could not read upload chunk.');
                }
                while (!feof($in)) {
                    $data = fread($in, 1048576);
                    if ($data === false) {
                        fclose($in);
                        throw new RuntimeException('Could not read upload chunk.');
                    }
                    if ($data === '') break;
                    $bytes = strlen($data);
                    if (fwrite($out, $data) !== $bytes) {
                        fclose($in);
                        throw new RuntimeException('Could not assemble uploaded file.');
                    }
                    hash_update($hash, $data);
                    $written += $bytes;
                    if ($written > self::MAX_FILE_BYTES) {
                        fclose($in);
                        throw new RuntimeException('File is larger than the 50 MB application limit.');
                    }
                }
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        if ($written !== $totalSize || filesize($assembled) !== $totalSize) {
            @unlink($assembled);
            throw new RuntimeException('Assembled upload size mismatch. Please retry.');
        }

        @chmod($assembled, 0640);
        $sha = hash_final($hash);
        $mime = self::detectMime($assembled);

        try {
            $result = self::commitFile($actor, $meta, $assembled, $sha, $mime);
            self::removeTree($dir);
            return $result;
        } catch (Throwable $e) {
            self::removeTree($dir);
            throw $e;
        }
    }

    private static function commitFile(array $actor, array $meta, string $assembled, string $sha, string $mime): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $storedPath = null;
        $oldRow = null;

        try {
            if ($meta['mode'] === 'upload') {
                $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
                $lock->execute([(int)$actor['id']]);
                if (!$lock->fetchColumn()) {
                    throw new RuntimeException('Account unavailable.');
                }

                if (($actor['role'] ?? '') !== 'owner') {
                    $countQ = $pdo->prepare('SELECT COUNT(*) FROM user_uploads WHERE user_id=?');
                    $countQ->execute([(int)$actor['id']]);
                    if ((int)$countQ->fetchColumn() >= UploadManager::USER_FILE_LIMIT) {
                        throw new RuntimeException('Your private vault already contains the maximum 2 files. Replace or delete an existing file first.');
                    }
                }

                [$storageName, $storedPath] = self::moveAssembled((int)$actor['id'], $assembled);
                $q = $pdo->prepare(
                    'INSERT INTO user_uploads(user_id,original_name,storage_name,extension,mime_type,size_bytes,sha256,version) VALUES(?,?,?,?,?,?,?,1)'
                );
                $q->execute([
                    (int)$actor['id'],
                    $meta['name'],
                    $storageName,
                    $meta['extension'],
                    $mime,
                    (int)$meta['total_size'],
                    $sha,
                ]);
                $fileId = (int)$pdo->lastInsertId();
                $ownerId = (int)$actor['id'];
                $version = 1;
            } else {
                $fileId = (int)$meta['file_id'];
                $sql = 'SELECT f.* FROM user_uploads f WHERE f.id=?';
                $params = [$fileId];
                if (($actor['role'] ?? '') !== 'owner') {
                    $sql .= ' AND f.user_id=?';
                    $params[] = (int)$actor['id'];
                }
                $sql .= ' LIMIT 1 FOR UPDATE';
                $q = $pdo->prepare($sql);
                $q->execute($params);
                $oldRow = $q->fetch() ?: null;
                if (!$oldRow) {
                    throw new RuntimeException('File not found or not allowed.');
                }

                $ownerId = (int)$oldRow['user_id'];
                [$storageName, $storedPath] = self::moveAssembled($ownerId, $assembled);
                $q = $pdo->prepare(
                    'UPDATE user_uploads SET original_name=?,storage_name=?,extension=?,mime_type=?,size_bytes=?,sha256=?,version=version+1 WHERE id=?'
                );
                $q->execute([
                    $meta['name'],
                    $storageName,
                    $meta['extension'],
                    $mime,
                    (int)$meta['total_size'],
                    $sha,
                    $fileId,
                ]);
                $version = (int)$oldRow['version'] + 1;
            }

            $pdo->commit();

            if ($oldRow !== null) {
                self::unlinkStored((int)$oldRow['user_id'], (string)$oldRow['storage_name']);
                Security::audit((int)$actor['id'], 'private_file_replaced', [
                    'file_id'=>$fileId,
                    'owner_id'=>$ownerId,
                    'name'=>$meta['name'],
                    'size'=>(int)$meta['total_size'],
                    'chunked'=>true,
                ]);
                $cdnSynced = CdnCache::purgeVaultRow($oldRow);
            } else {
                Security::audit((int)$actor['id'], 'private_file_uploaded', [
                    'file_id'=>$fileId,
                    'name'=>$meta['name'],
                    'extension'=>$meta['extension'],
                    'size'=>(int)$meta['total_size'],
                    'chunked'=>true,
                ]);
                $cdnSynced = CdnCache::purgeVaultFile($fileId);
            }

            return [
                'ok'=>true,
                'id'=>$fileId,
                'name'=>$meta['name'],
                'size'=>(int)$meta['total_size'],
                'sha256'=>$sha,
                'version'=>$version,
                'cdn_synced'=>$cdnSynced,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_string($storedPath) && is_file($storedPath)) @unlink($storedPath);
            throw $e;
        }
    }

    private static function requestMeta(array $actor, array $input): array
    {
        $uploadId = strtolower(trim((string)($input['upload_id'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new RuntimeException('Invalid upload session.');
        }

        $name = basename(str_replace('\\', '/', (string)($input['original_name'] ?? '')));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || strlen($name) > 240) {
            throw new RuntimeException('Invalid file name.');
        }

        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Only .so and .zip files are allowed.');
        }

        $totalSize = self::parseInt($input['total_size'] ?? null, 1, self::MAX_FILE_BYTES, 'File is larger than the 50 MB application limit.');
        $expectedChunks = (int)ceil($totalSize / self::CHUNK_BYTES);
        $totalChunks = self::parseInt($input['total_chunks'] ?? null, 1, self::MAX_CHUNKS, 'Invalid upload chunk count.');
        if ($totalChunks !== $expectedChunks) {
            throw new RuntimeException('Invalid upload chunk count.');
        }

        $mode = strtolower(trim((string)($input['mode'] ?? 'upload')));
        if (!in_array($mode, ['upload','replace'], true)) {
            throw new RuntimeException('Invalid upload mode.');
        }
        $fileId = $mode === 'replace'
            ? self::parseInt($input['file_id'] ?? null, 1, PHP_INT_MAX, 'Invalid file slot.')
            : 0;

        return [
            'upload_id'=>$uploadId,
            'user_id'=>(int)$actor['id'],
            'name'=>$name,
            'extension'=>$extension,
            'total_size'=>$totalSize,
            'total_chunks'=>$totalChunks,
            'mode'=>$mode,
            'file_id'=>$fileId,
        ];
    }

    private static function ensureSessionDir(array $actor, string $dir, array $meta): void
    {
        $userRoot = self::userChunkRoot((int)$actor['id']);
        if (!is_dir($userRoot) && !mkdir($userRoot, 0750, true) && !is_dir($userRoot)) {
            throw new RuntimeException('Could not create upload staging directory.');
        }

        if (!is_dir($dir)) {
            $active = 0;
            foreach (glob($userRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $candidate) {
                $active++;
            }
            if ($active >= self::MAX_ACTIVE_UPLOADS) {
                throw new RuntimeException('Too many unfinished uploads. Wait a moment and retry.');
            }
            if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create upload session.');
            }
            $payload = json_encode($meta + ['created_at'=>time()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($dir.DIRECTORY_SEPARATOR.'meta.json', $payload, LOCK_EX) === false) {
                self::removeTree($dir);
                throw new RuntimeException('Could not initialize upload session.');
            }
            @chmod($dir.DIRECTORY_SEPARATOR.'meta.json', 0640);
        }
    }

    private static function assertStoredMeta(string $dir, array $meta): void
    {
        $stored = self::loadMeta($dir);
        foreach (['upload_id','user_id','name','extension','total_size','total_chunks','mode','file_id'] as $key) {
            if ((string)($stored[$key] ?? '') !== (string)$meta[$key]) {
                throw new RuntimeException('Upload session metadata changed. Start the upload again.');
            }
        }
    }

    private static function loadMeta(string $dir): array
    {
        $file = $dir.DIRECTORY_SEPARATOR.'meta.json';
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Upload session expired or is unavailable.');
        }
        $raw = file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException('Upload session metadata is invalid.');
        }
        return $decoded;
    }

    private static function expectedChunkBytes(int $totalSize, int $totalChunks, int $index): int
    {
        if ($index < 0 || $index >= $totalChunks) return 0;
        if ($index < $totalChunks - 1) return self::CHUNK_BYTES;
        return $totalSize - (self::CHUNK_BYTES * ($totalChunks - 1));
    }

    private static function countChunks(string $dir, int $totalChunks): int
    {
        $count = 0;
        for ($i = 0; $i < $totalChunks; $i++) {
            if (is_file($dir.DIRECTORY_SEPARATOR.sprintf('%04d.part', $i))) $count++;
        }
        return $count;
    }

    private static function moveAssembled(int $userId, string $assembled): array
    {
        $dir = self::privateUserDir($userId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create private storage directory.');
        }

        for ($i = 0; $i < 8; $i++) {
            $storage = bin2hex(random_bytes(24)).'.blob';
            $path = $dir.DIRECTORY_SEPARATOR.$storage;
            if (file_exists($path)) continue;
            if (!@rename($assembled, $path)) {
                if (!@copy($assembled, $path)) {
                    throw new RuntimeException('Could not move uploaded file into private storage.');
                }
                @unlink($assembled);
            }
            @chmod($path, 0640);
            return [$storage, $path];
        }

        throw new RuntimeException('Could not allocate private storage name.');
    }

    private static function unlinkStored(int $userId, string $storageName): void
    {
        if (!preg_match('/^[a-f0-9]{48}\.blob$/', $storageName)) return;
        $path = self::privateUserDir($userId).DIRECTORY_SEPARATOR.$storageName;
        if (is_file($path)) @unlink($path);
    }

    private static function detectMime(string $path): string
    {
        $mime = 'application/octet-stream';
        if (class_exists('finfo')) {
            try {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected = $finfo->file($path);
                if (is_string($detected) && $detected !== '') $mime = substr($detected, 0, 120);
            } catch (Throwable) {
            }
        }
        return $mime;
    }

    private static function cleanupStale(int $userId): void
    {
        $root = self::userChunkRoot($userId);
        if (!is_dir($root)) return;
        $cutoff = time() - self::SESSION_TTL_SECONDS;
        foreach (glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime !== false && $mtime < $cutoff) self::removeTree($dir);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir.DIRECTORY_SEPARATOR.$name;
            if (is_dir($path)) self::removeTree($path); else @unlink($path);
        }
        @rmdir($dir);
    }

    private static function parseInt(mixed $value, int $min, int $max, string $message): int
    {
        $raw = is_int($value) ? (string)$value : trim((string)$value);
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) throw new RuntimeException($message);
        $number = (int)$raw;
        if ($number < $min || $number > $max) throw new RuntimeException($message);
        return $number;
    }

    private static function sessionDir(int $userId, string $uploadId): string
    {
        return self::userChunkRoot($userId).DIRECTORY_SEPARATOR.$uploadId;
    }

    private static function userChunkRoot(int $userId): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'upload_chunks'.DIRECTORY_SEPARATOR.'u'.$userId;
    }

    private static function privateUserDir(int $userId): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'private_uploads'.DIRECTORY_SEPARATOR.'u'.$userId;
    }
}
