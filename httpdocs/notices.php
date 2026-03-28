<?php
// api/notices.php
// GET  ?action=list                                — all authenticated users
// GET  ?action=get&id=X
// POST ?action=create {title,body,priority,target} — Admin only
// POST ?action=update {id,...fields}               — Admin only
// POST ?action=delete {id}                         — Admin only

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
cors();

$user = needAuth();
$act  = $_GET['action'] ?? 'list';
$b    = body();
$pdo  = db();

/* ── LIST ─────────────────────────────────────── */
if ($act === 'list') {
    $role = $user['role'];
    $st = $pdo->prepare(
        'SELECT n.*, u.name AS created_by_name
         FROM notices n LEFT JOIN users u ON n.created_by = u.id
         WHERE n.target = "All" OR n.target = ?
         ORDER BY FIELD(n.priority,"High","Normal","Low"), n.created_at DESC'
    );
    $st->execute([$role]);
    ok($st->fetchAll());
}

/* ── GET ONE ──────────────────────────────────── */
elseif ($act === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('ID required.');
    $st = $pdo->prepare(
        'SELECT n.*, u.name AS created_by_name
         FROM notices n LEFT JOIN users u ON n.created_by = u.id
         WHERE n.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) fail('Notice not found.', 404);
    ok($row);
}

/* ── CREATE ───────────────────────────────────── */
elseif ($act === 'create') {
    if ($user['role'] !== 'Admin') fail('Admin access required.', 403);
    if (empty($b['title'])) fail('Title is required.');
    $priority = in_array($b['priority'] ?? '', ['Low','Normal','High']) ? $b['priority'] : 'Normal';
    $target   = in_array($b['target']   ?? '', ['All','Admin','User'])  ? $b['target']   : 'All';
    $pdo->prepare(
        'INSERT INTO notices (title, body, priority, target, created_by) VALUES (?,?,?,?,?)'
    )->execute([clean($b['title']), clean($b['body'] ?? ''), $priority, $target, $user['id']]);
    logAct($user['id'], 'NOTICE_CREATED', clean($b['title']));
    ok(['id' => $pdo->lastInsertId()], 'Notice published');
}

/* ── UPDATE ───────────────────────────────────── */
elseif ($act === 'update') {
    if ($user['role'] !== 'Admin') fail('Admin access required.', 403);
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $fields = []; $params = [];
    if (!empty($b['title']))    { $fields[] = 'title=?';    $params[] = clean($b['title']); }
    if (isset($b['body']))      { $fields[] = 'body=?';     $params[] = clean($b['body']); }
    if (!empty($b['priority'])) { $fields[] = 'priority=?'; $params[] = $b['priority']; }
    if (!empty($b['target']))   { $fields[] = 'target=?';   $params[] = $b['target']; }
    if (!$fields) fail('Nothing to update.');
    $params[] = $id;
    $pdo->prepare('UPDATE notices SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
    logAct($user['id'], 'NOTICE_UPDATED', "id:$id");
    ok([], 'Notice updated');
}

/* ── DELETE ───────────────────────────────────── */
elseif ($act === 'delete') {
    if ($user['role'] !== 'Admin') fail('Admin access required.', 403);
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $pdo->prepare('DELETE FROM notices WHERE id=?')->execute([$id]);
    logAct($user['id'], 'NOTICE_DELETED', "id:$id");
    ok([], 'Notice deleted');
}

else fail('Unknown action.', 404);
