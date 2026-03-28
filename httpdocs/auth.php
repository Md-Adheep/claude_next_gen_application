<?php
// auth.php — must be in the SAME folder as config.php, db.php, helpers.php

// Catch ALL PHP errors and return JSON instead of crashing
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>"PHP Error [$errno]: $errstr in ".basename($errfile)." line $errline"]);
    exit;
});
set_exception_handler(function($e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>"Exception: ".$e->getMessage()." in ".basename($e->getFile())." line ".$e->getLine()]);
    exit;
});

// Check required files exist
foreach (['helpers.php','db.php','config.php'] as $f) {
    if (!file_exists(__DIR__.'/'.$f)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>"Missing file: $f — all PHP files must be in SAME folder. Dir: ".__DIR__]);
        exit;
    }
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
cors();

$act   = $_GET['action'] ?? '';
$input = body();

if ($act === 'login') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    if (!$username || !$password) fail('Username and password are required.');

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND status="Active" LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) fail('Username not found.');
    if (!password_verify($password, $user['password'])) fail('Incorrect password.');

    startSess();
    $sess = ['id'=>$user['id'],'name'=>$user['name'],'username'=>$user['username'],
             'email'=>$user['email'],'role'=>$user['role'],'department'=>$user['department']];
    $_SESSION['ng_user'] = $sess;
    logAct($user['id'], 'LOGIN', 'Logged in');
    ok($sess, 'Login successful');
}
elseif ($act === 'logout') {
    $u = authUser();
    if ($u) logAct($u['id'], 'LOGOUT', 'Logged out');
    startSess(); session_destroy();
    ok([], 'Logged out');
}
elseif ($act === 'me') {
    ok(needAuth());
}
else {
    fail('Unknown action.', 404);
}
