<?php
declare(strict_types=1);

use TeamDark\Panel\{Auth,Config,Database,PanelControl,Security};

$root = dirname(__DIR__);
foreach (['Config','Database','Security','PanelControl','Auth'] as $file) {
    require $root.'/app/'.$file.'.php';
}

Config::load($root);
Security::enforceHttpsWeb();
Security::headers();
Security::startSession();

function passwordRedirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function passwordFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function simplePasswordValid(string $password): bool
{
    return strlen($password) >= 1 && strlen($password) <= 200;
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        exit;
    }

    $user = Auth::requireLogin();
    Security::verifyCsrf($_POST['csrf'] ?? null);
    $path = '/'.ltrim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');

    if ($path === '/security/password') {
        $current = (string)($_POST['current_password'] ?? '');
        $next = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!Auth::verifyCurrentPassword((int)$user['id'], $current)) {
            throw new RuntimeException('Current password is incorrect.');
        }
        if (!simplePasswordValid($next)) {
            throw new RuntimeException('New password must be between 1 and 200 characters.');
        }
        if (!hash_equals($next, $confirm)) {
            throw new RuntimeException('New password confirmation does not match.');
        }
        if (hash_equals($current, $next)) {
            throw new RuntimeException('Choose a password different from the current password.');
        }

        $newHash = Security::passwordHash($next);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')
                ->execute([$newHash, (int)$user['id']]);
            $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([(int)$user['id']]);
            $pdo->prepare('DELETE FROM login_2fa_challenges WHERE user_id=?')->execute([(int)$user['id']]);
            $versionQ = $pdo->prepare('SELECT auth_version FROM users WHERE id=? LIMIT 1');
            $versionQ->execute([(int)$user['id']]);
            $newVersion = max(1, (int)$versionQ->fetchColumn());
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        Auth::refreshCurrentSessionCredentials((int)$user['id'], $newVersion, $newHash);
        Security::audit((int)$user['id'], 'password_changed_self');
        passwordFlash('ok', 'Password changed. Other sessions and API tokens were revoked.');
        passwordRedirect('/dashboard');
    }

    if ($path === '/users/password') {
        Auth::requireRole($user, 'owner');
        if (!Auth::recentlyAuthenticated(300)) {
            Auth::logout();
            Security::startSession();
            passwordFlash('err', 'Fresh sign-in required for this privileged security action.');
            passwordRedirect('/login');
        }

        $targetId = (int)($_POST['user_id'] ?? 0);
        $q = Database::pdo()->prepare('SELECT id,username,role,status FROM users WHERE id=? LIMIT 1');
        $q->execute([$targetId]);
        $target = $q->fetch();
        if (!$target || $target['role'] === 'owner' || (int)$target['id'] === (int)$user['id']) {
            throw new RuntimeException('This account cannot be managed.');
        }

        $password = (string)($_POST['password'] ?? '');
        if (!simplePasswordValid($password)) {
            throw new RuntimeException('Password must be between 1 and 200 characters.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')
                ->execute([Security::passwordHash($password), $targetId]);
            $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM login_2fa_challenges WHERE user_id=?')->execute([$targetId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        Security::audit((int)$user['id'], 'user_password_reset', ['target_id'=>$targetId]);
        passwordFlash('ok', 'Password reset and active API access revoked for @'.$target['username'].'.');
        passwordRedirect('/users');
    }

    http_response_code(404);
    exit;
} catch (Throwable $e) {
    error_log('TeamDark password action error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine().' '.$e->getMessage());
    passwordFlash('err', substr($e->getMessage() ?: 'Password action failed.', 0, 300));
    $fallback = (isset($path) && $path === '/users/password') ? '/users' : '/dashboard';
    passwordRedirect($fallback);
}
