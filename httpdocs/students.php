<?php
// /students.php
// GET  ?action=list        [status, search, course_id]
// GET  ?action=get         id
// POST ?action=create      {first_name,last_name,email,phone,gender,dob,nationality,
//                           qualification,address_line1,address_line2,city,state,pincode,
//                           id_number,occupation,company,experience_yrs,known_skills,
//                           prior_certs,referral_source,notes,
//                           courses:[{course_id,mode,start_date}]}
// POST ?action=update      {id,...}
// POST ?action=approve     {id}  — Admin
// POST ?action=reject      {id}  — Admin
// POST ?action=complete    {id}  — Admin
// POST ?action=delete      {id}  — Admin
// POST ?action=progress    {enrollment_id, progress}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
cors();

$user = needAuth();
$act  = $_GET['action'] ?? '';
$b    = body();
$pdo  = db();

/* LIST ───────────────────────────────────────────────────── */
if ($act === 'list') {
    $w = ['1=1']; $p = [];
    if (!empty($b['status'])) { $w[] = 's.status=?'; $p[] = $b['status']; }
    if (!empty($b['search'])) {
        $like = '%'.$b['search'].'%';
        $w[]  = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    if (!empty($b['course_id'])) {
        $w[] = 'EXISTS(SELECT 1 FROM enrollments e WHERE e.student_id=s.id AND e.course_id=?)';
        $p[] = (int)$b['course_id'];
    }
    // Trainers only see their own students
    if ($user['role'] === 'User') { $w[] = 's.submitted_by=?'; $p[] = $user['id']; }

    $st = $pdo->prepare(
        'SELECT s.id, CONCAT(s.first_name," ",s.last_name) AS name,
                s.first_name, s.last_name, s.email, s.phone, s.city,
                s.status, s.registered_at,
                u.name AS submitted_by_name,
                (SELECT GROUP_CONCAT(c.name SEPARATOR ", ")
                 FROM enrollments e JOIN courses c ON e.course_id=c.id
                 WHERE e.student_id=s.id) AS courses,
                COALESCE((SELECT AVG(e.progress) FROM enrollments e WHERE e.student_id=s.id),0) AS avg_progress
         FROM students s
         LEFT JOIN users u ON s.submitted_by=u.id
         WHERE '.implode(' AND ',$w).' ORDER BY s.registered_at DESC'
    );
    $st->execute($p);
    ok($st->fetchAll());
}

/* GET ONE ───────────────────────────────────────────────── */
elseif ($act === 'get') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $st = $pdo->prepare('SELECT s.*, CONCAT(s.first_name," ",s.last_name) AS name, u.name AS submitted_by_name FROM students s LEFT JOIN users u ON s.submitted_by=u.id WHERE s.id=?');
    $st->execute([$id]);
    $row = $st->fetch(); if (!$row) fail('Not found.', 404);

    $en = $pdo->prepare('SELECT e.*,c.name AS course_name,c.emoji,c.color FROM enrollments e JOIN courses c ON e.course_id=c.id WHERE e.student_id=?');
    $en->execute([$id]);
    $row['enrollments'] = $en->fetchAll();
    ok($row);
}

/* CREATE ─────────────────────────────────────────────────── */
elseif ($act === 'create') {
    if (empty($b['first_name'])) fail('First name required.');
    if (empty($b['last_name']))  fail('Last name required.');
    $email = filter_var(trim($b['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) fail('Valid email required.');

    $dup = $pdo->prepare('SELECT id FROM students WHERE email=? LIMIT 1');
    $dup->execute([$email]);
    if ($dup->fetch()) fail('Student with this email already exists.');

    $pdo->prepare(
        'INSERT INTO students(first_name,last_name,email,phone,gender,dob,nationality,
                              qualification,address_line1,address_line2,city,state,pincode,
                              id_number,occupation,company,experience_yrs,known_skills,
                              prior_certs,referral_source,notes,submitted_by,status)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        clean($b['first_name']),   clean($b['last_name']),     $email,
        clean($b['phone'] ?? ''),  $b['gender'] ?? null,
        !empty($b['dob']) ? $b['dob'] : null,
        clean($b['nationality']    ?? ''), clean($b['qualification'] ?? ''),
        clean($b['address_line1']  ?? ''), clean($b['address_line2'] ?? ''),
        clean($b['city']           ?? ''), clean($b['state']         ?? ''),
        clean($b['pincode']        ?? ''), clean($b['id_number']      ?? ''),
        clean($b['occupation']     ?? ''), clean($b['company']        ?? ''),
        (int)($b['experience_yrs'] ?? 0),
        clean($b['known_skills']   ?? ''), clean($b['prior_certs']    ?? ''),
        clean($b['referral_source']?? ''), clean($b['notes']          ?? ''),
        $user['id'], 'Pending',
    ]);
    $sid = $pdo->lastInsertId();

    // Enrollments
    if (!empty($b['courses']) && is_array($b['courses'])) {
        $en = $pdo->prepare('INSERT INTO enrollments(student_id,course_id,mode,start_date) VALUES(?,?,?,?)');
        foreach ($b['courses'] as $c) {
            if (empty($c['course_id'])) continue;
            $en->execute([$sid, (int)$c['course_id'], $c['mode'] ?? 'Offline',
                          !empty($c['start_date']) ? $c['start_date'] : null]);
        }
    }

    logAct($user['id'], 'STUDENT_CREATED', "id:$sid");
    ok(['id' => $sid], 'Student registered. Pending admin approval.');
}

/* UPDATE ─────────────────────────────────────────────────── */
elseif ($act === 'update') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $fields = ['first_name','last_name','phone','gender','dob','nationality','qualification',
                'address_line1','address_line2','city','state','pincode','id_number',
                'occupation','company','experience_yrs','known_skills','prior_certs',
                'referral_source','notes'];
    $f = []; $p = [];
    foreach ($fields as $k) if (isset($b[$k])) {
        $f[] = "$k=?";
        $p[] = $k === 'experience_yrs' ? (int)$b[$k] : clean((string)$b[$k]);
    }
    if (!empty($b['email'])) {
        $e = filter_var(trim($b['email']), FILTER_VALIDATE_EMAIL); if (!$e) fail('Invalid email.');
        $f[] = 'email=?'; $p[] = $e;
    }
    if (!$f) fail('Nothing to update.');
    $p[] = $id;
    $pdo->prepare('UPDATE students SET '.implode(',',$f).' WHERE id=?')->execute($p);
    logAct($user['id'], 'STUDENT_UPDATED', "id:$id");
    ok([], 'Updated');
}

/* STATUS CHANGES — Admin only ────────────────────────────── */
elseif (in_array($act, ['approve','reject','complete'])) {
    if ($user['role'] !== 'Admin') fail('Admin only.', 403);
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $map = ['approve' => 'Approved', 'reject' => 'Rejected', 'complete' => 'Completed'];
    $pdo->prepare('UPDATE students SET status=? WHERE id=?')->execute([$map[$act], $id]);
    logAct($user['id'], 'STUDENT_'.strtoupper($map[$act]), "id:$id");
    ok([], 'Student '.$map[$act]);
}

/* DELETE — Admin only ─────────────────────────────────────── */
elseif ($act === 'delete') {
    if ($user['role'] !== 'Admin') fail('Admin only.', 403);
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$id]);
    logAct($user['id'], 'STUDENT_DELETED', "id:$id");
    ok([], 'Deleted');
}

/* UPDATE PROGRESS ─────────────────────────────────────────── */
elseif ($act === 'progress') {
    $eid  = (int)($b['enrollment_id'] ?? 0); if (!$eid) fail('enrollment_id required.');
    $prog = max(0, min(100, (int)($b['progress'] ?? 0)));
    $st   = $prog >= 100 ? 'Completed' : 'Active';
    $pdo->prepare('UPDATE enrollments SET progress=?,status=? WHERE id=?')->execute([$prog, $st, $eid]);
    ok([], 'Progress updated');
}

else fail('Unknown action.', 404);
