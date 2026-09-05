<?php
require_once __DIR__ . '/../includes/config.php';

// Disable foreign key checks temporarily if needed, though CASCADE/SET NULL will handle it safely.
$conn->query("SET FOREIGN_KEY_CHECKS=0");

echo "Cleaning up duplicates...\n";
$cleanupSql = "
    DELETE d1 FROM documents d1
    INNER JOIN documents d2 
    ON d1.folder_id = d2.folder_id 
    AND d1.raw_filename = d2.raw_filename 
    AND d1.document_id > d2.document_id
";

if ($conn->query($cleanupSql)) {
    echo "Deleted duplicates successfully. Affected rows: " . $conn->affected_rows . "\n";
} else {
    echo "Error cleaning up duplicates: " . $conn->error . "\n";
}

echo "Adding UNIQUE index (folder_id, raw_filename)...\n";
$indexSql = "ALTER TABLE documents ADD UNIQUE INDEX uq_folder_file (folder_id, raw_filename)";
if ($conn->query($indexSql)) {
    echo "UNIQUE index added successfully.\n";
} else {
    // Check if it already exists
    if (strpos($conn->error, 'Duplicate key name') !== false) {
        echo "UNIQUE index already exists.\n";
    } else {
        echo "Error adding UNIQUE index: " . $conn->error . "\n";
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS=1");
