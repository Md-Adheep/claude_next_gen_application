<?php
// ============================================================
//  deploy.php — Auto-deployment webhook
//  Called by GitHub Actions on every push.
//  DO NOT expose this URL publicly — protect with secret token.
// ============================================================

// ── Config ───────────────────────────────────────────────────
define('DEPLOY_SECRET', getenv('DEPLOY_SECRET') ?: 'Md.Adheep@2005');

define('DEPLOY_BRANCH', 'main');
define('LOG_FILE',      __DIR__ . '/deploy.log');

// ── Security: only allow POST ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// ── Verify secret token ──────────────────────────────────────
$token = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if (!hash_equals(DEPLOY_SECRET, $token)) {
    http_response_code(403);
    writeLog('BLOCKED', 'Invalid token from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    die(json_encode(['error' => 'Unauthorized']));
}

// ── Read payload ─────────────────────────────────────────────
$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$branch  = $payload['branch'] ?? 'unknown';
$commit  = $payload['commit'] ?? 'unknown';
$pusher  = $payload['pusher'] ?? 'unknown';

writeLog('DEPLOY_START', "Branch: $branch | Commit: $commit | By: $pusher");

// ── Run git pull ─────────────────────────────────────────────
$projectDir = escapeshellarg(__DIR__);
$gitDir = escapeshellarg(dirname(__DIR__) . '/git');
$gitBranch  = escapeshellarg(DEPLOY_BRANCH);

$commands = [
    "git --git-dir={$gitDir} --work-tree={$projectDir} fetch origin 2>&1",
    "git --git-dir={$gitDir} --work-tree={$projectDir} reset --hard origin/main 2>&1",
];

$output = [];
$success = true;

foreach ($commands as $cmd) {
    $result = null;
    $exitCode = null;

    if (function_exists('exec')) {
        exec($cmd, $result, $exitCode);
        $line = implode("\n", $result);
    } elseif (function_exists('shell_exec')) {
        $line    = shell_exec($cmd) ?? '';
        $exitCode = 0;
    } else {
        http_response_code(500);
        $msg = 'exec() and shell_exec() are disabled on this server. Enable one in php.ini or Plesk PHP settings.';
        writeLog('ERROR', $msg);
        die(json_encode(['success' => false, 'error' => $msg]));
    }

    $output[] = $line;
    if ($exitCode !== 0 && $exitCode !== null) {
        $success = false;
        writeLog('ERROR', "Command failed (exit $exitCode): $cmd\n$line");
        break;
    }
}

$status = $success ? 'DEPLOY_OK' : 'DEPLOY_FAILED';
$fullOutput = implode("\n", $output);
writeLog($status, "Commit: $commit\n" . $fullOutput);

http_response_code($success ? 200 : 500);
echo json_encode([
    'success' => $success,
    'branch'  => $branch,
    'commit'  => $commit,
    'output'  => $fullOutput,
]);

// ── Logger ───────────────────────────────────────────────────
function writeLog(string $event, string $detail): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $event . '] ' . $detail . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
