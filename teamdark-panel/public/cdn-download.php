<?php
declare(strict_types=1);

use TeamDark\Panel\{CdnCache,Config,Database,UploadManager};

$root = dirname(__DIR__);
foreach (['Config','Database','CdnCache','UploadManager'] as $file) {
    require_once $root.'/app/'.$file.'.php';
}
Config::load($root);

function cdnFail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit;
}

try {
    if (!CdnCache::enabled()) cdnFail(404, 'File not found.');

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET','HEAD'], true)) cdnFail(405, 'Method not allowed.');

    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    $fileId = 0;
    $legacyVersion = 0;
    $signature = '';
    $stableRoute = false;

    // v2 permanent URL: /cdn-files/{file_id}/{stable_signature}/asset.zip
    if (preg_match('#/cdn-files/(\d+)/([a-f0-9]{64})/asset\.zip/?$#i', $path, $m)) {
        $fileId = (int)$m[1];
        $signature = strtolower($m[2]);
        $stableRoute = true;
    // v1 compatibility URL retained for links shared before stable URLs shipped.
    } elseif (preg_match('#/cdn-files/(\d+)/(\d+)/([a-f0-9]{64})/asset\.zip/?$#i', $path, $m)) {
        $fileId = (int)$m[1];
        $legacyVersion = (int)$m[2];
        $signature = strtolower($m[3]);
    } else {
        cdnFail(404, 'File not found.');
    }

    if ($fileId <= 0 || (!$stableRoute && $legacyVersion <= 0)) cdnFail(404, 'File not found.');

    UploadManager::ensureSchema();
    $q = Database::pdo()->prepare('SELECT * FROM user_uploads WHERE id=? LIMIT 1');
    $q->execute([$fileId]);
    $row = $q->fetch();
    if (!$row) cdnFail(404, 'File not found.');

    if ($stableRoute) {
        if (!CdnCache::verifyStableVaultSignature($row, $signature)) {
            cdnFail(404, 'File not found.');
        }
    } else {
        $currentVersion = (int)$row['version'];
        $validCurrentLegacy = $currentVersion === $legacyVersion
            && CdnCache::verifyVaultSignature($row, $signature);
        $validRememberedLegacy = !$validCurrentLegacy
            && CdnCache::legacyAliasMatches($fileId, $legacyVersion, $signature);
        if (!$validCurrentLegacy && !$validRememberedLegacy) {
            cdnFail(404, 'File not found.');
        }
    }

    $storageName = (string)$row['storage_name'];
    if (!preg_match('/^[a-f0-9]{48}\.blob$/', $storageName)) cdnFail(404, 'File not found.');

    $filePath = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'private_uploads'
        .DIRECTORY_SEPARATOR.'u'.(int)$row['user_id']
        .DIRECTORY_SEPARATOR.$storageName;
    if (!is_file($filePath) || !is_readable($filePath)) cdnFail(404, 'File not found.');

    $size = filesize($filePath);
    if ($size === false || $size < 0) cdnFail(500, 'Could not read file.');

    $name = (string)$row['original_name'];
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'download.'.(string)$row['extension'];
    $type = strtolower((string)$row['extension']) === 'zip' ? 'application/zip' : 'application/octet-stream';
    $ttl = CdnCache::cacheSeconds();
    $version = (int)$row['version'];
    $etag = '"'.strtolower((string)$row['sha256']).'-v'.$version.'"';
    $mtime = filemtime($filePath) ?: time();

    $start = 0;
    $end = max(0, $size - 1);
    $partial = false;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '' && $size > 0) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $rm)) {
            header('Content-Range: bytes */'.$size);
            cdnFail(416, 'Requested range is not satisfiable.');
        }
        $startRaw = $rm[1];
        $endRaw = $rm[2];
        if ($startRaw === '' && $endRaw === '') {
            header('Content-Range: bytes */'.$size);
            cdnFail(416, 'Requested range is not satisfiable.');
        }
        if ($startRaw === '') {
            $suffix = (int)$endRaw;
            if ($suffix <= 0) {
                header('Content-Range: bytes */'.$size);
                cdnFail(416, 'Requested range is not satisfiable.');
            }
            $suffix = min($suffix, $size);
            $start = $size - $suffix;
            $end = $size - 1;
        } else {
            $start = (int)$startRaw;
            $end = $endRaw === '' ? $size - 1 : (int)$endRaw;
            if ($start >= $size || $end < $start) {
                header('Content-Range: bytes */'.$size);
                cdnFail(416, 'Requested range is not satisfiable.');
            }
            $end = min($end, $size - 1);
        }
        $partial = true;
    }

    while (ob_get_level() > 0) ob_end_clean();
    @set_time_limit(0);
    header('Content-Type: '.$type);
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
    header('Accept-Ranges: bytes');
    header('ETag: '.$etag);
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');

    // The URL is permanent while its bytes may change. Browsers therefore
    // revalidate, while Cloudflare can retain the current object for the full TTL.
    header('Cache-Control: public, max-age=0, s-maxage='.$ttl.', must-revalidate');
    header('CDN-Cache-Control: public, max-age='.$ttl);
    header('Cloudflare-CDN-Cache-Control: public, max-age='.$ttl);
    header('X-TeamDark-CDN: stable-signed-v2');
    header('X-TeamDark-File-Version: '.$version);
    header('Content-Disposition: attachment; filename="'.addcslashes($fallback, "\\\"").'"; filename*=UTF-8\'\''.rawurlencode($name));

    $length = $size === 0 ? 0 : ($end - $start + 1);
    if ($partial) {
        http_response_code(206);
        header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    } else {
        http_response_code(200);
    }
    header('Content-Length: '.$length);

    if ($method === 'HEAD' || $length === 0) exit;

    $handle = fopen($filePath, 'rb');
    if ($handle === false) cdnFail(500, 'Could not open file.');
    if ($start > 0 && fseek($handle, $start) !== 0) {
        fclose($handle);
        cdnFail(500, 'Could not seek file.');
    }

    $remaining = $length;
    $chunkSize = 1024 * 1024;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min($chunkSize, $remaining));
        if ($chunk === false) break;
        $bytes = strlen($chunk);
        if ($bytes === 0) break;
        echo $chunk;
        $remaining -= $bytes;
        flush();
        if (connection_aborted()) break;
    }
    fclose($handle);
    exit;
} catch (Throwable $e) {
    error_log('TeamDark CDN download error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    cdnFail(500, 'Download failed.');
}
