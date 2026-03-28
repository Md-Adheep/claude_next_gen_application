<?php
// attendance.php
// GET  ?action=list    &course_id=X&session_date=Y&session=Z
// GET  ?action=summary &course_id=X&student_id=Y
// POST ?action=save    {course_id, session_date, session, records:[{student_id,status,note}]}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
cors();

$user = needAuth();
$act  = $_GET['action'] ?? '';
$b    = body();
$pdo  = db();

/* LIST — get attendance for a course+date+session ──────── */
if ($act === 'list') {
    $cid  = (int)($_GET['course_id']    ?? $b['course_id']    ?? 0);
    $date = $_GET['session_date'] ?? $b['session_date'] ?? '';
    $sess = $_GET['session']      ?? $b['session']      ?? '';

    if (!$cid || !$date) fail('course_id and session_date required.');

    $w = ['a.course_id=?', 'a.session_date=?'];
    $p = [$cid, $date];
    if ($sess) { $w[] = 'a.session=?'; $p[] = $sess; }

    $st = $pdo->prepare(
        'SELECT a.student_id, a.status, a.note, a.session,
                CONCAT(s.first_name," ",s.last_name) AS student_name,
                s.email
         FROM attendance a
         JOIN students s ON a.student_id = s.id
         WHERE ' . implode(' AND ', $w) . '
         ORDER BY student_name'
    );
    $st->execute($p);
    ok($st->fetchAll());
}

/* SUMMARY — overall attendance % per student for a course */
elseif ($act === 'summary') {
    $cid = (int)($_GET['course_id'] ?? $b['course_id'] ?? 0);
    $sid = (int)($_GET['student_id'] ?? $b['student_id'] ?? 0);
    if (!$cid) fail('course_id required.');

    $w = ['course_id=?']; $p = [$cid];
    if ($sid) { $w[] = 'student_id=?'; $p[] = $sid; }

    $st = $pdo->prepare(
        'SELECT student_id,
                COUNT(*) AS total_days,
                SUM(status="Present") AS present_days,
                SUM(status="Absent")  AS absent_days,
                SUM(status="Late")    AS late_days,
                COALESCE(ROUND(SUM(status="Present")/NULLIF(COUNT(*),0)*100), 0) AS attendance_pct
         FROM attendance
         WHERE ' . implode(' AND ', $w) . '
         GROUP BY student_id'
    );
    $st->execute($p);
    ok($st->fetchAll());
}

/* SAVE — upsert attendance for a session ───────────────── */
elseif ($act === 'save') {
    $cid     = (int)($b['course_id']    ?? 0);
    $date    = $b['session_date'] ?? '';
    $session = $b['session']      ?? 'Full Day';
    $records = $b['records']      ?? [];

    if (!$cid || !$date)    fail('course_id and session_date required.');
    if (!is_array($records)) fail('records must be an array.');

    $validStatuses = ['Present', 'Absent', 'Late'];
    $validSessions = ['Morning', 'Afternoon', 'Evening', 'Full Day'];
    if (!in_array($session, $validSessions)) fail('Invalid session.');

    $stmt = $pdo->prepare(
        'INSERT INTO attendance (student_id, course_id, session_date, session, status, note, marked_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           status     = VALUES(status),
           note       = VALUES(note),
           marked_by  = VALUES(marked_by),
           updated_at = NOW()'
    );

    $saved = 0;
    foreach ($records as $rec) {
        $sid    = (int)($rec['student_id'] ?? 0);
        $status = $rec['status'] ?? '';
        $note   = clean($rec['note'] ?? '');
        if (!$sid || !in_array($status, $validStatuses)) continue;
        $stmt->execute([$sid, $cid, $date, $session, $status, $note, $user['id']]);
        $saved++;
    }

    logAct($user['id'], 'ATTENDANCE_SAVED', "course:$cid date:$date session:$session count:$saved");
    ok(['saved' => $saved], "$saved attendance records saved.");
}

else fail('Unknown action.', 404);
