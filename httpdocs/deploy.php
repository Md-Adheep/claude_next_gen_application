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
    writeLog('BLOCKED', 'Invalid token: "' . $token . '" from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    die(json_encode(['error' => 'Unauthorized']));
}

// ── Read payload ─────────────────────────────────────────────
$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$branch  = $payload['branch'] ?? 'unknown';
$commit  = $payload['commit'] ?? 'unknown';
$pusher  = $payload['pusher'] ?? 'unknown';

writeLog('DEPLOY_START', "Branch: $branch | Commit: $commit | By: $pusher");

// ── Find git binary ───────────────────────────────────────────
$gitBin = trim((string)shell_exec('which git 2>/dev/null'))
       ?: trim((string)shell_exec('command -v git 2>/dev/null'));

if (!$gitBin) {
    foreach (['/usr/bin/git', '/usr/local/bin/git', '/opt/plesk/git/bin/git'] as $p) {
        if (file_exists($p)) { $gitBin = $p; break; }
    }
}

if (!$gitBin) {
    http_response_code(500);
    $msg = 'git not found in PATH. PATH=' . getenv('PATH');
    writeLog('ERROR', $msg);
    die(json_encode(['success' => false, 'error' => $msg]));
}

writeLog('INFO', "Using git: $gitBin");

// ── Find git repo root ────────────────────────────────────────
$repoDir = __DIR__;
if (!is_dir($repoDir . '/.git') && is_dir(dirname($repoDir) . '/.git')) {
    $repoDir = dirname($repoDir);
}

if (!is_dir($repoDir . '/.git')) {
    http_response_code(500);
    $msg = 'Git repo not found at: ' . $repoDir . ' or ' . dirname($repoDir);
    writeLog('ERROR', $msg);
    die(json_encode(['success' => false, 'error' => $msg]));
}

writeLog('INFO', "Repo dir: $repoDir");

// ── Run git commands ──────────────────────────────────────────
$dir = escapeshellarg($repoDir);
$git = escapeshellarg($gitBin);

$commands = [
    "cd $dir && HOME=/tmp $git fetch origin 2>&1",
    "cd $dir && HOME=/tmp $git reset --hard origin/" . DEPLOY_BRANCH . " 2>&1",
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
        $line     = (string)(shell_exec($cmd) ?? '');
        $exitCode = 0;
    }

    $output[] = trim($line);
    writeLog('CMD', "Exit:$exitCode | $cmd\n$line");

    if ($exitCode !== 0) {
        $success = false;
        writeLog('ERROR', "Failed (exit $exitCode): $line");
        break;
    }
}

$fullOutput = implode("\n", $output);
writeLog($success ? 'DEPLOY_OK' : 'DEPLOY_FAILED', $fullOutput);

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
