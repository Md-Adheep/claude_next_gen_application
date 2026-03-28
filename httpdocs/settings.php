<?php
// settings.php
// GET  ?action=get_profile          — current user profile
// GET  ?action=get_system           — system/smtp settings (admin only)
// POST ?action=update_profile       — update name, email, username
// POST ?action=change_password      — change password
// POST ?action=update_system        — save smtp settings to DB (admin only)
// POST ?action=admin_reset_password — reset another user's password (admin only)
// POST ?action=test_smtp            — send a test email (admin only)

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
cors();

$user = needAuth();
$act  = $_GET['action'] ?? '';
$b    = body();
$pdo  = db();

/* ── helpers ─────────────────────────────────────────────── */
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $st = $pdo->prepare('SELECT value FROM system_settings WHERE `key`=? LIMIT 1');
    $st->execute([$key]);
    $row = $st->fetch();
    return ($row && $row['value'] !== null) ? $row['value'] : $default;
}

function setSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare(
        'INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()'
    )->execute([$key, $value]);
}

/* GET PROFILE ───────────────────────────────────────────── */
if ($act === 'get_profile') {
    $st = $pdo->prepare('SELECT id,name,username,email,role,department FROM users WHERE id=?');
    $st->execute([$user['id']]);
    ok($st->fetch());
}

/* UPDATE PROFILE ────────────────────────────────────────── */
elseif ($act === 'update_profile') {
    $name  = clean($b['name']  ?? '');
    $email = trim($b['email']  ?? '');
    $dept  = clean($b['department'] ?? '');

    if (!$name)  fail('Name is required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email.');

    $ck = $pdo->prepare('SELECT id FROM users WHERE email=? AND id!=? LIMIT 1');
    $ck->execute([$email, $user['id']]);
    if ($ck->fetch()) fail('Email already used by another account.');

    $pdo->prepare('UPDATE users SET name=?, email=?, department=?, updated_at=NOW() WHERE id=?')
        ->execute([$name, $email, $dept, $user['id']]);

    $_SESSION['ng_user']['name']  = $name;
    $_SESSION['ng_user']['email'] = $email;

    logAct($user['id'], 'PROFILE_UPDATED', 'name:' . $name);
    ok(['name' => $name, 'email' => $email], 'Profile updated successfully.');
}

/* CHANGE PASSWORD ───────────────────────────────────────── */
elseif ($act === 'change_password') {
    $current = trim($b['current_password'] ?? '');
    $new     = trim($b['new_password']     ?? '');
    $confirm = trim($b['confirm_password'] ?? '');

    if (!$current || !$new || !$confirm) fail('All password fields are required.');
    if (strlen($new) < 6) fail('New password must be at least 6 characters.');
    if ($new !== $confirm) fail('New password and confirmation do not match.');

    $st = $pdo->prepare('SELECT password FROM users WHERE id=?');
    $st->execute([$user['id']]);
    $row = $st->fetch();
    if (!password_verify($current, $row['password'])) fail('Current password is incorrect.');

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?')
        ->execute([$hash, $user['id']]);

    logAct($user['id'], 'PASSWORD_CHANGED', '');
    ok([], 'Password changed successfully.');
}

/* GET SYSTEM SETTINGS — read from DB ────────────────────── */
elseif ($act === 'get_system') {
    if ($user['role'] !== 'Admin') fail('Admin access required.', 403);

    ok([
        'app_name'       => getSetting($pdo, 'app_name',       APP_NAME),
        'smtp_host'      => getSetting($pdo, 'smtp_host'),
        'smtp_port'      => getSetting($pdo, 'smtp_port',      '587'),
        'smtp_secure'    => getSetting($pdo, 'smtp_secure',    'tls'),
        'smtp_username'  => getSetting($pdo, 'smtp_username'),
        'smtp_password'  => getSetting($pdo, 'smtp_password'),
        'smtp_from'      => getSetting($pdo, 'smtp_from'),
        'smtp_from_name' => getSetting($pdo, 'smtp_from_name', APP_NAME),
    ]);
}

/* UPDATE SYSTEM SETTINGS — save to DB ───────────────────── */
elseif ($act === 'update_system') {
    if ($user['role'] !== 'Admin') fail('Admin access required.', 403);

    $fields = [
        'app_name', 'smtp_host', 'smtp_port', 'smtp_secure',
        'smtp_username', 'smtp_password', 'smtp_from', 'smtp_from_name',
    ];
    foreach ($fields as $key) {
        if (isset($b[$key])) {
            setSetting($pdo, $key, trim($b[$key]));
        }
    }

    logAct($user['id'], 'SYSTEM_SETTINGS_UPDATED', '');
    ok([], 'System settings saved successfully.');
}

/* ADMIN RESET USER PASSWORD ─────────────────────────────── */
elseif ($act === 'admin_reset_password') {
    if ($user['role'] !== 'Admin') fail('Admin access required.', 403);

    $uid   = (int)($b['user_id']     ?? 0);
    $newPw = trim($b['new_password'] ?? '');

    if (!$uid)              fail('User ID required.');
    if (strlen($newPw) < 6) fail('Password must be at least 6 characters.');

    $st = $pdo->prepare('SELECT id, name, role FROM users WHERE id=? LIMIT 1');
    $st->execute([$uid]);
    $target = $st->fetch();
    if (!$target)                    fail('User not found.');
    if ($target['role'] === 'Admin') fail('Cannot reset another Admin password from here.');

    $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?')
        ->execute([$hash, $uid]);

    logAct($user['id'], 'ADMIN_PASSWORD_RESET', 'target_user:' . $uid . ' name:' . $target['name']);
    ok([], 'Password reset for ' . $target['name'] . ' successfully.');
}

/* TEST SMTP — reads settings from DB ────────────────────── */
elseif ($act === 'test_smtp') {
    if ($user['role'] !== 'Admin') fail('Admin access required.', 403);

    $smtpHost = getSetting($pdo, 'smtp_host');
    $smtpFrom = getSetting($pdo, 'smtp_from');
    $to       = trim($b['to'] ?? $smtpFrom);

    if (!$smtpHost) fail('SMTP host is not configured. Save your SMTP settings first.');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Invalid test email address.');

    $subject = 'NextGen SMTP Test — ' . date('d M Y H:i');
    $body    = '<html><body style="font-family:sans-serif;padding:20px;">'
             . '<h2 style="color:#7C3AED;">SMTP Test Successful!</h2>'
             . '<p>Your email settings are configured correctly.</p>'
             . '<p style="color:#6B7280;font-size:13px;">Sent at: ' . date('d M Y H:i:s') . '</p>'
             . '</body></html>';

    $result = sendMail($to, $to, $subject, $body);

    if (!$result['ok']) fail('Test failed: ' . $result['error']);
    ok([], 'Test email sent to ' . $to . ' successfully!');
}

else fail('Unknown action.', 404);
