<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Database,PanelControl,Security,UploadManager};

$root = dirname(__DIR__);
foreach (['Config','Database','Security','PanelControl','Auth','UploadManager'] as $file) {
    require_once $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::enforceHttpsWeb();
Security::headers();
Security::startSession();

function downloadFail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    echo $message;
    exit;
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET','HEAD'], true)) {
        downloadFail(405, 'Method not allowed.');
    }

    $user = Auth::requireLogin();
    if (PanelControl::blocked($user)) {
        downloadFail(503, 'Panel is temporarily unavailable.');
    }

    UploadManager::ensureSchema();
    $fileId = (int)($_GET['id'] ?? 0);
    if ($fileId <= 0) downloadFail(404, 'File not found.');

    $sql = 'SELECT f.* FROM user_uploads f WHERE f.id=?';
    $params = [$fileId];
    if (($user['role'] ?? '') !== 'owner') {
        $sql .= ' AND f.user_id=?';
        $params[] = (int)$user['id'];
    }
    $sql .= ' LIMIT 1';

    $q = Database::pdo()->prepare($sql);
    $q->execute($params);
    $row = $q->fetch();
    if (!$row) downloadFail(404, 'File not found.');

    $storageName = (string)$row['storage_name'];
    if (!preg_match('/^[a-f0-9]{48}\.blob$/', $storageName)) {
        downloadFail(404, 'Stored file is unavailable.');
    }

    $path = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'private_uploads'
        .DIRECTORY_SEPARATOR.'u'.(int)$row['user_id']
        .DIRECTORY_SEPARATOR.$storageName;

    if (!is_file($path) || !is_readable($path)) {
        downloadFail(404, 'Stored file is unavailable.');
    }

    $size = filesize($path);
    if ($size === false || $size < 0) {
        downloadFail(500, 'Could not read stored file.');
    }

    $name = (string)$row['original_name'];
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name)
        ?: 'download.'.(string)$row['extension'];
    $type = strtolower((string)$row['extension']) === 'zip'
        ? 'application/zip'
        : 'application/octet-stream';

    $start = 0;
    $end = max(0, $size - 1);
    $partial = false;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));

    if ($range !== '' && $size > 0) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            header('Content-Range: bytes */'.$size);
            downloadFail(416, 'Requested range is not satisfiable.');
        }

        $startRaw = $m[1];
        $endRaw = $m[2];
        if ($startRaw === '' && $endRaw === '') {
            header('Content-Range: bytes */'.$size);
            downloadFail(416, 'Requested range is not satisfiable.');
        }

        if ($startRaw === '') {
            $suffix = (int)$endRaw;
            if ($suffix <= 0) {
                header('Content-Range: bytes */'.$size);
                downloadFail(416, 'Requested range is not satisfiable.');
            }
            $suffix = min($suffix, $size);
            $start = $size - $suffix;
            $end = $size - 1;
        } else {
            $start = (int)$startRaw;
            $end = $endRaw === '' ? $size - 1 : (int)$endRaw;
            if ($start >= $size || $end < $start) {
                header('Content-Range: bytes */'.$size);
                downloadFail(416, 'Requested range is not satisfiable.');
            }
            $end = min($end, $size - 1);
        }

        $partial = true;
    }

    while (ob_get_level() > 0) ob_end_clean();
    @set_time_limit(0);
    header('Content-Type: '.$type);
    header('X-Content-Type-Options: nosniff');
    header('X-Download-Options: noopen');
    header('Cache-Control: private, no-store, max-age=0');
    header('Accept-Ranges: bytes');
    header('Content-Disposition: attachment; filename="'.addcslashes($fallback, "\\\"").'"; filename*=UTF-8\'\''.rawurlencode($name));

    $length = $size === 0 ? 0 : ($end - $start + 1);
    if ($partial) {
        http_response_code(206);
        header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    } else {
        http_response_code(200);
    }
    header('Content-Length: '.$length);

    // Release the PHP session file lock before a potentially long download so
    // the same signed-in user can continue using the panel in other tabs.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    Security::audit((int)$user['id'], 'private_file_downloaded', [
        'file_id'=>$fileId,
        'owner_id'=>(int)$row['user_id'],
        'partial'=>$partial,
        'start'=>$start,
        'end'=>$end,
    ]);

    if ($method === 'HEAD' || $length === 0) exit;

    $handle = fopen($path, 'rb');
    if ($handle === false) downloadFail(500, 'Could not open stored file.');
    if ($start > 0 && fseek($handle, $start) !== 0) {
        fclose($handle);
        downloadFail(500, 'Could not seek stored file.');
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
        if (function_exists('fastcgi_finish_request')) {
            // Do not call fastcgi_finish_request here; it would terminate the stream.
        }
        flush();
        if (connection_aborted()) break;
    }
    fclose($handle);
    exit;
} catch (Throwable $e) {
    error_log('TeamDark private download error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    downloadFail(500, 'Download failed.');
}
