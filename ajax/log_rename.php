<?php
require_once '../includes/auth.php';
requireLogin();
header('Content-Type: application/json');
$userId = getUserId();
$folderName      = clean($_POST['folder_name']      ?? '');
$rawFilename     = clean($_POST['raw_filename']      ?? '');
$renamedFilename = clean($_POST['renamed_filename']  ?? '');
$fileType        = clean($_POST['file_type']         ?? '');
$fileSize        = clean($_POST['file_size']         ?? '');
$phaseInput      = clean($_POST['phase']             ?? '');
$branchInput     = clean($_POST['branch']            ?? '');
$plotInput       = clean($_POST['plot']              ?? '');
$renameMeta      = isset($_POST['rename_meta']) ? trim($_POST['rename_meta']) : '';
$nowTimestamp    = date('Y-m-d H:i:s'); // same clock/timezone source as every other timestamp in the app

if (empty($folderName) || empty($rawFilename) || empty($renamedFilename)) {
    echo json_encode(['success'=>false,'message'=>'Missing required fields']);
    exit();
}

// ── Sanitize folder name for filesystem (keep letters, digits, spaces, hyphens, underscores, dots)
$safeFolderName = preg_replace('/[^A-Za-z0-9 _.\-]/', '_', $folderName);

// ── Build server upload path
$uploadDir  = __DIR__ . '/../uploads/' . $safeFolderName . '/';
$storagePath = 'uploads/' . $safeFolderName . '/' . $renamedFilename;

// ── Save file to server if binary was sent
if (!empty($_FILES['file_content']['tmp_name']) && $_FILES['file_content']['error'] === UPLOAD_ERR_OK) {
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $destPath = $uploadDir . $renamedFilename;
    if (!move_uploaded_file($_FILES['file_content']['tmp_name'], $destPath)) {
        echo json_encode(['success'=>false,'message'=>'Failed to save file to server: check uploads/ permissions']);
        exit();
    }
    // Use actual file size from upload
    $fileSize = $_FILES['file_content']['size'];
}

// ── Get or create folder record in DB
$stmt = $conn->prepare("SELECT folder_id FROM folders WHERE folder_name = ? LIMIT 1");
$stmt->bind_param("s", $folderName);
$stmt->execute();
$fRes = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($fRes) {
    $folderId = $fRes['folder_id'];
} else {
    $stmt = $conn->prepare("INSERT INTO folders (folder_name, created_by, status) VALUES (?, ?, 'active')");
    $stmt->bind_param("si", $folderName, $userId);
    if ($stmt->execute()) {
        $folderId = $stmt->insert_id;
    } else {
        echo json_encode(['success'=>false,'message'=>'Failed to create folder: ' . $conn->error]);
        exit();
    }
    $stmt->close();
}

// ── Check if document already exists in DB for this folder + raw filename
$stmt = $conn->prepare("SELECT document_id FROM documents WHERE folder_id = ? AND raw_filename = ? LIMIT 1");
$stmt->bind_param("is", $folderId, $rawFilename);
$stmt->execute();
$dRes = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($dRes) {
    // Update existing record and reset status to pending so it goes to verification queue
    $docId = $dRes['document_id'];
    $stmt = $conn->prepare("UPDATE documents SET renamed_filename = ?, storage_path = ?, file_size = ?, phase = ?, branch = ?, plot = ?, rename_meta = ?, renamed_by = ?, renamed_at = ?, status = 'pending' WHERE document_id = ?");
    $stmt->bind_param("sssssssisi", $renamedFilename, $storagePath, $fileSize, $phaseInput, $branchInput, $plotInput, $renameMeta, $userId, $nowTimestamp, $docId);
    $stmt->execute();
    $stmt->close();
    logActivity($conn, $userId, 'update', "Renamed document #$docId to '$renamedFilename'", $docId);
    echo json_encode(['success'=>true,'document_id'=>$docId]);
} else {
    // Insert new record
    $stmt = $conn->prepare("INSERT INTO documents (raw_filename, renamed_filename, file_type, file_size, storage_path, phase, branch, plot, rename_meta, folder_id, scanned_by, scanned_at, renamed_by, renamed_at, status, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'browse')");
    $stmt->bind_param("sssssssssiisss", $rawFilename, $renamedFilename, $fileType, $fileSize, $storagePath, $phaseInput, $branchInput, $plotInput, $renameMeta, $folderId, $userId, $nowTimestamp, $userId, $nowTimestamp);
    if ($stmt->execute()) {
        $docId = $stmt->insert_id;
        logActivity($conn, $userId, 'update', "Renamed and registered local document #$docId to '$renamedFilename'", $docId);
        echo json_encode(['success'=>true,'document_id'=>$docId]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Failed to register renamed file in DB: ' . $conn->error]);
    }
    $stmt->close();
}
