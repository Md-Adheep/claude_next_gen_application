<?php
// api/certificates.php  — Admin only
// GET  ?action=list     [search, delivery_status]
// GET  ?action=get      id
// POST ?action=generate {student_id,course_id,cert_type,grade,issue_date,
//                        organisation,org_unit,country,state,city,director_name,custom_message}
// POST ?action=send     {id}
// POST ?action=delete   {id}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
cors();

$admin = needAdmin();
$act   = $_GET['action'] ?? '';
$b     = body();
$pdo   = db();

/* LIST ───────────────────────────────────────────────────── */
if ($act === 'list') {
    $w = ['1=1']; $p = [];
    if (!empty($b['delivery_status'])) { $w[] = 'cert.delivery_status=?'; $p[] = $b['delivery_status']; }
    if (!empty($b['search'])) {
        $like = '%'.$b['search'].'%';
        $w[]  = '(CONCAT(s.first_name," ",s.last_name) LIKE ? OR c.name LIKE ? OR cert.id LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    $st = $pdo->prepare(
        'SELECT cert.id, cert.cert_type, cert.grade, cert.issue_date,
                cert.delivery_status, cert.sent_at, cert.created_at,
                CONCAT("CERT-",LPAD(cert.id,6,"0")) AS cert_code,
                CONCAT(s.first_name," ",s.last_name) AS student_name,
                s.email AS student_email,
                c.name AS course_name
         FROM certificates cert
         JOIN students s ON cert.student_id=s.id
         JOIN courses  c ON cert.course_id=c.id
         WHERE '.implode(' AND ',$w).' ORDER BY cert.created_at DESC'
    );
    $st->execute($p);
    ok($st->fetchAll());
}

/* GET ONE ───────────────────────────────────────────────── */
elseif ($act === 'get') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $st = $pdo->prepare(
        'SELECT cert.*, CONCAT("CERT-",LPAD(cert.id,6,"0")) AS cert_code,
                CONCAT(s.first_name," ",s.last_name) AS student_name,
                s.email AS student_email, c.name AS course_name,
                u.name AS issued_by_name
         FROM certificates cert
         JOIN students s ON cert.student_id=s.id
         JOIN courses  c ON cert.course_id=c.id
         LEFT JOIN users u ON cert.issued_by=u.id
         WHERE cert.id=?'
    );
    $st->execute([$id]);
    $row = $st->fetch(); if (!$row) fail('Not found.', 404);
    ok($row);
}

/* GENERATE ──────────────────────────────────────────────── */
elseif ($act === 'generate') {
    foreach (['student_id','course_id','issue_date'] as $f)
        if (empty($b[$f])) fail("$f required.");

    $sid = (int)$b['student_id'];
    $cid = (int)$b['course_id'];

    // Validate student & course exist
    $sc = $pdo->prepare('SELECT id FROM students WHERE id=?'); $sc->execute([$sid]);
    if (!$sc->fetch()) fail('Student not found.');
    $cc = $pdo->prepare('SELECT id FROM courses WHERE id=?');  $cc->execute([$cid]);
    if (!$cc->fetch()) fail('Course not found.');

    // Prevent duplicate
    $dup = $pdo->prepare('SELECT id FROM certificates WHERE student_id=? AND course_id=? LIMIT 1');
    $dup->execute([$sid, $cid]);
    if ($dup->fetch()) fail('Certificate already issued for this student & course.');

    $pdo->prepare(
        'INSERT INTO certificates(student_id,course_id,cert_type,grade,issue_date,
                                  organisation,org_unit,country,state,city,
                                  director_name,custom_message,issued_by)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $sid, $cid,
        clean($b['cert_type']      ?? 'Certificate of Completion'),
        clean($b['grade']          ?? 'Pass'),
        $b['issue_date'],
        clean($b['organisation']   ?? 'NextGen Technologies'),
        clean($b['org_unit']       ?? ''),
        clean($b['country']        ?? 'IN'),
        clean($b['state']          ?? ''),
        clean($b['city']           ?? ''),
        clean($b['director_name']  ?? ''),
        clean($b['custom_message'] ?? ''),
        $admin['id'],
    ]);
    $newId = $pdo->lastInsertId();

    // Mark enrollment & student completed
    $pdo->prepare('UPDATE enrollments SET status="Completed",progress=100 WHERE student_id=? AND course_id=?')->execute([$sid,$cid]);
    $pdo->prepare('UPDATE students SET status="Completed" WHERE id=? AND status IN ("Pending","Approved")')->execute([$sid]);

    logAct($admin['id'], 'CERT_GENERATED', "cert:$newId student:$sid");
    ok(['id' => $newId, 'cert_code' => 'CERT-'.str_pad($newId,6,'0',STR_PAD_LEFT)], 'Certificate generated');
}

/* SEND ──────────────────────────────────────────────────── */
elseif ($act === 'send') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');

    // Fetch full cert + student email + course
    $st = $pdo->prepare(
        'SELECT cert.*, CONCAT("CERT-",LPAD(cert.id,6,"0")) AS cert_code,
                CONCAT(s.first_name," ",s.last_name) AS student_name,
                s.email AS student_email,
                c.name AS course_name
         FROM certificates cert
         JOIN students s ON cert.student_id = s.id
         JOIN courses  c ON cert.course_id  = c.id
         WHERE cert.id = ?'
    );
    $st->execute([$id]);
    $cert = $st->fetch();
    if (!$cert) fail('Certificate not found.');
    if ($cert['delivery_status'] === 'Sent') fail('Certificate already sent to ' . $cert['student_email'] . '.');

    $toEmail = trim($cert['student_email']);
    if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        fail('Student email address is missing or invalid. Update the student profile first.');
    }

    $studentName = htmlspecialchars($cert['student_name']);
    $courseName  = htmlspecialchars($cert['course_name']);
    $certCode    = htmlspecialchars($cert['cert_code']);
    $issueDate   = date('d M Y', strtotime($cert['issue_date']));
    $grade       = htmlspecialchars($cert['grade'] ?? 'Pass');
    $certType    = htmlspecialchars($cert['cert_type'] ?? 'Certificate of Completion');
    $org         = htmlspecialchars($cert['organisation'] ?? 'NextGen Technologies');
    $director    = htmlspecialchars($cert['director_name'] ?? 'Director');
    // Strip newlines from values used in email headers to prevent header injection
    $orgHeader   = str_replace(["\r", "\n"], '', $cert['organisation'] ?? 'NextGen Technologies');

    // Build certificate HTML email
    $certHtml = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#F4F0FB;font-family:Georgia,serif;">
<div style="max-width:640px;margin:30px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.12);">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#7C3AED,#8B5CF6);padding:28px 36px;text-align:center;">
    <div style="font-size:36px;margin-bottom:8px;">🎓</div>
    <div style="color:#fff;font-family:sans-serif;font-size:13px;letter-spacing:0.15em;text-transform:uppercase;opacity:0.85;">NextGen Technologies</div>
    <div style="color:#fff;font-family:sans-serif;font-size:22px;font-weight:700;margin-top:4px;">Certificate Issued</div>
  </div>

  <!-- Greeting -->
  <div style="padding:30px 36px 10px;">
    <p style="font-family:sans-serif;font-size:15px;color:#374151;">Dear <strong>' . $studentName . '</strong>,</p>
    <p style="font-family:sans-serif;font-size:14px;color:#6B7280;line-height:1.7;margin-top:8px;">
      Congratulations! We are delighted to inform you that you have successfully completed the training program.
      Your certificate of completion is detailed below.
    </p>
  </div>

  <!-- Certificate Box -->
  <div style="margin:16px 36px 24px;background:linear-gradient(135deg,#fffdf0,#fff9e6);border:3px solid #D97706;border-radius:14px;padding:30px 36px;text-align:center;position:relative;">
    <div style="position:absolute;inset:6px;border:1px dashed rgba(217,119,6,0.3);border-radius:10px;pointer-events:none;"></div>

    <div style="font-size:28px;margin-bottom:6px;">🏆</div>
    <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#92400E;font-family:sans-serif;font-weight:700;margin-bottom:14px;">' . $org . '</div>

    <div style="font-size:26px;font-weight:700;color:#B45309;letter-spacing:0.04em;font-family:Georgia,serif;">Certificate</div>
    <div style="font-size:11px;letter-spacing:0.2em;text-transform:uppercase;color:#AAA;font-family:sans-serif;margin-bottom:16px;">' . $certType . '</div>

    <div style="width:60px;height:2px;background:linear-gradient(90deg,transparent,#D97706,transparent);margin:0 auto 14px;"></div>

    <div style="font-size:11px;color:#6B7280;font-family:sans-serif;margin-bottom:6px;">This is to certify that</div>
    <div style="font-size:28px;font-style:italic;color:#1C1917;font-weight:700;border-bottom:2px solid #D97706;padding-bottom:8px;display:inline-block;margin-bottom:12px;">' . $studentName . '</div>

    <div style="font-size:12px;color:#6B7280;font-family:sans-serif;margin-bottom:8px;">has successfully completed</div>
    <div style="font-size:16px;font-weight:700;color:#92400E;margin-bottom:4px;font-family:sans-serif;">' . $courseName . '</div>
    <div style="font-size:11px;color:#9CA3AF;font-family:sans-serif;margin-bottom:20px;">with ' . $grade . '</div>

    <div style="display:flex;justify-content:space-between;padding-top:16px;border-top:1px solid rgba(217,119,6,0.25);">
      <div style="text-align:center;">
        <div style="width:70px;height:1px;background:#9CA3AF;margin:0 auto 4px;"></div>
        <div style="font-size:9.5px;font-weight:700;color:#374151;font-family:sans-serif;">' . $director . '</div>
        <div style="font-size:9px;color:#9CA3AF;font-family:sans-serif;">' . $org . '</div>
      </div>
      <div style="text-align:center;">
        <div style="font-size:9.5px;color:#9CA3AF;font-family:sans-serif;">' . $issueDate . '</div>
        <div style="width:70px;height:1px;background:#9CA3AF;margin:6px auto 4px;"></div>
        <div style="font-size:9.5px;font-weight:700;color:#374151;font-family:sans-serif;">Head of Training</div>
      </div>
    </div>

    <div style="margin-top:12px;font-size:9px;color:#D1D5DB;font-family:monospace;">' . $certCode . '</div>
  </div>

  <!-- Footer -->
  <div style="background:#F9FAFB;padding:20px 36px;text-align:center;border-top:1px solid #E5E7EB;">
    <p style="font-family:sans-serif;font-size:12px;color:#9CA3AF;line-height:1.7;margin:0;">
      This certificate was issued by <strong>' . $org . '</strong>.<br>
      Certificate ID: <code>' . $certCode . '</code> | Issued: ' . $issueDate . '<br>
      If you have any questions, please contact us.
    </p>
  </div>
</div>
</body>
</html>';

    $subject = "Your Certificate of Completion — " . $courseName . " | " . $org;
    $result  = sendMail($toEmail, $cert['student_name'], $subject, $certHtml);

    if (!$result['ok']) {
        // Mark as Failed so admin can see it and retry after fixing SMTP settings
        $pdo->prepare('UPDATE certificates SET delivery_status="Failed" WHERE id=?')->execute([$id]);
        fail('Email could not be sent to ' . $toEmail . '. ' . $result['error'] . ' — Go to Settings and configure SMTP.');
    }

    $pdo->prepare('UPDATE certificates SET delivery_status="Sent", sent_at=NOW() WHERE id=?')->execute([$id]);
    logAct($admin['id'], 'CERT_SENT', "cert:$id to:" . $toEmail);
    ok(['email' => $toEmail], 'Certificate sent to ' . $toEmail . ' successfully!');
}

/* DELETE ─────────────────────────────────────────────────── */
elseif ($act === 'delete') {
    $id = (int)($b['id'] ?? 0); if (!$id) fail('ID required.');
    $pdo->prepare('DELETE FROM certificates WHERE id=?')->execute([$id]);
    logAct($admin['id'], 'CERT_DELETED', "cert:$id");
    ok([], 'Deleted');
}

else fail('Unknown action.', 404);
 
