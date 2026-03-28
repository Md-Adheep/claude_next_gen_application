<?php
// /courses.php
// GET  ?action=list   [search, category, status]
// GET  ?action=get    id
// POST ?action=create {name,category,emoji,duration,fee,max_students,instructor,description,color,status}
// POST ?action=update {id,...}
// POST ?action=delete {id}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
cors();

$user = needAuth();
$act  = $_GET['action'] ?? '';
$b    = body();
$pdo  = db();

if ($act === 'list') {
    $w = ['1=1']; $p = [];
    if (!empty($b['category'])) { $w[] = 'category=?'; $p[] = $b['category']; }
    if (!empty($b['status']))   { $w[] = 'status=?';   $p[] = $b['status']; }
    if (!empty($b['search']))   { $w[] = 'name LIKE ?'; $p[] = '%'.$b['search'].'%'; }
    $st = $pdo->prepare(
        'SELECT c.*,
                (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status!="Dropped") AS enrolled
         FROM courses c WHERE '.implode(' AND ',$w).' ORDER BY c.created_at DESC'
    );
    $st->execute($p);
    ok($st->fetchAll());
}

elseif ($act === 'get') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $st = $pdo->prepare('SELECT c.*,(SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status!="Dropped") AS enrolled FROM courses c WHERE c.id=?');
    $st->execute([$id]);
    $row = $st->fetch(); if (!$row) fail('Not found.', 404);
    ok($row);
}

elseif ($act === 'create') {
    if ($user['role'] !== 'Admin') fail('Admin only.', 403);
    if (empty($b['name'])) fail('Course name required.');
    $pdo->prepare('INSERT INTO courses(name,category,emoji,duration,fee,max_students,instructor,description,color,status) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([clean($b['name']), clean($b['category'] ?? ''), clean($b['emoji'] ?? '📚'),
                   clean($b['duration'] ?? ''), (float)($b['fee'] ?? 0), (int)($b['max_students'] ?? 30),
                   clean($b['instructor'] ?? ''), clean($b['description'] ?? ''),
                   clean($b['color'] ?? '#7C3AED'), $b['status'] ?? 'Active']);
    logAct($user['id'], 'COURSE_CREATED', $b['name']);
    ok(['id' => $pdo->lastInsertId()], 'Course created');
}

elseif ($act === 'update') {
    if ($user['role'] !== 'Admin') fail('Admin only.', 403);
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $f = []; $p = [];
    foreach (['name','category','emoji','duration','instructor','description','color','status'] as $k)
        if (isset($b[$k])) { $f[] = "$k=?"; $p[] = clean((string)$b[$k]); }
    if (isset($b['fee']))          { $f[] = 'fee=?';          $p[] = (float)$b['fee']; }
    if (isset($b['max_students'])) { $f[] = 'max_students=?'; $p[] = (int)$b['max_students']; }
    if (!$f) fail('Nothing to update.');
    $p[] = $id;
    $pdo->prepare('UPDATE courses SET '.implode(',',$f).' WHERE id=?')->execute($p);
    logAct($user['id'], 'COURSE_UPDATED', "id:$id");
    ok([], 'Updated');
}

elseif ($act === 'delete') {
    if ($user['role'] !== 'Admin') fail('Admin only.', 403);
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $check = $pdo->prepare('SELECT COUNT(*) FROM certificates WHERE course_id=?');
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) fail('Cannot delete: certificates exist for this course.');
    $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$id]);
    logAct($user['id'], 'COURSE_DELETED', "id:$id");
    ok([], 'Deleted');
}

else fail('Unknown action.', 404);
