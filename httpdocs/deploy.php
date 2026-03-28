<?php
// ============================================================
//  deploy.php — Auto-deployment webhook
//  Called by GitHub Actions on every push to main.
// ============================================================

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

// ── Find git repo root (could be httpdocs or one level up) ───
$repoDir = __DIR__;
if (!is_dir($repoDir . '/.git') && is_dir(dirname($repoDir) . '/.git')) {
    $repoDir = dirname($repoDir);
}

if (!is_dir($repoDir . '/.git')) {
    http_response_code(500);
    $msg = 'Git repo not found. Run: cd ' . $repoDir . ' && git init && git remote add origin YOUR_REPO_URL && git pull origin main';
    writeLog('ERROR', $msg);
    die(json_encode(['success' => false, 'error' => $msg]));
}

// ── Check exec availability ───────────────────────────────────
if (!function_exists('exec') && !function_exists('shell_exec')) {
    http_response_code(500);
    $msg = 'exec() and shell_exec() are both disabled. Enable one in Plesk PHP settings.';
    writeLog('ERROR', $msg);
    die(json_encode(['success' => false, 'error' => $msg]));
}

// ── Run git commands synchronously (NO & background) ─────────
$dir    = escapeshellarg($repoDir);
$branch = escapeshellarg(DEPLOY_BRANCH);

$commands = [
    "cd $dir && git fetch origin 2>&1",
    "cd $dir && git reset --hard origin/" . DEPLOY_BRANCH . " 2>&1",
];

$output  = [];
$success = true;

foreach ($commands as $cmd) {
    $result   = [];
    $exitCode = 0;

    if (function_exists('exec')) {
        exec($cmd, $result, $exitCode);
        $line = implode("\n", $result);
    } else {
        $line     = shell_exec($cmd) ?? '';
        $exitCode = 0;
    }

    $output[] = trim($line);
    writeLog('CMD', "$cmd\nExit: $exitCode\n$line");

    if ($exitCode !== 0) {
        $success = false;
        writeLog('ERROR', "Command failed (exit $exitCode): $cmd\n$line");
        break;
    }
}

$fullOutput = implode("\n", $output);
writeLog($success ? 'DEPLOY_OK' : 'DEPLOY_FAILED', "Commit: $commit\n$fullOutput");

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
