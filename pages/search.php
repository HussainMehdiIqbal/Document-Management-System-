<?php
// ============================================================
// admin/ajax/search.php — Global Search AJAX API (dms_database)
// ============================================================
header('Content-Type: application/json');

require_once '../../includes/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized Access']);
    exit();
}

$query = clean($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit();
}

// Determine referer
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$inPages = (strpos($referer, '/pages/') !== false);
$prefix = $inPages ? '' : 'pages/';

$results = [];
$userRoleId = (int)getUserRoleId();
$userId = getUserId();
$searchQuery = "%" . $conn->real_escape_string($query) . "%";

// 1. Search Users (Admin only)
if ($userRoleId === 1) {
    $stmt = $conn->prepare("SELECT u.user_id, u.username, u.full_name, u.employee_id, r.role_name
                            FROM users u
                            LEFT JOIN roles r ON u.role_id = r.role_id
                            WHERE u.username LIKE ? OR u.full_name LIKE ? OR u.employee_id LIKE ? LIMIT 5");
    $stmt->bind_param("sss", $searchQuery, $searchQuery, $searchQuery);
    $stmt->execute();
    $userResult = $stmt->get_result();
    while ($user = $userResult->fetch_assoc()) {
        $results[] = [
            'title' => esc($user['full_name']),
            'subtitle' => 'ID: ' . esc($user['employee_id'] ?? 'N/A') . ' | @' . esc($user['username']) . ' | Role: ' . esc($user['role_name'] ?? 'None'),
            'url' => $prefix . 'users.php?search=' . urlencode($user['username']),
            'icon' => 'fa-user'
        ];
    }
    $stmt->close();
}

// 2. Search Documents (Role Scoped)
if ($userRoleId === 3) { // Validator
    $stmt = $conn->prepare("SELECT d.document_id, d.raw_filename, d.renamed_filename, d.doc_type, d.file_no, d.branch, d.status
                            FROM documents d
                            WHERE (d.document_id LIKE ? OR d.raw_filename LIKE ? OR d.renamed_filename LIKE ? OR d.doc_type LIKE ? OR d.file_no LIKE ? OR d.branch LIKE ?)
                              AND (d.status='pending' OR d.document_id IN (SELECT v.document_id FROM validations v WHERE v.validated_by = ?))
                            LIMIT 8");
    $stmt->bind_param("ssssssi", $searchQuery, $searchQuery, $searchQuery, $searchQuery, $searchQuery, $searchQuery, $userId);
} elseif ($userRoleId === 2) { // Operator
    $stmt = $conn->prepare("SELECT d.document_id, d.raw_filename, d.renamed_filename, d.doc_type, d.file_no, d.branch, d.status
                            FROM documents d
                            WHERE (d.document_id LIKE ? OR d.raw_filename LIKE ? OR d.renamed_filename LIKE ? OR d.doc_type LIKE ? OR d.file_no LIKE ? OR d.branch LIKE ?)
                              AND d.scanned_by = ?
                            LIMIT 8");
    $stmt->bind_param("ssssssi", $searchQuery, $searchQuery, $searchQuery, $searchQuery, $searchQuery, $searchQuery, $userId);
} else { // Admin
    $stmt = $conn->prepare("SELECT d.document_id, d.raw_filename, d.renamed_filename, d.doc_type, d.file_no, d.branch, d.status
                            FROM documents d
                            WHERE d.document_id LIKE ? OR d.raw_filename LIKE ? OR d.renamed_filename LIKE ? OR d.doc_type LIKE ? OR d.file_no LIKE ? OR d.branch LIKE ? LIMIT 8");
    $stmt->bind_param("ssssss", $searchQuery, $searchQuery, $searchQuery, $searchQuery, $searchQuery, $searchQuery);
}

$stmt->execute();
$docResult = $stmt->get_result();
while ($doc = $docResult->fetch_assoc()) {
    // Document ID is admin-only in the UI — operators/validators see this
    // same search box while on Rename/Verify, so keep it out of their results.
    $title = ($userRoleId === 1) ? '#' . esc($doc['document_id']) . ' - ' . esc($doc['doc_type'] ?? 'Doc')
                                  : esc($doc['doc_type'] ?? 'Doc');
    if (!empty($doc['renamed_filename'])) {
        $title .= ' (' . esc($doc['renamed_filename']) . ')';
    } else {
        $title .= ' (' . esc($doc['raw_filename']) . ')';
    }
    $results[] = [
        'title' => $title,
        'subtitle' => 'Branch: ' . esc($doc['branch'] ?? 'N/A') . ' | File No: ' . esc($doc['file_no'] ?? 'N/A') . ' | Status: ' . ($doc['status'] === 'changed' ? 'Validated after Correction' : ucfirst($doc['status'])),
        'url' => $prefix . 'validate.php?id=' . $doc['document_id'],
        'icon' => 'fa-file-alt'
    ];
}
$stmt->close();

echo json_encode(['results' => $results]);
exit();
?>
