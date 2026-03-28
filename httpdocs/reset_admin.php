<?php
// ============================================================
//  reset_admin.php — One-time admin password reset
//  Visit this page ONCE, then it disables itself automatically.
// ============================================================

// Self-disable after first run
$selfDisable = function() {
    file_put_contents(__FILE__, '<?php http_response_code(404); exit;');
};

require_once __DIR__ . '/db.php';

$newPassword = 'Admin@123';
$hash        = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    $pdo  = db();
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin' AND role = 'Admin'");
    $stmt->execute([$hash]);

    if ($stmt->rowCount() > 0) {
        $selfDisable();
        echo "<div style='font-family:sans-serif;padding:30px;background:#f0fdf4;border-left:4px solid #22c55e;'>";
        echo "<h2 style='color:#16a34a;'>Password Reset Successful</h2>";
        echo "<p><strong>Username:</strong> admin</p>";
        echo "<p><strong>Password:</strong> Admin@123</p>";
        echo "<p><strong>Hash used:</strong> <code style='font-size:12px;'>".htmlspecialchars($hash)."</code></p>";
        echo "<p style='color:#dc2626;font-weight:bold;'>This file has been disabled. You can now delete reset_admin.php.</p>";
        echo "<br><a href='login.html' style='background:#7C3AED;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;'>Go to Login</a>";
        echo "</div>";
    } else {
        echo "<div style='font-family:sans-serif;padding:30px;background:#fef2f2;border-left:4px solid #ef4444;'>";
        echo "<h2 style='color:#dc2626;'>No admin user found</h2>";
        echo "<p>Make sure you imported schema.sql and have a user with username <strong>admin</strong> and role <strong>Admin</strong>.</p>";
        echo "<p>Run in phpMyAdmin: <code>SELECT * FROM users WHERE role='Admin';</code></p>";
        echo "</div>";
    }
} catch (Exception $e) {
    echo "<div style='font-family:sans-serif;padding:30px;background:#fef2f2;border-left:4px solid #ef4444;'>";
    echo "<h2 style='color:#dc2626;'>Database Error</h2>";
    echo "<p>".htmlspecialchars($e->getMessage())."</p>";
    echo "</div>";
}
