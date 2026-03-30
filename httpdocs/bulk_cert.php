<?php
// ============================================================
//  bulk_cert.php — Excel/CSV Bulk Certificate Sender
//  POST ?action=upload   — upload & preview Excel/CSV
//  POST ?action=send_all — generate + email all from session
//  GET  ?action=template — download sample CSV
// ============================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
cors();

$admin = needAdmin();
$act   = $_GET['action'] ?? '';
$pdo   = db();

/* ── TEMPLATE DOWNLOAD ───────────────────────────────────── */
if ($act === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="certificate_template.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    echo "name,email,course_name,grade,issue_date\n";
    echo "John Doe,john@example.com,Web Development,Distinction,2026-03-28\n";
    echo "Jane Smith,jane@example.com,Data Science,Pass,2026-03-28\n";
    exit;
}

/* ── UPLOAD & PARSE ──────────────────────────────────────── */
if ($act === 'upload') {
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['excel_file']['error'] ?? -1;
        fail('File upload failed (error code: ' . $errCode . '). Check PHP upload_max_filesize setting.');
    }
    $file = $_FILES['excel_file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['csv', 'xlsx'];
    if (!in_array($ext, $allowed)) fail('Only CSV and XLSX files are supported. XLS (old format) is not supported.');
    if ($file['size'] > 5 * 1024 * 1024) fail('File too large. Max 5MB.');

    // Detect if a row looks like the actual header row (has name + email columns)
    $nameAliases  = ['name','full name','student name','full_name','student_name','name of the student','name of student'];
    $emailAliases = ['email','email address','e-mail','email id','e-mail id','emailid','mail id','mail'];
    $looksLikeHeader = function(array $row) use ($nameAliases, $emailAliases): bool {
        $hasName = $hasEmail = false;
        foreach ($row as $cell) {
            $kl = strtolower(trim((string)$cell));
            if (in_array($kl, $nameAliases))  $hasName  = true;
            if (in_array($kl, $emailAliases)) $hasEmail = true;
        }
        return $hasName && $hasEmail;
    };

    $rows = [];
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        $header = null;
        while (($line = fgetcsv($handle)) !== false) {
            if (!$header) {
                // Skip title/merged rows until we find the real header
                if ($looksLikeHeader($line)) {
                    $header = array_map('trim', $line);
                }
                continue;
            }
            if (count($line) < 2) continue;
            $rows[] = array_combine($header, array_pad($line, count($header), ''));
        }
        fclose($handle);
    } elseif ($ext === 'xlsx') {
        // Parse XLSX manually (read zip → xl/worksheets/sheet1.xml)
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) fail('Cannot open XLSX file.');
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = simplexml_load_string($ssXml);
            foreach ($ss->si as $si) {
                $t = '';
                foreach ($si->r ?? [$si] as $r) {
                    $t .= (string)($r->t ?? '');
                }
                if (!$si->r) $t = (string)$si->t;
                $sharedStrings[] = $t;
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!$sheetXml) fail('Cannot read sheet data from XLSX.');
        $sheet  = simplexml_load_string($sheetXml);
        $header = null;
        foreach ($sheet->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                // Get column index from cell reference (e.g. "B3" → col 1)
                preg_match('/^([A-Z]+)/', (string)($cell['r'] ?? ''), $m);
                $colStr = $m[1] ?? '';
                $colIdx = 0;
                foreach (str_split($colStr) as $ch) {
                    $colIdx = $colIdx * 26 + (ord($ch) - 64);
                }
                $colIdx--; // 0-based

                $t   = (string)($cell['t'] ?? '');
                $val = (string)$cell->v;
                if ($t === 's') $val = $sharedStrings[(int)$val] ?? '';
                elseif ($t === 'inlineStr') $val = (string)($cell->is->t ?? '');
                $rowData[$colIdx] = trim($val);
            }
            if (!$header) {
                ksort($rowData);
                $potential = array_values($rowData);
                // Skip title rows until we find the real header row
                if ($looksLikeHeader($potential)) {
                    $header = $potential;
                }
                continue;
            }
            if (count(array_filter($rowData)) === 0) continue;
            $combined = [];
            foreach ($header as $i => $h) {
                $combined[trim(strtolower($h))] = $rowData[$i] ?? '';
            }
            $rows[] = $combined;
        }
    }

    if (empty($rows)) fail('No data found in file. Check the format.');

    // Normalize column names
    $normalized = [];
    foreach ($rows as $r) {
        $keys = array_keys($r);
        $map  = [];
        foreach ($keys as $k) {
            $kl = strtolower(trim($k));
            if (in_array($kl, ['name','full name','student name','full_name','student_name','name of the student','name of student'])) $map['name'] = $r[$k];
            elseif (in_array($kl, ['email','email address','e-mail','email id','e-mail id','emailid','mail id','mail'])) $map['email'] = $r[$k];
            elseif (in_array($kl, ['course','course name','course_name','program','workshop','subject'])) $map['course_name'] = $r[$k];
            elseif (in_array($kl, ['grade','result','mark','marks','score'])) $map['grade'] = $r[$k];
            elseif (in_array($kl, ['issue_date','date','issue date','issued_on','certificate date'])) $map['issue_date'] = $r[$k];
            elseif (in_array($kl, ['register number','register no','reg no','regno','roll no','roll number','reg_no','register_number','registration number'])) $map['reg_no'] = $r[$k];
            // skip s.no, phone number — not needed for certificate
        }
        // Validate required
        if (empty($map['name']) || empty($map['email'])) continue;
        if (!filter_var(trim($map['email']), FILTER_VALIDATE_EMAIL)) {
            $map['_error'] = 'Invalid email: ' . $map['email'];
        }
        $map['name']       = trim($map['name'] ?? '');
        $map['email']      = strtolower(trim($map['email'] ?? ''));
        $map['course_name']= trim($map['course_name'] ?? 'General Training');
        $map['grade']      = trim($map['grade'] ?? 'Pass');
        $map['issue_date'] = trim($map['issue_date'] ?? date('Y-m-d'));

        // Validate/format date
        $d = date_create($map['issue_date']);
        $map['issue_date'] = $d ? date_format($d, 'Y-m-d') : date('Y-m-d');

        $normalized[] = $map;
    }

    if (empty($normalized)) fail('No valid rows found. Ensure name and email columns exist.');

    startSess();
    $_SESSION['bulk_cert_rows'] = $normalized;
    ok(['rows' => $normalized, 'count' => count($normalized)], count($normalized) . ' records parsed successfully.');
}

/* ── SHARED: process one row into bulk_cert_records + send email ── */
function processBulkRow(array $row, PDO $pdo, array $globals, string $batchId, int $issuedBy): array {
    if (!empty($row['_error']) || !empty($row['_sent'])) {
        return ['name' => $row['name'] ?? '', 'email' => $row['email'] ?? '',
                'status' => 'skipped', 'message' => $row['_error'] ?? 'Already sent'];
    }

    $name  = trim($row['name']  ?? '');
    $email = strtolower(trim($row['email'] ?? ''));
    if (!$name || !$email) return ['name' => $name, 'email' => $email, 'status' => 'skipped', 'message' => 'Missing name or email'];

    $resolvedCourse   = ($row['course_name']    ?? '') ?: ($globals['course']    ?: 'General Training');
    $resolvedGrade    = ($row['grade']           ?? '') ?: ($globals['grade']     ?: 'Pass');
    $resolvedDate     = ($row['issue_date']      ?? '') ?: ($globals['date']      ?: date('Y-m-d'));
    $resolvedOrg      = ($row['org_name']        ?? '') ?: ($globals['org']       ?: 'NextGen Technologies');
    $resolvedDirector = ($row['director_name']   ?? '') ?: ($globals['director']  ?: 'Director');
    $resolvedCertType = ($row['cert_type']       ?? '') ?: ($globals['cert_type'] ?: 'Certificate of Completion');
    $rd = date_create($resolvedDate);
    $resolvedDate = $rd ? date_format($rd, 'Y-m-d') : date('Y-m-d');

    try {
        // Check duplicate in bulk_cert_records only (no students/courses/certificates touched)
        $dup = $pdo->prepare('SELECT id FROM bulk_cert_records WHERE student_email=? AND course_name=? AND batch_id=? LIMIT 1');
        $dup->execute([$email, $resolvedCourse, $batchId]);
        if ($dup->fetch()) {
            return ['name' => $name, 'email' => $email, 'status' => 'skipped', 'message' => 'Already sent in this batch'];
        }

        // Insert into bulk_cert_records
        $pdo->prepare(
            'INSERT INTO bulk_cert_records
             (batch_id,student_name,student_email,course_name,grade,issue_date,organisation,director_name,cert_type,issued_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$batchId, $name, $email, $resolvedCourse, $resolvedGrade, $resolvedDate,
                    $resolvedOrg, $resolvedDirector, $resolvedCertType, $issuedBy]);
        $recId   = $pdo->lastInsertId();
        $certCode = 'BC-' . str_pad($recId, 6, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE bulk_cert_records SET cert_code=? WHERE id=?')->execute([$certCode, $recId]);

        // Build email HTML
        $issueDate   = date('d M Y', strtotime($resolvedDate));
        $studentName = htmlspecialchars($name);
        $courseName  = htmlspecialchars($resolvedCourse);
        $grade       = htmlspecialchars($resolvedGrade);
        $org         = htmlspecialchars($resolvedOrg);
        $director    = htmlspecialchars($resolvedDirector);
        $ctype       = htmlspecialchars($resolvedCertType);

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#F4F0FB;font-family:Georgia,serif;">
<div style="max-width:640px;margin:30px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.12);">
  <div style="background:linear-gradient(135deg,#7C3AED,#8B5CF6);padding:28px 36px;text-align:center;">
    <div style="font-size:36px;margin-bottom:8px;">🎓</div>
    <div style="color:#fff;font-family:sans-serif;font-size:13px;letter-spacing:.15em;text-transform:uppercase;opacity:.85;">' . $org . '</div>
    <div style="color:#fff;font-family:sans-serif;font-size:22px;font-weight:700;margin-top:4px;">Certificate Issued</div>
  </div>
  <div style="padding:30px 36px 10px;">
    <p style="font-family:sans-serif;font-size:15px;color:#374151;">Dear <strong>' . $studentName . '</strong>,</p>
    <p style="font-family:sans-serif;font-size:14px;color:#6B7280;line-height:1.7;margin-top:8px;">
      Congratulations! You have successfully completed the training program. Your certificate is detailed below.
    </p>
  </div>
  <div style="margin:16px 36px 24px;background:linear-gradient(135deg,#fffdf0,#fff9e6);border:3px solid #D97706;border-radius:14px;padding:30px 36px;text-align:center;">
    <div style="font-size:28px;margin-bottom:6px;">🏆</div>
    <div style="font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:#92400E;font-family:sans-serif;font-weight:700;margin-bottom:14px;">' . $org . '</div>
    <div style="font-size:26px;font-weight:700;color:#B45309;font-family:Georgia,serif;">Certificate</div>
    <div style="font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:#AAA;font-family:sans-serif;margin-bottom:16px;">' . $ctype . '</div>
    <div style="width:60px;height:2px;background:linear-gradient(90deg,transparent,#D97706,transparent);margin:0 auto 14px;"></div>
    <div style="font-size:11px;color:#6B7280;font-family:sans-serif;margin-bottom:6px;">This is to certify that</div>
    <div style="font-size:28px;font-style:italic;color:#1C1917;font-weight:700;border-bottom:2px solid #D97706;padding-bottom:8px;display:inline-block;margin-bottom:12px;">' . $studentName . '</div>
    <div style="font-size:12px;color:#6B7280;font-family:sans-serif;margin-bottom:8px;">has successfully completed</div>
    <div style="font-size:16px;font-weight:700;color:#92400E;margin-bottom:4px;font-family:sans-serif;">' . $courseName . '</div>
    <div style="font-size:11px;color:#9CA3AF;font-family:sans-serif;margin-bottom:20px;">with ' . $grade . '</div>
    <div style="display:flex;justify-content:space-between;padding-top:16px;border-top:1px solid rgba(217,119,6,.25);">
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
  <div style="background:#F9FAFB;padding:20px 36px;text-align:center;border-top:1px solid #E5E7EB;">
    <p style="font-family:sans-serif;font-size:12px;color:#9CA3AF;line-height:1.7;margin:0;">
      Certificate ID: <code>' . $certCode . '</code> | Issued: ' . $issueDate . '<br>
      Issued by <strong>' . $org . '</strong>
    </p>
  </div>
</div></body></html>';

        $subject = "Your Certificate — {$resolvedCourse} | {$resolvedOrg}";
        $result  = sendMail($email, $name, $subject, $html);

        if ($result['ok']) {
            $pdo->prepare('UPDATE bulk_cert_records SET delivery_status="Sent", sent_at=NOW() WHERE id=?')->execute([$recId]);
            logAct($issuedBy, 'BULK_CERT_SENT', "bc:{$recId} to:{$email}");
            return ['name' => $name, 'email' => $email, 'status' => 'sent', 'cert_code' => $certCode, 'message' => 'Sent successfully'];
        } else {
            $pdo->prepare('UPDATE bulk_cert_records SET delivery_status="Failed" WHERE id=?')->execute([$recId]);
            return ['name' => $name, 'email' => $email, 'status' => 'failed', 'message' => $result['error']];
        }
    } catch (Throwable $e) {
        return ['name' => $name, 'email' => $email, 'status' => 'failed', 'message' => $e->getMessage()];
    }
}

/* ── SEND ALL ─────────────────────────────────────────────── */
elseif ($act === 'send_all') {
    startSess();
    $bodyRows = $b['rows'] ?? null;
    $rows = (!empty($bodyRows) && is_array($bodyRows)) ? $bodyRows : ($_SESSION['bulk_cert_rows'] ?? []);
    if (empty($rows)) fail('No data found. Upload file again.');

    $globals = [
        'org'       => clean($b['organisation']  ?? 'NextGen Technologies'),
        'director'  => clean($b['director_name'] ?? 'Director'),
        'cert_type' => clean($b['cert_type']     ?? 'Certificate of Completion'),
        'course'    => clean($b['course_name']   ?? ''),
        'grade'     => clean($b['grade']         ?? ''),
        'date'      => clean($b['issue_date']    ?? ''),
    ];
    $batchId = 'BATCH-' . date('YmdHis') . '-' . substr(md5(uniqid()), 0, 4);

    $results = [];
    foreach ($rows as $row) {
        $results[] = processBulkRow($row, $pdo, $globals, $batchId, $admin['id']);
    }

    unset($_SESSION['bulk_cert_rows']);

    $sent    = count(array_filter($results, fn($r) => $r['status'] === 'sent'));
    $failed  = count(array_filter($results, fn($r) => $r['status'] === 'failed'));
    $skipped = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));

    ok(['results' => $results, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped],
       "$sent sent, $failed failed, $skipped skipped");
}

/* ── SEND ONE ─────────────────────────────────────────────── */
elseif ($act === 'send_one') {
    $row = $b['row'] ?? null;
    if (empty($row) || !is_array($row)) fail('No row data provided.');

    $globals = [
        'org'       => clean($b['organisation']  ?? 'NextGen Technologies'),
        'director'  => clean($b['director_name'] ?? 'Director'),
        'cert_type' => clean($b['cert_type']     ?? 'Certificate of Completion'),
        'course'    => clean($b['course_name']   ?? ''),
        'grade'     => clean($b['grade']         ?? ''),
        'date'      => clean($b['issue_date']    ?? ''),
    ];
    $batchId = 'SINGLE-' . date('YmdHis');

    $result = processBulkRow($row, $pdo, $globals, $batchId, $admin['id']);

    if ($result['status'] === 'sent') {
        ok(['result' => $result], 'Certificate sent to ' . $result['name']);
    } else {
        fail($result['message']);
    }
}

else fail('Unknown action.', 404);
