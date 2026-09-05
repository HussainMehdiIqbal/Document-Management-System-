<?php
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');
$userId = getUserId();
$folderName = clean($_POST['folder_name'] ?? '');
$filesJson = $_POST['files'] ?? '';
if (empty($folderName)) { echo json_encode(['success'=>false,'message'=>'Folder name is required']); exit(); }
$stmt = $conn->prepare("SELECT folder_id FROM folders WHERE folder_name = ? LIMIT 1");
$stmt->bind_param("s", $folderName);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($res) {
    $folderId = $res['folder_id'];
} else {
    $stmt = $conn->prepare("INSERT INTO folders (folder_name, created_by, status) VALUES (?, ?, 'active')");
    $stmt->bind_param("si", $folderName, $userId);
    if ($stmt->execute()) {
        $folderId = $stmt->insert_id;
        logActivity($conn, $userId, 'create', "Created folder '$folderName' via browse", null);
    } else {
        echo json_encode(['success'=>false,'message'=>'Failed to create folder: ' . $conn->error]);
        exit();
    }
    $stmt->close();
}
if (!empty($filesJson)) {
    $files = json_decode($filesJson, true);
    if (is_array($files)) {
        foreach ($files as $f) {
            $rawName = clean($f['name'] ?? '');
            $size = clean($f['size'] ?? '0');
            $ext = clean($f['ext'] ?? '');
            if (empty($rawName)) continue;
            $stmt = $conn->prepare("SELECT document_id FROM documents WHERE folder_id = ? AND raw_filename = ? LIMIT 1");
            $stmt->bind_param("is", $folderId, $rawName);
            $stmt->execute();
            $dRes = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dRes) {
                $docIds[$rawName] = (int)$dRes['document_id'];
            } else {
                $storagePath = 'uploads/' . preg_replace('/[^A-Za-z0-9 _.-]/', '_', $folderName) . '/' . $rawName;
                $stmt = $conn->prepare("INSERT IGNORE INTO documents (raw_filename, file_type, file_size, storage_path, folder_id, scanned_by, scanned_at, status, source) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pending', 'browse')");
                $stmt->bind_param("ssssii", $rawName, $ext, $size, $storagePath, $folderId, $userId);
                $stmt->execute();
                $docIds[$rawName] = (int)$stmt->insert_id;
                $stmt->close();
            }
        }
    }
}
echo json_encode(['success'=>true,'folder_id'=>$folderId,'docIds'=>$docIds ?? new stdClass()]);
