<?php
require_once '../includes/auth.php';
requireLoginRoot();
if ((int)getUserRoleId() !== 1) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit(); }

// Scope to the same "Reset Dashboard" cutoff used on dashboard.php, so this
// live-refreshing table also goes back to 0 right after a reset instead of
// keeping stale all-time numbers.
$uid = getUserId();
$conn->query("
    CREATE TABLE IF NOT EXISTS user_dashboard_resets (
        user_id   INT PRIMARY KEY,
        reset_at  DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )
");
$resetRow   = $conn->query("SELECT reset_at FROM user_dashboard_resets WHERE user_id = $uid")->fetch_row();
$lastReset  = $resetRow ? $resetRow[0] : null;
$todayStart = date('Y-m-d') . ' 00:00:00';
$cutoff     = ($lastReset && $lastReset > $todayStart) ? $lastReset : $todayStart;
$cutoffSql  = $conn->real_escape_string($cutoff);

$result = $conn->query("SELECT u.user_id, u.full_name, u.avatar_color, r.role_name, COUNT(DISTINCT COALESCE(d_scan.folder_id, d_ren.folder_id)) AS folders_browsed, COUNT(DISTINCT d_scan.document_id) AS total_files_in_folders, COUNT(DISTINCT d_ren.document_id) AS files_renamed FROM users u LEFT JOIN roles r ON r.role_id=u.role_id LEFT JOIN documents d_scan ON d_scan.scanned_by=u.user_id AND d_scan.scanned_at >= '$cutoffSql' LEFT JOIN documents d_ren ON d_ren.renamed_by=u.user_id AND d_ren.renamed_at >= '$cutoffSql' WHERE u.status='active' AND u.role_id=2 GROUP BY u.user_id, u.full_name, u.avatar_color, r.role_name ORDER BY files_renamed DESC, folders_browsed DESC");
$rows = [];
while ($row = $result->fetch_assoc()) { $rows[] = ['user_id'=>(int)$row['user_id'],'full_name'=>$row['full_name'],'avatar_color'=>$row['avatar_color'] ?? '#1e4db7','role_name'=>$row['role_name'] ?? 'Scanner','folders_browsed'=>(int)$row['folders_browsed'],'total_files_in_folders'=>(int)$row['total_files_in_folders'],'files_renamed'=>(int)$row['files_renamed']]; }
header('Content-Type: application/json');
echo json_encode($rows);