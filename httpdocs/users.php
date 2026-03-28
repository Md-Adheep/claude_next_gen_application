<?php
// /users.php
// GET  ?action=list   [search, role, status]
// GET  ?action=get    id
// POST ?action=create {name,username,email,password,role,department}
// POST ?action=update {id,...fields}
// POST ?action=delete {id}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
cors();

$admin = needAdmin();
$act   = $_GET['action'] ?? '';
$b     = body();
$pdo   = db();

if ($act === 'list') {
    $w = ['1=1']; $p = [];
    if (!empty($b['role']))   { $w[] = 'role = ?';           $p[] = $b['role']; }
    if (!empty($b['status'])) { $w[] = 'status = ?';         $p[] = $b['status']; }
    if (!empty($b['search'])) {
        $like = '%'.$b['search'].'%';
        $w[]  = '(name LIKE ? OR email LIKE ? OR username LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    $st = $pdo->prepare('SELECT id,name,username,email,role,department,status,created_at FROM users WHERE '.implode(' AND ',$w).' ORDER BY created_at DESC');
    $st->execute($p);
    ok($st->fetchAll());
}

elseif ($act === 'get') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $st = $pdo->prepare('SELECT id,name,username,email,role,department,status,created_at FROM users WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(); if (!$row) fail('Not found.', 404);
    ok($row);
}

elseif ($act === 'create') {
    foreach (['name','username','email','password','role'] as $f)
        if (empty($b[$f])) fail("$f is required.");

    $email = filter_var(trim($b['email']), FILTER_VALIDATE_EMAIL);
    if (!$email) fail('Invalid email.');
    if (!in_array($b['role'], ['Admin','User','Staff'])) fail('Invalid role.');

    $ck = $pdo->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');
    $ck->execute([trim($b['username']), $email]);
    if ($ck->fetch()) fail('Username or email already exists.');

    $hash = password_hash(trim($b['password']), PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('INSERT INTO users(name,username,email,password,role,department,status) VALUES(?,?,?,?,?,?,"Active")')
        ->execute([clean($b['name']), clean($b['username']), $email, $hash, $b['role'], clean($b['department'] ?? '')]);
    $newId = $pdo->lastInsertId();
    logAct($admin['id'], 'USER_CREATED', "user: ".$b['username']);
    ok(['id' => $newId], 'User created');
}

elseif ($act === 'update') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $f = []; $p = [];
    foreach (['name','department'] as $k) if (isset($b[$k])) { $f[] = "$k=?"; $p[] = clean($b[$k]); }
    if (!empty($b['email'])) {
        $e = filter_var(trim($b['email']), FILTER_VALIDATE_EMAIL); if (!$e) fail('Invalid email.');
        $f[] = 'email=?'; $p[] = $e;
    }
    if (!empty($b['role'])) {
        if (!in_array($b['role'], ['Admin','User','Staff'])) fail('Invalid role.');
        $f[] = 'role=?'; $p[] = $b['role'];
    }
    if (!empty($b['status'])) { $f[] = 'status=?'; $p[] = $b['status']; }
    if (!empty($b['password'])) {
        $f[] = 'password=?';
        $p[] = password_hash(trim($b['password']), PASSWORD_BCRYPT, ['cost' => 12]);
    }
    if (!$f) fail('Nothing to update.');
    $p[] = $id;
    $pdo->prepare('UPDATE users SET '.implode(',',$f).' WHERE id=?')->execute($p);
    logAct($admin['id'], 'USER_UPDATED', "id:$id");
    ok([], 'Updated');
}

elseif ($act === 'delete') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    if ($id === (int)$admin['id']) fail('Cannot delete your own account.');
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    logAct($admin['id'], 'USER_DELETED', "id:$id");
    ok([], 'Deleted');
}

else fail('Unknown action.', 404);
