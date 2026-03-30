<?php
// ============================================================
//  deploy.php — Auto-deployment webhook (ZIP-based)
//  Called by GitHub Actions on every push.
//  Downloads latest code from GitHub as ZIP and extracts it.
// ============================================================

// ── Config ───────────────────────────────────────────────────
define('DEPLOY_SECRET',  getenv('DEPLOY_SECRET') ?: 'Md.Adheep@2005');
define('GITHUB_REPO',    'Md-Adheep/claude_next_gen_application');
define('DEPLOY_BRANCH',  'main');
define('DEPLOY_DIR',     __DIR__);
define('LOG_FILE',       __DIR__ . '/deploy.log');

// Files/folders to never delete during deploy
define('SKIP_DELETE', ['deploy.php', 'deploy.log', '.htaccess', 'config.php']);

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

// ── Download ZIP from GitHub ──────────────────────────────────
$zipUrl  = "https://github.com/" . GITHUB_REPO . "/archive/refs/heads/" . DEPLOY_BRANCH . ".zip";
$tmpZip  = sys_get_temp_dir() . '/deploy_' . time() . '.zip';

$ctx = stream_context_create([
    'http' => [
        'method'          => 'GET',
        'header'          => "User-Agent: deploy-webhook\r\n",
        'follow_location' => 1,
        'timeout'         => 60,
    ],
    'ssl' => ['verify_peer' => false],
]);

$zipData = @file_get_contents($zipUrl, false, $ctx);
if ($zipData === false) {
    $err = 'Failed to download ZIP from GitHub: ' . $zipUrl;
    writeLog('ERROR', $err);
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => $err]));
}

file_put_contents($tmpZip, $zipData);
writeLog('INFO', "Downloaded ZIP (" . round(strlen($zipData) / 1024) . " KB)");

// ── Extract ZIP ───────────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($tmpZip) !== true) {
    writeLog('ERROR', 'Failed to open ZIP file');
    @unlink($tmpZip);
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Failed to open ZIP']));
}

$tmpExtract = sys_get_temp_dir() . '/deploy_extract_' . time();
mkdir($tmpExtract, 0755, true);
$zip->extractTo($tmpExtract);
$zip->close();
@unlink($tmpZip);

// ── Find extracted subfolder (repo-main/) ────────────────────
$repoName    = basename(GITHUB_REPO);
$extractedDir = $tmpExtract . '/' . $repoName . '-' . DEPLOY_BRANCH . '/httpdocs';

if (!is_dir($extractedDir)) {
    // Fallback: try root of extracted zip
    $extractedDir = $tmpExtract . '/' . $repoName . '-' . DEPLOY_BRANCH;
}

writeLog('INFO', "Extracted to: $extractedDir");

// ── Copy files to httpdocs ────────────────────────────────────
$copied = copyDir($extractedDir, DEPLOY_DIR);

// ── Cleanup temp ─────────────────────────────────────────────
deleteDir($tmpExtract);

writeLog('DEPLOY_OK', "Deployed commit $commit — $copied files updated");
http_response_code(200);
echo json_encode([
    'success' => true,
    'branch'  => $branch,
    'commit'  => $commit,
    'files'   => $copied,
    'message' => 'Deployed successfully via ZIP',
]);

// ── Helpers ───────────────────────────────────────────────────
function copyDir(string $src, string $dst): int {
    $count = 0;
    if (!is_dir($src)) return 0;
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;
        if (is_dir($srcPath)) {
            if (!is_dir($dstPath)) mkdir($dstPath, 0755, true);
            $count += copyDir($srcPath, $dstPath);
        } else {
            if (in_array($item, SKIP_DELETE)) continue; // never overwrite protected files
            if (@copy($srcPath, $dstPath)) $count++;
        }
    }
    return $count;
}

function deleteDir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? deleteDir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function writeLog(string $event, string $detail): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $event . '] ' . $detail . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
