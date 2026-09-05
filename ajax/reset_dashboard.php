<?php
// ajax/reset_dashboard.php — Manual daily dashboard reset for current user
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$uid = getUserId();

// Auto-create the resets table if it doesn't exist
$conn->query("
    CREATE TABLE IF NOT EXISTS user_dashboard_resets (
        user_id   INT PRIMARY KEY,
        reset_at  DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )
");

// Upsert: insert or update the reset timestamp for this user
// Uses PHP's clock (not MySQL's NOW()) to match renamed_at/validated_at/
// scanned_at elsewhere in the app — if PHP and MySQL are on different
// timezones, mixing NOW() here with date() everywhere else silently
// breaks every "since last reset" comparison on the dashboard.
$nowTimestamp = date('Y-m-d H:i:s');
$stmt = $conn->prepare("
    INSERT INTO user_dashboard_resets (user_id, reset_at)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE reset_at = ?
");
$stmt->bind_param("iss", $uid, $nowTimestamp, $nowTimestamp);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Dashboard reset successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Reset failed: ' . $conn->error]);
}
$stmt->close();
