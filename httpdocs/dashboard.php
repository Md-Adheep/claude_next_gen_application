<?php
// /dashboard.php
// GET ?action=stats
// GET ?action=activity  [limit]

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
cors();

$user = needAuth();
$act  = $_GET['action'] ?? 'stats';
$pdo  = db();

if ($act === 'stats') {
    if ($user['role'] === 'Admin') {
        ok([
            'total_students'   => (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
            'pending_students' => (int)$pdo->query('SELECT COUNT(*) FROM students WHERE status="Pending"')->fetchColumn(),
            'active_users'     => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE status="Active"')->fetchColumn(),
            'total_courses'    => (int)$pdo->query('SELECT COUNT(*) FROM courses WHERE status="Active"')->fetchColumn(),
            'total_certs'      => (int)$pdo->query('SELECT COUNT(*) FROM certificates')->fetchColumn(),
            'certs_pending'    => (int)$pdo->query('SELECT COUNT(*) FROM certificates WHERE delivery_status="Pending"')->fetchColumn(),
        ]);
    } else {
        $uid = $user['id'];
        $q = fn(string $sql, array $p=[]) => (function() use($pdo,$sql,$p){ $st=$pdo->prepare($sql);$st->execute($p);return(int)$st->fetchColumn(); })();
        ok([
            'my_students' => $q('SELECT COUNT(*) FROM students WHERE submitted_by=?',[$uid]),
            'pending'     => $q('SELECT COUNT(*) FROM students WHERE submitted_by=? AND status="Pending"',[$uid]),
            'approved'    => $q('SELECT COUNT(*) FROM students WHERE submitted_by=? AND status="Approved"',[$uid]),
            'completed'   => $q('SELECT COUNT(*) FROM students WHERE submitted_by=? AND status="Completed"',[$uid]),
        ]);
    }
}

elseif ($act === 'activity') {
    $limit = min((int)($_GET['limit'] ?? 8), 50);
    $st = $pdo->prepare(
        'SELECT a.action,a.description,a.logged_at,u.name AS user_name
         FROM activity_log a LEFT JOIN users u ON a.user_id=u.id
         ORDER BY a.logged_at DESC LIMIT ?'
    );
    $st->execute([$limit]);
    ok($st->fetchAll());
}

else fail('Unknown action.', 404);
