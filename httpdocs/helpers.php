<?php
require_once __DIR__ . '/config.php';

/* ── HTTP ─────────────────────────────────────────────────── */
function cors(): void {
    header('Access-Control-Allow-Origin: '  . CORS_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

function ok(array $data = [], string $msg = 'OK'): void {
    echo json_encode(['success' => true, 'message' => $msg, 'data' => $data],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    $j   = json_decode($raw, true);
    return is_array($j) ? $j : array_merge($_GET, $_POST);
}

function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

/* ── Session / Auth ──────────────────────────────────────── */
function startSess(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'path' => '/',
                                   'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function authUser(): ?array { startSess(); return $_SESSION['ng_user'] ?? null; }

function needAuth(): array {
    $u = authUser();
    if (!$u) fail('Unauthorized. Please log in.', 401);
    return $u;
}

function needAdmin(): array {
    $u = needAuth();
    if ($u['role'] !== 'Admin') fail('Admin access required.', 403);
    return $u;
}

/* ── Activity log ────────────────────────────────────────── */
function logAct(?int $uid, string $action, string $desc = ''): void {
    try {
        require_once __DIR__ . '/db.php';
        db()->prepare('INSERT INTO activity_log (user_id,action,description,ip_address) VALUES (?,?,?,?)')
           ->execute([$uid, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) { /* non-fatal */ }
}
