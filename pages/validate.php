<?php
// ============================================================
// pages/validate.php — Validation Module (dms_database schema)
// ============================================================
require_once '../includes/auth.php';
requireLogin();

$pageTitle = 'Document Validation';

$message = '';
$msgType = 'success';
$userId  = getUserId();

// A warning set just before the Post/Redirect/Get below (e.g. the physical
// rename failing) would otherwise be silently discarded — this page's very
// next request is a fresh GET that resets $message to blank before anyone
// sees it. Restore it from the session flash if one was left behind.
if (isset($_SESSION['validate_flash_message'])) {
    $message = $_SESSION['validate_flash_message'];
    $msgType = $_SESSION['validate_flash_type'] ?? 'warning';
    unset($_SESSION['validate_flash_message'], $_SESSION['validate_flash_type']);
}

// ── Validate KPI — same formula as the Rename KPI: target 1000/day ─────
// Formula (as instructed): (count * 100) / target
// Corrected and Validated docs from Verify are both counted into this
// single KPI count — no separate breakdown, matching how Rename counts
// all renamed docs together.
// Also scoped to the shared "Reset Dashboard" cutoff instead of a hardcoded
// CURDATE() so pressing Reset actually zeroes it here too (previously this
// was the one KPI banner still keyed on DATE(validated_at) = CURDATE(),
// so Reset Dashboard zeroed the main dashboard's Validate KPI but left
// this tab's own banner showing the un-reset count).
$validateKpiTarget = 1000;
$validateKpiCount  = 0;
$validateKpiCutoff = getDashboardResetCutoff($conn, $userId);
$vKpiStmt = $conn->prepare("SELECT COUNT(*) FROM validations WHERE validated_by = ? AND validated_at >= ?");
if ($vKpiStmt) {
    $vKpiStmt->bind_param("is", $userId, $validateKpiCutoff);
    $vKpiStmt->execute();
    $validateKpiCount = (int)$vKpiStmt->get_result()->fetch_row()[0];
    $vKpiStmt->close();
}
$validateKpiPercent          = ($validateKpiCount * 100) / $validateKpiTarget;
$validateKpiPercentFormatted = number_format($validateKpiPercent, 1);
$validateKpiPercentWidth     = min(100, $validateKpiPercent);

// documentsHasRenameMetaColumn() now lives in includes/config.php, shared
// with rename.php and ajax/log_rename.php, which need the same guard.

/**
 * Populates the documents table's report columns (doc_type, phase, plot,
 * file_no, doc_year, branch) after a document is approved.
 *
 * IMPORTANT: When posted metadata (from the Verify Workflow form) is
 * available, we ALWAYS use it directly — it is the exact data the
 * validator saw and edited on screen, so there is nothing to "guess".
 *
 * When posted metadata is NOT available (rare/legacy path), we now prefer
 * the JSON snapshot saved in documents.rename_meta at rename time, and
 * only fall back to naive filename-splitting as a last resort for very
 * old records that predate the rename_meta column. Naive splitting is
 * unreliable because CLASS NAME / FILE NAME are free text and can
 * themselves contain "_", which shifts every field after them.
 */
function parseAndPopulateMetadata($conn, $docId, $renamedFilename, $folderName, $postedClass = '', $postedPH = '', $postedPlot = '', $postedFileName = '', $postedDate = '', $postedSEC = '') {
    if (empty($renamedFilename)) return;

    if (!empty($postedClass)) {
        $docType = $postedClass;
        $ph = $postedPH;
        $plot = $postedPlot;
        $fileName = $postedFileName;

        $year = date('Y');
        if (!empty($postedDate)) {
            // Posted date is dd-mm-yyyy from the Verify Workflow form.
            $dateParts = explode('-', str_replace('/', '-', $postedDate));
            if (count($dateParts) === 3) {
                $year = $dateParts[2];
            }
        }

        $branch = $postedSEC;
        if (empty($branch)) {
            $branch = 'Lahore';
            if (stripos($folderName, 'Karachi') !== false) {
                $branch = 'Karachi';
            } elseif (stripos($folderName, 'Islamabad') !== false) {
                $branch = 'Islamabad';
            } elseif (stripos($folderName, 'Multan') !== false) {
                $branch = 'Multan';
            }
        }
    } else {
        // No posted metadata — try the exact snapshot saved at rename time first.
        $meta = null;
        if (documentsHasRenameMetaColumn($conn)) {
            $q = $conn->prepare("SELECT rename_meta, phase, branch, plot FROM documents WHERE document_id = ? LIMIT 1");
        } else {
            $q = $conn->prepare("SELECT phase, branch, plot FROM documents WHERE document_id = ? LIMIT 1");
        }
        $metaRow = [];
        if ($q) {
            $q->bind_param("i", $docId);
            $q->execute();
            $metaRow = $q->get_result()->fetch_assoc() ?: [];
            $q->close();
        } else {
            error_log('validate.php: failed to prepare metadata lookup — ' . $conn->error);
        }

        if (!empty($metaRow['rename_meta'] ?? null)) {
            $decoded = json_decode($metaRow['rename_meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        if ($meta) {
            $docType  = $meta['vClass']     ?? '';
            $ph       = $meta['vPH']        ?? '';
            $sec      = $meta['vSEC']       ?? '';
            $plot     = $meta['vPlot']      ?? '';
            $extVal   = $meta['vExt']       ?? '';
            if ($extVal) {
                $cleanExt = str_replace(['/', '\\'], '-', $extVal);
                if (substr($plot, -strlen($cleanExt)) !== $cleanExt) {
                    $plot = $plot ? ($plot . '-' . $cleanExt) : $cleanExt;
                }
            }
            $fileName = $meta['vFileName']  ?? '';
            $dateStr  = $meta['vDate']      ?? '';
        } else {
            // Smart legacy fallback in PHP
            $docType  = '';
            $ph       = $metaRow['phase']  ?? '';
            $sec      = $metaRow['branch'] ?? '';
            $plot     = $metaRow['plot']   ?? '';
            $fileName = '';
            $dateStr  = '';

            $base = preg_replace('/^_/', '', $renamedFilename);
            $base = pathinfo($base, PATHINFO_FILENAME);
            
            // Split and filter out empty elements
            $parts = array_filter(explode('_', $base), function($val) {
                return $val !== '';
            });
            $parts = array_values($parts); // re-index

            // Find 4-digit year index
            $yearIndex = -1;
            foreach ($parts as $idx => $part) {
                if (preg_match('/^\d{4}$/', $part)) {
                    $yearIndex = $idx;
                    break;
                }
            }

            if ($yearIndex !== -1) {
                $day = $parts[$yearIndex - 2] ?? '';
                $month = $parts[$yearIndex - 1] ?? '';
                $year = $parts[$yearIndex] ?? '';
                if ($day && $month && $year) {
                    $dateStr = "$day-$month-$year";
                }

                $docNoIndex = $yearIndex - 3;
                if ($docNoIndex - 1 >= 0) {
                    $fileName = $parts[$docNoIndex - 1];
                }

                $classStartIndex = 1;
                if (count($parts) > 1 && $parts[0] === 'Building' && $parts[1] === 'Control') {
                    $classStartIndex = 2;
                } else if (count($parts) > 1 && $parts[0] === 'Land' && $parts[1] === 'Acquisition') {
                    $classStartIndex = 2;
                }

                $currentIdx = $docNoIndex - 2;
                if (!empty($plot) && $currentIdx >= $classStartIndex && $parts[$currentIdx] === $plot) {
                    $currentIdx--;
                }
                if (!empty($sec) && $currentIdx >= $classStartIndex && $parts[$currentIdx] === $sec) {
                    $currentIdx--;
                }
                if (!empty($ph) && $currentIdx >= $classStartIndex && $parts[$currentIdx] === $ph) {
                    $currentIdx--;
                }

                if ($currentIdx >= $classStartIndex) {
                    $docType = implode('_', array_slice($parts, $classStartIndex, $currentIdx - $classStartIndex + 1));
                }
            } else {
                // Positional fallback
                $docType  = $parts[0] ?? '';
                $ph       = $parts[1] ?? '';
                $sec      = $parts[2] ?? '';
                $plot     = $parts[3] ?? '';
                $fileName = $parts[4] ?? '';
                $dateStr  = $parts[7] ?? '';
            }
        }

        // Determine branch from folder name
        $branch = 'Lahore';
        if (stripos($folderName, 'Karachi') !== false) {
            $branch = 'Karachi';
        } elseif (stripos($folderName, 'Islamabad') !== false) {
            $branch = 'Islamabad';
        } elseif (stripos($folderName, 'Multan') !== false) {
            $branch = 'Multan';
        }

        // Determine doc_year from dateStr (dd-mm-yyyy)
        $year = date('Y');
        if (!empty($dateStr)) {
            $dateParts = explode('-', str_replace('/', '-', $dateStr));
            if (count($dateParts) === 3) {
                $year = $dateParts[2];
            }
        }
    }

    // Update document table with parsed fields
    $stmt = $conn->prepare("
        UPDATE documents 
        SET doc_type = ?, phase = ?, plot = ?, file_no = ?, doc_year = ?, branch = ?
        WHERE document_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("ssssssi", $docType, $ph, $plot, $fileName, $year, $branch, $docId);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log('validate.php: failed to prepare report-column update — ' . $conn->error);
    }
}

// ── Handle Approve / Change ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = clean($_POST['action'] ?? '');
    $docId    = (int)$_POST['doc_id'];
    $remarks  = clean($_POST['remarks'] ?? '');

    $decisionMap = [
        'approve' => ['approved', 'approve'],
        'change'  => ['changed', 'change'],
    ];

    if (isset($decisionMap[$action])) {
        [$newStatus, $decision] = $decisionMap[$action];
        $validatedAt = date('Y-m-d H:i:s');

        // Update document status
        $stmt = $conn->prepare("UPDATE documents SET status=? WHERE document_id=?");
        if ($stmt) {
            $stmt->bind_param("si", $newStatus, $docId);
            $stmt->execute(); $stmt->close();
        } else {
            error_log('validate.php: failed to prepare status update — ' . $conn->error);
        }

        // Parse the renamed filename and update database columns for BOTH
        // approve and change — corrections need their edited metadata/
        // filename saved just as much as a plain approval does. Previously
        // this only ran for 'approve', so a correction recorded the decision
        // (status + validations row) but silently never applied the actual
        // filename/metadata the validator had just edited.
        if ($action === 'approve' || $action === 'change') {
            if (documentsHasRenameMetaColumn($conn)) {
                $q = $conn->prepare("
                    SELECT d.raw_filename, d.renamed_filename, d.storage_path, d.rename_meta, f.folder_name 
                    FROM documents d 
                    LEFT JOIN folders f ON d.folder_id = f.folder_id 
                    WHERE d.document_id = ? LIMIT 1
                ");
            } else {
                $q = $conn->prepare("
                    SELECT d.raw_filename, d.renamed_filename, d.storage_path, f.folder_name 
                    FROM documents d 
                    LEFT JOIN folders f ON d.folder_id = f.folder_id 
                    WHERE d.document_id = ? LIMIT 1
                ");
            }

            $dInfo = null;
            if ($q === false) {
                // Extremely defensive fallback: if prepare still fails for any
                // other reason, don't fatal-crash the page — skip the metadata
                // refresh below but let the approve/change decision still save.
                error_log('validate.php: failed to prepare document lookup — ' . $conn->error);
            } else {
                $q->bind_param("i", $docId);
                $q->execute();
                $dInfo = $q->get_result()->fetch_assoc();
                $q->close();
            }

            if ($dInfo) {
                // Read posted metadata values (exactly what the validator saw/edited on screen)
                $vPH = clean($_POST['vPH'] ?? '');
                $vSEC = clean($_POST['vSEC'] ?? '');
                $vPlot = clean($_POST['vPlot'] ?? '');
                $vExt = clean($_POST['vExt'] ?? '');
                $vClass = clean($_POST['vClass'] ?? '');
                $vFileName = clean($_POST['vFileName'] ?? '');
                $vPageNo = clean($_POST['vPageNo'] ?? '');
                $vPageCount = clean($_POST['vPageCount'] ?? '');
                $vDate = clean($_POST['vDate'] ?? '');

                // Land Acquisition fields — the JS form already submits these
                // as hidden inputs on every Approve/Change, but they were
                // never being read here, so any Land Acquisition document
                // going through Validate had its filename silently rebuilt
                // from the (empty/stale) Standard fields instead.
                $vMouaza        = clean($_POST['vMouaza'] ?? '');
                $vLandFileNo    = clean($_POST['vLandFileNo'] ?? '');
                $vLandOwnerName = clean($_POST['vLandOwnerName'] ?? '');
                $vKanal         = clean($_POST['vKanal'] ?? '');
                $vMarla         = clean($_POST['vMarla'] ?? '');
                $vSquareFoot    = clean($_POST['vSquareFoot'] ?? '');
                $vSaledeed      = clean($_POST['vSaledeed'] ?? '');
                $vLitigationNo  = clean($_POST['vLitigationNo'] ?? '');
                $vScanNo        = clean($_POST['vScanNo'] ?? '');

                // Determine which branch (Transfer / Building_Control /
                // Land_Acquisition) this document belongs to, the same way
                // getDocCategory() does in JS — prefer the saved vCategory
                // snapshot, then fall back to the Class Name prefix.
                $vCategory = '';
                if (!empty($dInfo['rename_meta'])) {
                    $decodedCatMeta = json_decode($dInfo['rename_meta'], true);
                    if (is_array($decodedCatMeta) && !empty($decodedCatMeta['vCategory'])) {
                        $vCategory = $decodedCatMeta['vCategory'];
                    }
                }
                if (empty($vCategory)) {
                    if (strpos($vClass, 'BC_') === 0) {
                        $vCategory = 'Building_Control';
                    } elseif (strpos($vClass, 'ACQN_') === 0) {
                        $vCategory = 'Land_Acquisition';
                    } else {
                        $vCategory = 'Transfer';
                    }
                }

                // Check extension
                $ext = pathinfo($dInfo['renamed_filename'] ?: $dInfo['raw_filename'], PATHINFO_EXTENSION);

                // Construct new renamed filename — Land Acquisition uses a
                // completely different field set/format (mirrors rename.php's
                // autoGenerateName()), so it must be built separately instead
                // of falling through to the Standard branch below it.
                $newRenamedName = $dInfo['renamed_filename'];

                if ($vCategory === 'Land_Acquisition') {
                    // Token order: FileNo_Mouaza_OwnerName_Phase_Kanal_Marla_SquareFoot_SaleDeedNo_LiticationNo_ScanNo_
                    $landParts = [];
                    if (!empty($vLandFileNo))    $landParts[] = $vLandFileNo;
                    if (!empty($vMouaza))        $landParts[] = $vMouaza;
                    if (!empty($vLandOwnerName)) $landParts[] = $vLandOwnerName;
                    if (!empty($vPH))            $landParts[] = $vPH;
                    if (!empty($vKanal))         $landParts[] = $vKanal;
                    if (!empty($vMarla))         $landParts[] = $vMarla;
                    if (!empty($vSquareFoot))    $landParts[] = $vSquareFoot;
                    if (!empty($vSaledeed))      $landParts[] = $vSaledeed;
                    if (!empty($vLitigationNo))  $landParts[] = $vLitigationNo;
                    if (!empty($vScanNo))        $landParts[] = $vScanNo;

                    if (!empty($landParts)) {
                        $newRenamedName = implode('_', $landParts) . '_.PDF';
                    }
                } else {
                    // $vDate is posted as "dd-mm-yyyy" — format date as single dd-mm-yyyy token
                    $dateToken = '';
                    if (!empty($vDate)) {
                        $dateBits = explode('-', str_replace('/', '-', $vDate));
                        if (count($dateBits) === 3) {
                            $dateToken = $dateBits[0] . '-' . $dateBits[1] . '-' . $dateBits[2];
                        }
                    }

                    $fullPlot = str_replace(['/', '\\'], '-', $vPlot);

                    $parts = [];
                    if (!empty($vClass))     $parts[] = $vClass;
                    if (!empty($vPH))        $parts[] = $vPH;
                    if (!empty($vSEC))       $parts[] = $vSEC;
                    if (!empty($fullPlot))   $parts[] = $fullPlot;
                    if (!empty($vFileName))  $parts[] = $vFileName;
                    if (!empty($dateToken))  $parts[] = $dateToken;
                    if (!empty($vPageNo))    $parts[] = $vPageNo;
                    if (!empty($vPageCount)) $parts[] = $vPageCount;

                    if (!empty($parts)) {
                        $newRenamedName = '_' . implode('_', $parts) . '.' . $ext;
                    }
                }

                // Build the exact metadata snapshot the validator confirmed.
                // This is what future reads (queue reload, re-open, etc.) will
                // trust instead of re-parsing the filename.
                $renameMetaJson = json_encode([
                    'vCategory'  => $vCategory,
                    'vClass'     => $vClass,
                    'vPH'        => $vPH,
                    'vSEC'       => $vSEC,
                    'vPlot'      => $vPlot,
                    'vExt'       => $vExt,
                    'vFileName'  => $vFileName,
                    'vPageNo'    => $vPageNo,
                    'vPageCount' => $vPageCount,
                    'vDate'      => $vDate,
                    'vMouaza'        => $vMouaza,
                    'vLandFileNo'    => $vLandFileNo,
                    'vLandOwnerName' => $vLandOwnerName,
                    'vKanal'         => $vKanal,
                    'vMarla'         => $vMarla,
                    'vSquareFoot'    => $vSquareFoot,
                    'vSaledeed'      => $vSaledeed,
                    'vLitigationNo'  => $vLitigationNo,
                    'vScanNo'        => $vScanNo,
                ], JSON_UNESCAPED_UNICODE);

                $finalRenamedName = $dInfo['renamed_filename'];
                $finalStoragePath = $dInfo['storage_path'];

                // Track whether the validator actually changed any metadata
                // fields before approving, so it can be surfaced in the
                // activity log for the admin portal.
                $metadataEditedBeforeApproval = (!empty($newRenamedName) && $newRenamedName !== $dInfo['renamed_filename']);

                // If filename changed, rename the file physically
                if ($metadataEditedBeforeApproval) {
                    $dirPart = dirname($dInfo['storage_path']);
                    $newStoragePath = $dirPart . '/' . $newRenamedName;

                    $oldFull = __DIR__ . '/../' . $dInfo['storage_path'];
                    $newFull = __DIR__ . '/../' . $newStoragePath;

                    if (!file_exists($oldFull)) {
                        error_log("validate.php: physical rename SKIPPED — source file not found at: $oldFull (doc #$docId)");
                        $message = "Validated, but the file on disk could not be found to rename it. The status was still saved.";
                        $msgType = 'warning';
                    } elseif (!rename($oldFull, $newFull)) {
                        error_log("validate.php: rename() FAILED from '$oldFull' to '$newFull' (doc #$docId)");
                        $message = "Validated, but renaming the file on disk failed (check folder permissions/path). The status was still saved.";
                        $msgType = 'warning';
                    } else {
                        $finalRenamedName = $newRenamedName;
                        $finalStoragePath = $newStoragePath;
                    }
                }

                // Always persist renamed_filename/storage_path/rename_meta together,
                // so rename_meta stays in sync with whatever filename is on disk —
                // even when the filename text itself didn't change but fields did.
                if (documentsHasRenameMetaColumn($conn)) {
                    $stmtRename = $conn->prepare("UPDATE documents SET renamed_filename = ?, storage_path = ?, rename_meta = ? WHERE document_id = ?");
                    if ($stmtRename) {
                        $stmtRename->bind_param("sssi", $finalRenamedName, $finalStoragePath, $renameMetaJson, $docId);
                        $stmtRename->execute();
                        $stmtRename->close();
                    }
                } else {
                    // No rename_meta column on this database — still keep the
                    // filename/path in sync, just without the JSON snapshot.
                    // (Run the migration in database/add_rename_meta_column.sql
                    // to enable full metadata-editing/tracking support.)
                    $stmtRename = $conn->prepare("UPDATE documents SET renamed_filename = ?, storage_path = ? WHERE document_id = ?");
                    if ($stmtRename) {
                        $stmtRename->bind_param("ssi", $finalRenamedName, $finalStoragePath, $docId);
                        $stmtRename->execute();
                        $stmtRename->close();
                    }
                }

                // Populate report columns using the posted (exact) metadata
                parseAndPopulateMetadata($conn, $docId, $finalRenamedName, $dInfo['folder_name'], $vClass, $vPH, $vPlot, $vFileName, $vDate, $vSEC);

                // Log the edit separately so it's traceable in the admin
                // portal's activity feed, distinct from the approval itself.
                if ($metadataEditedBeforeApproval) {
                    $oldNameForLog = $dInfo['renamed_filename'] ?: $dInfo['raw_filename'];
                    logActivity(
                        $conn, $userId, 'update',
                        "Edited metadata before validating document #$docId ({$oldNameForLog} → {$finalRenamedName})",
                        $docId
                    );
                }
            }
        }

        // Insert into validations table
        $stmt2 = $conn->prepare(
            "INSERT INTO validations (document_id, validated_by, decision, remarks, validated_at)
             VALUES (?,?,?,?,?)"
        );
        $validationInserted = false;
        if ($stmt2) {
            $stmt2->bind_param("iisss", $docId, $userId, $decision, $remarks, $validatedAt);
            $validationInserted = $stmt2->execute();
            $stmt2->close();
        } else {
            error_log('validate.php: failed to prepare validations insert — ' . $conn->error);
        }

        $label = match($action) {
            'approve' => 'Approved',
            'change'  => 'Corrected',
        };
        logActivity($conn, $userId, 'update', "$label document #$docId", $docId);

        // Post/Redirect/Get — same pattern rename.php already uses. Without
        // this, the response to this POST re-renders the page directly, and
        // the Validate KPI banner above (computed at the top of the script,
        // BEFORE this insert) shows the count from just before this action —
        // it looks "off by one" on this exact view. Worse, if the browser is
        // refreshed on that direct POST response, it resubmits the same form
        // data and inserts a second row for the same action, which is what
        // made the org-wide dashboard total (a fresh, independent page load)
        // race ahead of this tab's own banner. Redirecting makes the follow-up
        // request a plain GET — refreshing it is harmless, and the KPI query
        // at the top of the script now always runs after the insert.
        if ($validationInserted) {
            // Carry a warning (e.g. "renamed on disk failed") through the
            // redirect below so it's still visible on the reloaded page.
            if ($msgType === 'warning' && !empty($message)) {
                $_SESSION['validate_flash_message'] = $message;
                $_SESSION['validate_flash_type']    = $msgType;
            }
            $qs = $_SERVER['QUERY_STRING'] ?? '';
            $location = 'validate.php' . ($qs !== '' ? '?' . $qs . '&validated=1' : '?validated=1');
            header('Location: ' . $location);
            exit();
        }

        $message = 'Validation failed: ' . $conn->error;
        $msgType = 'danger';
    }
}

// ── Filters ───────────────────────────────────────────────────
$isAdmin = ((int)getUserRoleId() === 1);
if (!in_array((int)getUserRoleId(), [1, 3])) {
    header('Location: ../user_dashboard.php');
    exit();
}
$myAssignedBranches = getUserAssignedBranches();
$userRoleId = (int)getUserRoleId();
$filterStatus = clean($_GET['status']   ?? '');
$filterBranch = clean($_GET['branch']   ?? '');
$filterFolder = (int)($_GET['folder_id'] ?? 0);
$focusId      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── "Browse a folder" filter (set by the Browse button below) ──────────
// The button reads a local folder's name via the browser and reloads
// here with ?folder_name=... ; we resolve that to the matching folder_id
// so the existing folder_id filter (and its file_exists() safety check)
// does the actual work. A name with no matching folder yet — or no
// pending documents in it — naturally falls through to the normal
// "No documents found" empty state below.
$filterFolderName  = clean($_GET['folder_name'] ?? '');
$folderNameNoMatch = false;
if ($filterFolderName !== '' && !$filterFolder) {
    // NOTE: we deliberately do NOT resolve this to a single folder_id and
    // filter by that id. The "get folder, or create it" logic in
    // log_rename.php / log_browse.php has no unique constraint on
    // folder_name and isn't run in a transaction, so two near-simultaneous
    // requests for the same folder (e.g. an auto-sync tick overlapping the
    // initial scan) can each insert their own row — leaving two different
    // folder_id values that share the exact same folder_name. Resolving to
    // "the first matching id" here could silently pick the row with zero
    // documents on it, while the actual renamed files sit under its
    // duplicate sibling — showing an empty queue even though the folder
    // clearly matched. Filtering directly by name in the query below (see
    // $sql) matches every row with that name, so this can't happen.
    $chk = $conn->prepare("SELECT 1 FROM folders WHERE folder_name = ? LIMIT 1");
    $chk->bind_param("s", $filterFolderName);
    $chk->execute();
    $folderNameNoMatch = !$chk->get_result()->fetch_row();
    $chk->close();
}

// ── Independent-module rule ──────────────────────────────────────────
// Validate must never auto-load simply because rename.php finished —
// each module is worked by its own person who opens their own files.
// So the queue here only ever fills when the validator has explicitly
// scoped it themselves: by browsing a folder (sets $filterFolder above)
// or by opening a direct link to one document (?id=...). With neither
// given, the queue stays empty rather than listing every renamed/pending
// document in the system.
$docs = null;
$filteredDocs = [];
$hasScope = ($filterFolder > 0) || ($focusId > 0) || ($filterFolderName !== '' && !$folderNameNoMatch);

if ($hasScope) {
    $sql = "SELECT d.*, f.folder_name, u.full_name AS scanned_by_name, v.validated_by, v.validated_at
            FROM documents d
            LEFT JOIN folders f ON d.folder_id  = f.folder_id
            LEFT JOIN users   u ON d.scanned_by = u.user_id
            LEFT JOIN (
                SELECT document_id, validated_by, validated_at FROM validations
                WHERE validation_id IN (SELECT MAX(validation_id) FROM validations GROUP BY document_id)
            ) v ON d.document_id = v.document_id
            WHERE 1=1";

    if ($focusId > 0) {
        // A direct link to one specific document — explicit access, not a
        // queue — but still must be a renamed file; Validate never shows a
        // document that hasn't been through Rename yet, even via direct link.
        $sql .= " AND d.document_id = $focusId AND NOT (d.renamed_filename IS NULL OR d.renamed_filename='')";
    } else {
        if (!$isAdmin) {
            $sql .= " AND (d.status='pending' OR d.document_id IN (SELECT val.document_id FROM validations val WHERE val.validated_by = $userId))";
        }
        // A pending document that hasn't been renamed yet isn't ready for
        // verification — it belongs in the Rename queue, not here. Approved/
        // changed documents are always renamed already (rename is required
        // before verification), so this only ever hides not-yet-renamed pending rows.
        $sql .= " AND NOT (d.status='pending' AND (d.renamed_filename IS NULL OR d.renamed_filename=''))";

        // Already-verified (approved) documents are done and should no longer
        // clutter the queue or be previewable from it — once a document is
        // approved it drops out of this list entirely.
        $sql .= " AND d.status != 'approved'";

        // Same for documents already validated after correction — once a
        // document has been marked 'changed' it's done too and should no
        // longer show up in the queue or preview here.
        $sql .= " AND d.status != 'changed'";

        // Verify only ever deals with PDFs — image files (jpg/png/tiff) never
        // show up in the queue or preview here.
        $sql .= " AND d.file_type = 'pdf'";

        if ($filterStatus) { $s = $conn->real_escape_string($filterStatus); $sql .= " AND d.status='$s'"; }
        if ($filterBranch) { $b = $conn->real_escape_string($filterBranch); $sql .= " AND d.branch LIKE '%$b%'"; }
        if ($filterFolder) {
            $sql .= " AND d.folder_id=$filterFolder";
        } elseif ($filterFolderName !== '') {
            // Match by name (see note above) instead of a single resolved
            // folder_id, so documents split across duplicate folder rows
            // with the same name are still found.
            $fn = $conn->real_escape_string($filterFolderName);
            $sql .= " AND f.folder_name = '$fn'";
        }
    }
    $sql .= " ORDER BY (d.status='pending') DESC, v.validated_at DESC, d.document_id DESC";
    $docs = $conn->query($sql);

    if ($docs && $docs->num_rows > 0) {
        while ($row = $docs->fetch_assoc()) {
            $realPath = __DIR__ . '/../' . $row['storage_path'];
            if (file_exists($realPath) && is_file($realPath)) {
                $filteredDocs[] = $row;
            }
        }
    }
}
// $folderNameNoMatch (browsed folder name with no DB match) naturally
// stays empty too, since $filterFolder is never set in that case.

// Stats — scoped for user roles
if ($userRoleId === 3) { // Validator
    $pending  = $conn->query("SELECT COUNT(*) FROM documents WHERE status='pending'")->fetch_row()[0];
    $approved = $conn->query("SELECT COUNT(*) FROM validations WHERE validated_by = $userId AND decision='approve'")->fetch_row()[0];
    $changed = $conn->query("SELECT COUNT(*) FROM validations WHERE validated_by = $userId AND decision='change'")->fetch_row()[0];
} elseif ($userRoleId === 2) { // Operator
    $pending  = $conn->query("SELECT COUNT(*) FROM documents WHERE scanned_by = $userId AND status='pending'")->fetch_row()[0];
    $approved = $conn->query("SELECT COUNT(*) FROM documents WHERE scanned_by = $userId AND status='approved'")->fetch_row()[0];
    $changed = $conn->query("SELECT COUNT(*) FROM documents WHERE scanned_by = $userId AND status='changed'")->fetch_row()[0];
} else { // Admin
    $pending  = $conn->query("SELECT COUNT(*) FROM documents WHERE status='pending'")->fetch_row()[0];
    $approved = $conn->query("SELECT COUNT(*) FROM documents WHERE status='approved'")->fetch_row()[0];
    $changed = $conn->query("SELECT COUNT(*) FROM documents WHERE status='changed'")->fetch_row()[0];
}

// Count of documents awaiting rename
$awaitingRenameCount = $conn->query("SELECT COUNT(*) FROM documents WHERE status='pending' AND (renamed_filename IS NULL OR renamed_filename = '')" . (($userRoleId === 1 || $userRoleId === 3) ? "" : " AND scanned_by = $userId"))->fetch_row()[0];

// Folders for filter dropdown (only show folders with pending documents)
$folderOpts = $conn->query("
    SELECT DISTINCT f.folder_id, f.folder_name 
    FROM folders f 
    JOIN documents d ON f.folder_id = d.folder_id 
    WHERE f.status='active' AND d.status='pending' 
    ORDER BY f.folder_name
");
?>

<?php if ($isAdmin) { require_once '../includes/header.php'; } else { require_once '../includes/user_header.php'; } ?>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show mb-3" role="alert">
  <i class="fas fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?> me-2"></i>
  <?= esc($message) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($filterFolderName !== ''): ?>
<div class="alert alert-info alert-dismissible fade show mb-3" role="alert" style="font-size:0.85rem;">
  <i class="fas fa-folder-open me-2"></i>
  <?php if ($folderNameNoMatch): ?>
    <strong>"<?= esc($filterFolderName) ?>":</strong> no documents from this folder are ready for verification yet.
  <?php else: ?>
    <strong>Filtered to folder:</strong> "<?= esc($filterFolderName) ?>" — showing only documents from it that need verification.
  <?php endif; ?>
  <a href="validate.php" class="alert-link ms-2">View all documents</a>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="validate-container">

  <!-- ══ LEFT: Document Preview ══════════════════════════════════ -->
  <div class="preview-column">
    <div class="dha-card h-100 d-flex flex-column">
      <div class="dha-card-header py-3">
        <h5 id="previewTitle" class="mb-0 fw-600 text-truncate">Document Preview</h5>
      </div>
      <div class="dha-card-body flex-grow-1 p-0 position-relative" style="background:var(--surface-2);overflow:hidden;overflow-x:hidden;min-height:400px;">
        <div id="docPreviewArea" class="w-100 h-100 d-flex align-items-center justify-content-center">
          <div class="text-center text-secondary py-5">
            <i class="fas fa-file fa-4x mb-3 opacity-30"></i>
            <p>Select a document from the queue to preview</p>
          </div>
        </div>
        <div class="zoom-controls-overlay" id="zoomOverlay" style="display:none;">
          <button type="button" class="zoom-btn" onclick="vZoom('out')" title="Zoom Out"><i class="fas fa-minus"></i></button>
          <span id="zoomPercent" class="zoom-text">100%</span>
          <button type="button" class="zoom-btn" onclick="vZoom('in')"  title="Zoom In"><i class="fas fa-plus"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ RIGHT: Verify Workflow Panel ═══════════════════════════ -->
  <div class="workflow-column">
    <div class="dha-card h-100 d-flex flex-column">
      <div class="dha-card-header py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-700">Verify Workflow</h5>
        <span id="currentDocBadge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:20px; padding:3px 12px; font-size:0.75rem; font-weight:600; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">None Selected</span>
      </div>

      <div class="dha-card-body flex-grow-1 d-flex flex-column gap-3 py-3 px-3 overflow-y-auto">

        <!-- Tabs (Hidden, but kept in DOM to prevent breaking JS references —
             mirrors the same pattern used in rename.php) -->
        <div class="workflow-tabs d-none" style="display: none !important;">
          <button type="button" class="workflow-tab active" id="tabTransfer"  onclick="setVTab('Transfer')">Transfer</button>
          <button type="button" class="workflow-tab"        id="tabBuilding"  onclick="setVTab('Building Control')">Building Control</button>
          <button type="button" class="workflow-tab"        id="tabLand"      onclick="setVTab('Land Acquisition')">Land Acquisition</button>
        </div>

        <!-- BRANCH -->
        <!-- Selecting a BRANCH used to also call vFocusFirstEditableField(),
             which jumped keyboard focus straight to CLASS NAME — skipping
             over Browse and the editable SEC / PLOT NO fields entirely.
             That call was removed so Tab now walks the natural order:
             BRANCH -> Browse -> SEC -> PLOT NO -> CLASS NAME -> ... -->
        <div>
          <label class="workflow-label">BRANCH</label>
          <?php $vBranchValues = ['Transfer' => 'Transfer', 'Building_Control' => 'Building Control', 'Land_Acquisition' => 'Land Acquisition']; ?>
          <?php if (count($myAssignedBranches) === 1 && !$isAdmin): ?>
            <?php $vBranchValue = $vBranchValues[$myAssignedBranches[0]] ?? $myAssignedBranches[0]; ?>
            <select class="workflow-input" id="vBranchSelect" disabled tabindex="-1">
              <option value="<?= esc($vBranchValue) ?>" selected><?= esc($vBranchValue) ?></option>
            </select>
          <?php elseif (count($myAssignedBranches) > 1 && !$isAdmin): ?>
            <select class="workflow-input" id="vBranchSelect" onchange="setVTab(this.value);">
              <option value="" selected disabled hidden>None</option>
              <?php foreach ($myAssignedBranches as $mb): ?>
                <?php $mbLabel = $vBranchValues[$mb] ?? $mb; ?>
                <option value="<?= esc($mbLabel) ?>"><?= esc($mbLabel) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <select class="workflow-input" id="vBranchSelect" onchange="setVTab(this.value);">
              <option value="" selected disabled hidden>None</option>
              <option value="Transfer">Transfer</option>
              <option value="Building Control">Building Control</option>
              <option value="Land Acquisition">Land Acquisition</option>
            </select>
          <?php endif; ?>
        </div>

        <!-- Browse Button -->
        <div class="browse-btn-wrap">
          <button type="button" class="workflow-btn workflow-btn-browse w-100" id="browseVerifyBtn" onclick="browseVerifyFolder()" title="Browse a folder — shows only the documents from it that still need verification">
            <i class="fas fa-folder-open me-2"></i>Browse Folder
          </button>
        </div>

        <!-- Multi-folder queue confirmation — shown after the first folder is
             picked, lets you add more folders (processed top-to-bottom in the
             order you pick them) before starting. -->
        <div id="verifyFolderQueuePanel" class="d-none p-2 rounded" style="background:var(--surface-2);border:1px solid var(--border);font-size:0.78rem;">
          <div id="verifyFolderQueueList" class="mb-2"></div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary flex-grow-1" onclick="browseVerifyFolder()"><i class="fas fa-plus me-1"></i>Add Another Folder</button>
            <button type="button" class="btn btn-sm btn-success flex-grow-1" onclick="startVerifyFolderQueue()"><i class="fas fa-play me-1"></i>Start Verifying</button>
          </div>
        </div>

        <!-- Metadata (editable, sourced from rename_meta snapshot) -->
        <div class="row g-2">
          <div class="col-4"><label class="workflow-label">PH</label><input type="text" class="workflow-input text-center" id="vPH" placeholder="—" readonly tabindex="-1"></div>
          <div class="col-4" id="vSecField"><label class="workflow-label">SEC</label><input type="text" class="workflow-input text-center" id="vSEC" placeholder="—"></div>
          <div class="col-4" id="vPlotField"><label class="workflow-label">PLOT NO</label><input type="text" class="workflow-input text-center" id="vPlot" placeholder="—"></div>
        </div>

        <!-- LAND ACQUISITION ONLY FIELDS -->
        <!-- Token order: FileNo_Mouaza_OwnerName_Phase_Kanal_Marla_SquareFoot_SaleDeedNo_LiticationNo_ScanNo_ -->
        <div class="row g-2" id="vLandExtraFields" style="display:none;">
          <div class="col-6">
            <label class="workflow-label">FILE NO</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input" id="vLandFileNo" oninput="this.value = this.value.replace(/[^0-9]/g, '');" placeholder="—">
          </div>
          <div class="col-6">
            <label class="workflow-label">MUZA</label>
            <input type="text" class="workflow-input" id="vMouaza" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '');" placeholder="—">
          </div>
          <div class="col-12">
            <label class="workflow-label">LAND OWNER NAME</label>
            <input type="text" class="workflow-input" id="vLandOwnerName" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '');" placeholder="—">
          </div>
          <div class="col-3">
            <label class="workflow-label">KANAL</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="vKanal" oninput="this.value = this.value.replace(/[^0-9]/g, '');" placeholder="—">
          </div>
          <div class="col-3">
            <label class="workflow-label">MARLA</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="vMarla" oninput="this.value = this.value.replace(/[^0-9]/g, '');" placeholder="—">
          </div>
          <div class="col-3">
            <label class="workflow-label">SQ. FT</label>
            <input type="text" inputmode="decimal" class="workflow-input text-center" id="vSquareFoot" oninput="this.value = this.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1');" placeholder="—">
          </div>
          <div class="col-3">
            <label class="workflow-label">SALE DEED</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="vSaledeed" oninput="this.value = this.value.replace(/[^0-9]/g, '');" placeholder="—">
          </div>
          <div class="col-3">
            <label class="workflow-label">LITIGATION NO</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="vLitigationNo" oninput="this.value = this.value.replace(/[^0-9]/g, '');" placeholder="—">
          </div>
          <div class="col-3">
            <label class="workflow-label">SCAN NO</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="vScanNo" oninput="this.value = this.value.replace(/[^0-9]/g, '');" placeholder="—">
          </div>
        </div>

        <!-- CLASS NAME (Custom Combo Box: type + dropdown) — mirrors the same
             combo box on pages/rename.php. Options are built at runtime from
             the shared ALLOWED_CLASSES list (assets/js/allowed_classes.js) so
             the two pages' option lists can never drift apart. id="vClass"
             is unchanged so every existing read/write of this field
             (parseAndPopulateMetadata, form submit, vCheckForChanges, the
             Escape-to-autocomplete handler below) keeps working untouched. -->
        <div id="vClassNameField" style="position:relative;">
          <label class="workflow-label">CLASS NAME</label>
          <div class="class-combo-wrap">
            <input type="text" class="workflow-input class-combo-input" id="vClass"
                   oninput="onVClassChange(); filterVClassOptions()"
                   onchange="onVClassChange()"
                   autocomplete="off"
                   placeholder="—">
            <button type="button" class="class-combo-arrow" onclick="toggleVClassDropdown()" tabindex="-1" title="Show options">
              <i class="fas fa-chevron-down" id="vClassArrowIcon"></i>
            </button>
          </div>
          <div class="class-combo-dropdown" id="vClassDropdown"></div>
        </div>
        <div id="vFileNameField"><label class="workflow-label">FILE NAME</label><input type="text" class="workflow-input" id="vFileName" placeholder="—"></div>
        <div class="row g-2" id="vPageDateFields">
          <div class="col-3"><label class="workflow-label">PAGE NO</label><input type="text" class="workflow-input text-center" id="vPageNo" placeholder="—"></div>
          <div class="col-3"><label class="workflow-label">PAGE COUNT</label><input type="text" class="workflow-input text-center" id="vPageCount" placeholder="—"></div>
          <div class="col-6"><label class="workflow-label">DATE</label><input type="text" inputmode="numeric" class="workflow-input" id="vDate" placeholder="dd-mm-yyyy" autocomplete="off"></div>
        </div>

        <!-- Shown when the validator has edited any metadata field for the currently
             selected document — clicking Validate will then record it as Validated
             after Correction instead of a plain Approval. -->
        <div id="vChangeIndicator" style="display:none;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:6px;padding:6px 10px;font-size:.78rem;font-weight:600;">
          <i class="fas fa-exclamation-triangle me-1"></i>Data edited — will be marked <strong>Validated after Correction</strong> instead of Validated as-is.
        </div>

        <!-- Verify / Approve button (always visible above the queue; disabled until a
             document is selected and the current user is permitted to action it) -->
        <div class="row g-2" id="verifyActionBtnsShown">
          <div class="col-12">
            <button type="button" class="workflow-btn w-100" id="btnTriggerValidate" style="background:#15803d;" onclick="triggerApprove()" disabled>
              <i class="fas fa-check me-2"></i>Validate
            </button>
          </div>
        </div>

        <!-- Document Queue -->
        <div class="queue-container flex-grow-1">
          <div class="queue-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span>Document Queue (<span id="queueCount"><?= count($filteredDocs) ?></span>)</span>
              <span style="background:#eab308;color:#000;font-size:0.65rem;font-weight:700;border-radius:4px;padding:3px 8px;"><?= $pending ?> Pending</span>
              <span style="background:#16a34a;color:#fff;font-size:0.65rem;font-weight:700;border-radius:4px;padding:3px 8px;"><?= $approved ?> Done</span>
              <span style="background:#dc2626;color:#fff;font-size:0.65rem;font-weight:700;border-radius:4px;padding:3px 8px;"><?= $changed ?> Corrected</span>
            </div>
            <div class="d-flex gap-2 align-items-center ms-auto" id="verifyQueueToolbar">
              <button type="button" class="btn btn-outline-danger" id="clearDoneBtn" onclick="clearValidateQueue()" style="font-size:0.85rem;height:36px;padding:0 16px;font-weight:600;" title="Clear document queue">
                <i class="fas fa-trash-alt me-2"></i>Clear
              </button>
            </div>
          </div>
          <div class="queue-list" id="validateQueueList">
            <?php
            foreach ($filteredDocs as $doc):
              $isPdf    = ($doc['file_type'] === 'pdf');
              $icon     = $isPdf ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
              // Any signed-in user on this page can edit & validate a pending
              // document once they select it — permission is no longer tied
              // to who originally scanned/renamed it.
              // Already-decided (approved/changed) documents can still only
              // be re-verified by Admins or the Validator who made that call.
              $isRenamed = !empty($doc['renamed_filename']);
              $canAction = ($doc['status'] === 'pending') ||
                           ($doc['status'] !== 'pending' && (
                               $userRoleId === 1 ||
                               $userRoleId === 3 ||
                               (int)($doc['validated_by'] ?? 0) === $userId
                           ));
            ?>
            <div class="queue-item <?= $doc['status'] === 'approved' ? 'queue-approved' : ($doc['status'] === 'changed' ? 'queue-changed' : '') ?>"
                 id="vdoc-<?= $doc['document_id'] ?>"
                 data-id="<?= $doc['document_id'] ?>"
                 data-status="<?= esc($doc['status']) ?>"
                 data-canaction="<?= $canAction ? '1' : '0' ?>"
                 data-doc='<?= htmlspecialchars(json_encode($doc), ENT_QUOTES) ?>'
                 onclick="selectValidateDoc(this)">
              <i class="fas <?= $icon ?> fa-sm"></i>
              <span class="text-truncate flex-grow-1" style="font-size:.78rem;">
                <?= esc($doc['renamed_filename'] ?: $doc['raw_filename']) ?>
              </span>
              <?php if ($doc['status'] === 'approved'): ?>
                <i class="fas fa-check-circle fa-xs" style="color:#16a34a;flex-shrink:0;" title="Verified"></i>
              <?php elseif ($doc['status'] === 'changed'): ?>
                <i class="fas fa-times-circle fa-xs" style="color:#dc2626;flex-shrink:0;" title="Validated after Correction"></i>
              <?php elseif (!empty($doc['renamed_filename'])): ?>
                <i class="fas fa-tag fa-xs" style="color:#c59437;flex-shrink:0;" title="Renamed – ready to verify"></i>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($filteredDocs) && !$hasScope): ?>
            <div class="text-center text-secondary py-4" style="font-size:.82rem;">
              <i class="fas fa-folder-open fa-2x mb-2 d-block opacity-50"></i>
              Click <strong>Browse</strong> above and pick a folder to load the documents you need to verify.
            </div>
            <?php elseif (empty($filteredDocs)): ?>
            <div class="text-center text-secondary py-4" style="font-size:.82rem;">
              <i class="fas fa-check-circle fa-2x mb-2 d-block text-success opacity-50"></i>
              No documents found.
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /card-body -->
    </div>
  </div>

</div><!-- /validate-container -->

<!-- Hidden folder-browse input (fallback for browsers without showDirectoryPicker) -->
<input type="file" id="verifyBrowseFileInput" webkitdirectory directory multiple
       style="display:none" onchange="handleVerifyBrowsedFolder(this)">

<!-- Hidden approve form -->
<form method="POST" id="approveForm" style="display:none;">
  <input type="hidden" name="action"  id="approveAction" value="approve">
  <input type="hidden" name="doc_id"  id="approveDocId">
  <input type="hidden" name="remarks" id="approveRemarks" value="Approved.">
</form>

<style>
/* Layout background follows the active theme (light/dark) */
body {
  background-color: var(--bg) !important;
  color: var(--text-primary);
}
.main-content {
  background-color: var(--bg) !important;
}
.content-area {
  padding: 16px !important;
  background-color: var(--bg) !important;
}

/* Navbar gold active line */
.hnav-link.active {
  border-bottom: 3px solid #c59437 !important;
}

.validate-container {
  display:grid;
  grid-template-columns:1fr 480px;
  gap:20px;
  height:calc(100vh - 130px);
  min-height:600px;
  margin-bottom:20px;
}
@media(max-width:992px){.validate-container{grid-template-columns:1fr;height:auto;}}

.preview-column{display:flex;flex-direction:column;height:100%;}
.preview-column .dha-card{height:100%;}
.workflow-column{height:100%;}
.workflow-column .dha-card{height:100%;}

/* Custom premium card styling — follows theme */
.dha-card {
  background: var(--surface) !important;
  color: var(--text-primary) !important;
  border-radius: 12px !important;
  border: 1px solid var(--border) !important;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06) !important;
  overflow: hidden;
}

.dha-card-header {
  background: var(--surface) !important;
  border-bottom: 1px solid var(--border) !important;
  color: var(--text-primary) !important;
  font-weight: 700;
}

/* Document preview background */
.dha-card-body #docPreviewArea {
  background: var(--surface-2) !important;
  padding: 10px;
}

.zoom-controls-overlay{position:absolute;bottom:20px;right:20px;display:flex;align-items:center;gap:12px;background:#0f172a;border-radius:30px;padding:6px 14px;box-shadow:0 4px 20px rgba(0,0,0,.3);z-index:100;}
.zoom-btn{background:none;border:none;color:#94a3b8;cursor:pointer;font-size:.9rem;padding:4px;transition:color .2s;}
.zoom-btn:hover{color:#fff;}
.zoom-text{color:#fff;font-size:.85rem;font-weight:600;min-width:45px;text-align:center;}

/* ══ Browse Button (matches rename.php's style) ══════════════ */
.browse-btn-wrap { position: relative; }
.workflow-btn-browse {
  background: linear-gradient(135deg, #0c1445 0%, #1e3a8a 100%);
  border: 1.5px dashed rgba(96,165,250,0.55);
  transition: all 0.25s; gap: 8px;
}
.workflow-btn-browse:hover {
  background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
  border-color: rgba(147,197,253,0.85);
  box-shadow: 0 4px 22px rgba(30,58,138,0.5);
  opacity: 1; transform: translateY(-1px);
}

/* Workflow navigation tabs */
.workflow-tabs{
  background: none !important;
  padding: 0 !important;
  display: flex !important;
  gap: 8px !important;
  border-radius: 0 !important;
}
.workflow-tab{
  flex: 1;
  padding: 11px 10px !important;
  border: 1px solid var(--border) !important;
  background: var(--surface-2) !important;
  border-radius: 6px !important;
  font-size: .92rem !important;
  font-weight: 600 !important;
  color: var(--text-secondary) !important;
  cursor: pointer;
  transition: all .2s !important;
  white-space: nowrap;
  text-align: center;
}
.workflow-tab:hover{
  background: var(--border) !important;
  color: var(--text-primary) !important;
}
.workflow-tab.active{
  background: #c59437 !important; /* Premium gold active background */
  border-color: #c59437 !important;
  color: #fff !important;
}

.workflow-label{font-size:.78rem;font-weight:700;letter-spacing:.5px;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;display:block;}

.workflow-input{
  width:100%;
  height:42px;
  border-radius:6px;
  border:1px solid var(--border);
  background:var(--surface-2);
  padding:6px 12px;
  font-size:.95rem;
  color:var(--text-primary);
  font-weight: 600;
  outline:none;
  transition:border-color .2s;
}
.workflow-input[readonly]{background:var(--surface-2);cursor:default;}

.workflow-btn{height:46px;border-radius:6px;border:none;font-size:.95rem;font-weight:700;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:opacity .2s,transform .1s;}
.workflow-btn:hover{opacity:.9;}
.workflow-btn:active{transform:scale(.98);}
.workflow-btn:disabled{opacity:.45;cursor:not-allowed;}
.workflow-btn:disabled:hover{opacity:.45;}

.queue-container{display:flex;flex-direction:column;border:1px solid var(--border);border-radius:8px;overflow:hidden;flex-grow:1;background:var(--surface);min-height:180px;}
.queue-header{background:var(--surface-2);border-bottom:1px solid var(--border);padding:8px 12px;font-size:.75rem;font-weight:700;color:var(--text-secondary);text-transform:none;}
.queue-list{overflow-y:auto;background:var(--surface);flex-grow:1;scroll-behavior:smooth;}
.queue-list::-webkit-scrollbar{width:4px;}
.queue-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}
.queue-item{display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:.8rem;color:var(--text-primary);cursor:pointer;border-bottom:1px solid var(--border-light);transition:background .2s;font-weight:500;}
.queue-item:hover{background:var(--surface-2);}
.queue-item.active{background:var(--dha-accent);border-left:3px solid var(--dha-light);font-weight:600;color:var(--dha-primary);}
.queue-approved{opacity:.7;}
.queue-changed{opacity:.7;}

[data-theme="dark"] #currentDocBadge {
  background: rgba(59,130,246,.15) !important;
  color: #93c5fd !important;
  border-color: rgba(59,130,246,.3) !important;
}

/* ══ CLASS NAME Combo Box — copied from pages/rename.php so both pages
   look and behave identically ═══════════════════════════════ */
.class-combo-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.class-combo-input {
  flex: 1;
  border-radius: 6px 0 0 6px !important;
  border-right: none !important;
  z-index: 1;
}
.class-combo-arrow {
  height: 42px;
  width: 40px;
  flex-shrink: 0;
  background: #f1f5f9;
  border: 1px solid #d1d5db;
  border-left: none;
  border-radius: 0 6px 6px 0;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #64748b;
  transition: background 0.15s, color 0.15s;
}
.class-combo-arrow:hover { background: #e2e8f0; color: #1e3a8a; }
[data-theme="dark"] .class-combo-arrow {
  background: #1e293b; border-color: #334155; color: #94a3b8;
}
[data-theme="dark"] .class-combo-arrow:hover { background: #0f172a; color: #60a5fa; }

.class-combo-dropdown {
  display: none;
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  z-index: 9999;
  background: #fff;
  border: 1px solid #bfdbfe;
  border-top: none;
  border-radius: 0 0 8px 8px;
  box-shadow: 0 8px 24px rgba(30,58,138,0.13);
  max-height: 220px;
  overflow-y: auto;
}
.class-combo-dropdown.open { display: block; }
[data-theme="dark"] .class-combo-dropdown {
  background: #1e293b;
  border-color: #334155;
  box-shadow: 0 8px 24px rgba(0,0,0,0.4);
}
.class-combo-item {
  padding: 8px 14px;
  font-size: 0.9rem;
  cursor: pointer;
  color: #1e3a8a;
  transition: background 0.12s;
}
.class-combo-item:hover, .class-combo-item.highlighted {
  background: #eff6ff;
}
[data-theme="dark"] .class-combo-item { color: #93c5fd; }
[data-theme="dark"] .class-combo-item:hover, [data-theme="dark"] .class-combo-item.highlighted { background: #172554; }
.class-combo-item.hidden { display: none; }
.class-combo-item.tab-filtered-out { display: none; }

/* ── Validate KPI Banner ──────────────────────────────────── */
.validate-kpi-card {
  background: linear-gradient(135deg, rgba(21,128,61,0.08) 0%, rgba(11,19,41,0.04) 100%);
  border-left: 5px solid #15803d;
}
.validate-kpi-body {
  display: flex; align-items: center; justify-content: space-between;
  gap: 1.5rem; flex-wrap: wrap; padding: 1.25rem 1.5rem;
}
.validate-kpi-info { display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 260px; }
.validate-kpi-icon {
  width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
  background-color: rgba(21,128,61,0.15); color: #15803d;
  display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
}
.validate-kpi-title { font-weight: 800; color: var(--text-primary); margin: 0 0 0.2rem 0; font-size: 1rem; }
.validate-kpi-sub   { color: var(--text-secondary); font-size: 0.8rem; margin: 0; }
.validate-kpi-progress-wrap { flex: 1.4; min-width: 240px; }
.validate-kpi-progress-track { height: 10px; background-color: var(--border); border-radius: 8px; overflow: hidden; }
.validate-kpi-progress-fill  { height: 100%; background: linear-gradient(90deg, #15803d, #16a34a); border-radius: 8px; transition: width 0.4s ease; }
.validate-kpi-progress-labels { display: flex; justify-content: space-between; margin-top: 0.4rem; font-size: 0.72rem; font-weight: 600; color: var(--text-secondary); }
.validate-kpi-percent { flex-shrink: 0; text-align: center; min-width: 110px; }
.validate-kpi-percent-value { font-size: 1.8rem; font-weight: 900; color: var(--text-primary); line-height: 1; }
.validate-kpi-percent-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-top: 0.2rem; }
</style>

<!-- CLASS NAME reference list + fuzzy-match helper — shared with
     pages/rename.php so both pages' Escape-to-autocomplete stay in sync. -->
<script src="<?= $base ?>assets/js/allowed_classes.js"></script>

<script>
let vCurrentDocId  = null;
let vCurrentCanAct = false;
let vZoomLevel     = 100;

// ── Date mask (dd-mm-yyyy) — mirrors the identical helper in rename.php ──
function attachVDateMask(input) {
  function reformat() {
    let raw = input.value.replace(/[^0-9]/g, '');
    let dd   = raw.slice(0, 2);
    let mm   = raw.slice(2, 4);
    let yyyy = raw.slice(4);
    if (yyyy.length > 4) yyyy = yyyy.slice(yyyy.length - 4); // rolling window

    if (dd.length === 2) {
      let n = parseInt(dd, 10);
      if (isNaN(n) || n < 1) n = 1;
      if (n > 31) n = 31;
      dd = String(n).padStart(2, '0');
    }
    if (mm.length === 2) {
      let n = parseInt(mm, 10);
      if (isNaN(n) || n < 1) n = 1;
      if (n > 12) n = 12;
      mm = String(n).padStart(2, '0');
    }

    const parts = [];
    if (dd)   parts.push(dd);
    if (mm)   parts.push(mm);
    if (yyyy) parts.push(yyyy);
    input.value = parts.join('-');
    input.setSelectionRange(input.value.length, input.value.length);
  }
  input.addEventListener('input', () => { reformat(); vCheckForChanges(); });
  input.addEventListener('blur', reformat);
}

// ── Live local-folder preview ────────────────────────────────────
// Validate's document METADATA (queue list, status, KPIs) still comes
// from the database exactly as before — that part hasn't changed. What
// changes here is where the PREVIEWED FILE ITSELF comes from: instead of
// always loading it from the server's storage_path, we read it directly
// out of whichever folder the validator picked with Browse, via the
// File System Access API — the same live, local-disk access Rename and
// Scan already use. storage_path is kept only as a fallback for when no
// local folder is connected yet (or the file can't be found in it).
const currentFilterFolderName = <?= json_encode($filterFolderName) ?>;
let verifyDirHandle     = null; // FileSystemDirectoryHandle for the currently browsed folder
let verifyDirHandleName = null;
let vCurrentPreviewBlobUrl = null; // tracks the last object URL so it can be revoked

const VERIFY_DB_NAME  = 'DHA_ValidateFolderDB';
const VERIFY_DB_VERSION = 1;
const VERIFY_STORE_NAME = 'folders';

function getVerifyDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(VERIFY_DB_NAME, VERIFY_DB_VERSION);
    request.onupgradeneeded = (e) => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains(VERIFY_STORE_NAME)) {
        db.createObjectStore(VERIFY_STORE_NAME, { keyPath: 'folderName' });
      }
    };
    request.onsuccess = (e) => resolve(e.target.result);
    request.onerror   = (e) => reject(e.target.error);
  });
}

async function saveVerifyFolderHandle(folderName, dirHandle) {
  try {
    const db = await getVerifyDB();
    const tx = db.transaction(VERIFY_STORE_NAME, 'readwrite');
    tx.objectStore(VERIFY_STORE_NAME).put({ folderName, dirHandle });
    await new Promise((resolve, reject) => { tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); });
  } catch (err) {
    console.warn('Failed to save folder handle for Validate:', err);
  }
}

async function getVerifyFolderHandle(folderName) {
  try {
    const db = await getVerifyDB();
    const tx = db.transaction(VERIFY_STORE_NAME, 'readonly');
    const req = tx.objectStore(VERIFY_STORE_NAME).get(folderName);
    const result = await new Promise((resolve, reject) => {
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error);
    });
    return result ? result.dirHandle : null;
  } catch (err) {
    console.warn('Failed to read folder handle for Validate:', err);
    return null;
  }
}

// Shows a small "Grant Access" prompt in the queue panel. Browsers require
// a real click to re-grant permission on a handle restored from IndexedDB
// (queryPermission alone can't silently regain 'granted' after a reload),
// so we can't just re-request it automatically on page load.
function renderVerifyGrantAccessPrompt(folderName, dirHandle) {
  const container = document.getElementById('validateQueueList');
  if (!container || document.getElementById('verifyGrantAccessPrompt')) return;
  const div = document.createElement('div');
  div.id = 'verifyGrantAccessPrompt';
  div.className = 'p-3 mb-2 rounded border d-flex flex-column gap-2';
  div.style.fontSize = '0.78rem';
  div.style.backgroundColor = '#2a1a08';
  div.style.color = '#eab308';
  div.style.borderColor = '#d97706';
  div.innerHTML = `
    <div class="d-flex align-items-center gap-2">
      <i class="fas fa-folder-open fa-lg"></i>
      <span>Reconnect to <strong>${folderName.replace(/</g,'&lt;')}</strong> to preview files directly from disk.</span>
    </div>
    <button type="button" class="btn btn-sm btn-warning w-100 fw-600 mt-1" style="font-size:0.75rem; background:#d97706; border:none; color:#fff;" onclick="requestVerifyFolderPermission()">
      <i class="fas fa-key me-1"></i>Grant Access
    </button>`;
  container.prepend(div);
}

async function requestVerifyFolderPermission() {
  if (!verifyDirHandle) return;
  try {
    const status = await verifyDirHandle.requestPermission({ mode: 'readwrite' });
    document.getElementById('verifyGrantAccessPrompt')?.remove();
    if (status === 'granted') {
      showToast(`Access granted to folder "${verifyDirHandleName}"`, 'success');
      // Re-render whichever document is currently selected so its preview
      // switches over to the local file now that we have access.
      const activeEl = document.querySelector('.queue-item.active');
      if (activeEl) selectValidateDoc(activeEl, false);
    } else {
      showToast('Access was not granted — previews will keep loading from the server.', 'warning');
    }
  } catch (err) {
    console.warn('Error requesting folder permission:', err);
  }
}
window.requestVerifyFolderPermission = requestVerifyFolderPermission;

// Restores the connection to the folder this page is currently filtered
// to (if any), so previews can be read live from disk instead of
// storage_path. Called once on page load.
async function restoreVerifyFolderHandle() {
  if (!currentFilterFolderName) return;
  const dirHandle = await getVerifyFolderHandle(currentFilterFolderName);
  if (!dirHandle) return; // never browsed locally this session (or a different device) — storage_path fallback covers it

  let permission = 'prompt';
  try { permission = await dirHandle.queryPermission({ mode: 'readwrite' }); } catch (_) { /* not supported */ }

  verifyDirHandle     = dirHandle;
  verifyDirHandleName = currentFilterFolderName;

  if (permission !== 'granted') {
    renderVerifyGrantAccessPrompt(currentFilterFolderName, dirHandle);
  }
}

// Looks up the currently selected document's file directly in the
// connected local folder (trying the renamed name first, then the
// original raw name, since either could be what's actually on disk
// depending on whether it's been renamed yet). Returns an object URL, or
// null if there's no connected folder / permission / matching file —
// callers fall back to storage_path in that case.
async function tryLoadLocalPreview(doc) {
  if (!verifyDirHandle) return null;
  let permission = 'prompt';
  try { permission = await verifyDirHandle.queryPermission({ mode: 'read' }); } catch (_) { /* not supported */ }
  if (permission !== 'granted') return null;

  const candidates = [doc.renamed_filename, doc.raw_filename].filter(Boolean);
  for (const name of candidates) {
    try {
      const fileHandle = await verifyDirHandle.getFileHandle(name);
      const file = await fileHandle.getFile();
      return URL.createObjectURL(file);
    } catch (_) {
      // not present under this name — try the next candidate
    }
  }
  return null;
}

// ── Change tracking ──────────────────────────────────────────────
// Any edit the validator makes to a metadata field, relative to what was
// loaded for the currently selected document, means the original
// rename/scan data was wrong — so Validate should record it as
// Changed (incorrect) instead of Approved.
const VMETA_FIELDS = ['vPH','vSEC','vPlot','vExt','vClass','vFileName','vPageNo','vPageCount','vDate','vMouaza','vLandFileNo','vLandOwnerName','vKanal','vMarla','vSquareFoot','vSaledeed','vLitigationNo','vScanNo'];
const VMETA_LABELS = {
  vPH: 'PH',
  vSEC: 'SEC',
  vPlot: 'PLOT NO',
  vExt: 'EXTENSION',
  vClass: 'Class Name',
  vFileName: 'File Name',
  vPageNo: 'Page No',
  vPageCount: 'Page Count',
  vDate: 'Date',
  vMouaza: 'Mouaza',
  vLandFileNo: 'File No',
  vLandOwnerName: 'Land Owner Name',
  vKanal: 'Kanal',
  vMarla: 'Marla',
  vSaledeed: 'Saledeed',
  vScanNo: 'Scan No'
};
let vOriginalMeta  = {};
let vDataChanged   = false;

// After Validate runs and the page reloads, keyboard focus used to loop
// back to the BRANCH dropdown at the top of the panel — so the next Tab
// press walked back through BRANCH, Browse, and the read-only PH field
// before reaching anything editable again. This instead jumps straight to
// CLASS NAME specifically (mirrors rename.php, where SEC is readonly so
// CLASS NAME naturally comes first) — the workflow should stay parked on
// CLASS NAME document after document, not drift to SEC just because SEC
// happens to be editable here too. Falls back to the first other editable,
// visible metadata field (VMETA_FIELDS order — e.g. the Land Acquisition
// fields when CLASS NAME itself is hidden for that branch), then to
// BRANCH only if nothing editable is visible at all.
function vFocusFirstEditableField() {
  const classEl = document.getElementById('vClass');
  if (classEl && !classEl.readOnly && !classEl.disabled && classEl.offsetParent !== null) {
    classEl.focus();
    return;
  }
  const targetId = VMETA_FIELDS.find(id => {
    const el = document.getElementById(id);
    return el && !el.readOnly && !el.disabled && el.offsetParent !== null;
  });
  const el = targetId ? document.getElementById(targetId) : null;
  if (el) {
    el.focus();
  } else {
    const topEl = document.getElementById('vBranchSelect');
    if (topEl) topEl.focus();
  }
}

function vCaptureOriginalMeta() {
  vOriginalMeta = {};
  VMETA_FIELDS.forEach(id => {
    const el = document.getElementById(id);
    vOriginalMeta[id] = el ? el.value : '';
  });
  vDataChanged = false;
  vUpdateChangeUI();
}

function vGetChangeDetails() {
  const changes = [];
  VMETA_FIELDS.forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      const newVal = el.value.trim();
      const oldVal = (vOriginalMeta[id] ?? '').trim();
      if (newVal !== oldVal) {
        const label = VMETA_LABELS[id] || id;
        changes.push(`${label}: "${oldVal || '—'}" → "${newVal || '—'}"`);
      }
    }
  });
  return changes;
}

function vCheckForChanges() {
  const changes = vGetChangeDetails();
  vDataChanged = changes.length > 0;
  vUpdateChangeUI(changes);
}

// ── CLASS NAME Combo Box — mirrors pages/rename.php's dropdown, built from
// the same shared ALLOWED_CLASSES list (assets/js/allowed_classes.js) so a
// class chosen at Rename time shows up identically here and can be
// corrected from the same kind of dropdown. ──────────────────────────────

// Maps a class name's prefix to the BRANCH tab it belongs to, same
// grouping rename.php uses for its dropdown's data-tab attribute.
function vClassNameTab(className) {
  if (className.startsWith('BC_'))   return 'Building Control';
  if (className.startsWith('ACQN_')) return 'Land Acquisition';
  if (className.startsWith('TFR_'))  return 'Transfer';
  return 'all';
}

function buildVClassDropdown() {
  const dd = document.getElementById('vClassDropdown');
  if (!dd || typeof ALLOWED_CLASSES === 'undefined') return;
  dd.innerHTML = ALLOWED_CLASSES.map(name =>
    `<div class="class-combo-item" data-tab="${vClassNameTab(name)}" onclick="selectVClassOption('${name.replace(/'/g, "\\'")}')">${name}</div>`
  ).join('');
  applyVTabFiltering();
}

// Show only items matching the active BRANCH tab (or "all"); hide the rest —
// same behaviour as rename.php's applyTabFiltering().
function applyVTabFiltering() {
  const cat = document.getElementById('vBranchSelect')?.value || '';
  document.querySelectorAll('#vClassDropdown .class-combo-item').forEach(el => {
    const itemTab = el.getAttribute('data-tab');
    if (!cat || itemTab === 'all' || itemTab === cat) {
      el.classList.remove('tab-filtered-out');
    } else {
      el.classList.add('tab-filtered-out');
    }
  });
}

function toggleVClassDropdown() {
  const dd = document.getElementById('vClassDropdown');
  const icon = document.getElementById('vClassArrowIcon');
  if (!dd || !icon) return;
  const isOpen = dd.classList.contains('open');
  if (isOpen) {
    dd.classList.remove('open');
    icon.style.transform = 'rotate(0deg)';
  } else {
    document.querySelectorAll('#vClassDropdown .class-combo-item').forEach(el => el.classList.remove('hidden'));
    applyVTabFiltering();
    dd.classList.add('open');
    icon.style.transform = 'rotate(180deg)';
    document.getElementById('vClass')?.focus();
  }
}

function filterVClassOptions() {
  const input = document.getElementById('vClass');
  const dd = document.getElementById('vClassDropdown');
  if (!input || !dd) return;
  const query = input.value.toLowerCase();
  let anyVisible = false;
  dd.querySelectorAll('.class-combo-item').forEach(el => {
    if (el.classList.contains('tab-filtered-out')) return;
    if (el.textContent.toLowerCase().includes(query)) {
      el.classList.remove('hidden');
      anyVisible = true;
    } else {
      el.classList.add('hidden');
    }
  });
  if (anyVisible && query.length > 0) {
    dd.classList.add('open');
    document.getElementById('vClassArrowIcon').style.transform = 'rotate(180deg)';
  }
}

function selectVClassOption(value) {
  const input = document.getElementById('vClass');
  if (!input) return;
  input.value = value;
  const dd = document.getElementById('vClassDropdown');
  if (dd) dd.classList.remove('open');
  const icon = document.getElementById('vClassArrowIcon');
  if (icon) icon.style.transform = 'rotate(0deg)';
  onVClassChange();
}

function onVClassChange() {
  // Mirrors pages/rename.php's onClassChange(): whatever the validator
  // picks/types for CLASS NAME, strip the branch prefix (TFR_/BC_/ACQN_)
  // before the first underscore and use the remainder to update FILE NAME
  // — so FILE NAME never has to be corrected by hand after CLASS NAME is
  // changed here, same as on the Rename page.
  const clsVal = document.getElementById('vClass').value.trim();
  if (clsVal && clsVal.includes('_')) {
    const afterUnderscore = clsVal.split('_').slice(1).join('_');
    if (afterUnderscore) {
      const fileNameInput = document.getElementById('vFileName');
      if (fileNameInput) fileNameInput.value = afterUnderscore;
    }
  }
  vCheckForChanges();
}

// Close the CLASS NAME dropdown when clicking outside it.
document.addEventListener('click', function(e) {
  const wrap = document.querySelector('#vClassNameField .class-combo-wrap');
  const dd = document.getElementById('vClassDropdown');
  const btn = document.querySelector('#vClassNameField .class-combo-arrow');
  if (!wrap || !dd) return;
  if (!wrap.contains(e.target) && !dd.contains(e.target) && !(btn && btn.contains(e.target))) {
    dd.classList.remove('open');
    const icon = document.getElementById('vClassArrowIcon');
    if (icon) icon.style.transform = 'rotate(0deg)';
  }
});

function vUpdateChangeUI(changes) {
  if (!changes) changes = vGetChangeDetails();
  const indicator = document.getElementById('vChangeIndicator');
  if (indicator) {
    if (vDataChanged) {
      indicator.style.display = 'block';
      indicator.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Will be marked <strong>Validated after Correction</strong>:<br>' +
        changes.map(c => `• ${c}`).join('<br>');
    } else {
      indicator.style.display = 'none';
    }
  }

  // Live update above the form: preview title, badge, and queue item text
  const activeEl = document.querySelector('.queue-item.active');
  const doc = activeEl ? JSON.parse(activeEl.dataset.doc) : null;
  if (doc) {
    const updatedName = buildValidateRenamedFilename(doc);
    if (updatedName) {
      const previewTitle = document.getElementById('previewTitle');
      const badge = document.getElementById('currentDocBadge');
      if (previewTitle) previewTitle.textContent = 'Document Preview: ' + updatedName;
      if (badge) badge.textContent = updatedName;

      const itemSpan = activeEl.querySelector('span');
      if (itemSpan) itemSpan.textContent = updatedName;
    }
  }

  const btn = document.getElementById('btnTriggerValidate');
  if (btn) {
    if (vDataChanged) {
      btn.style.background = '#dc2626';
      btn.innerHTML = '<i class="fas fa-times me-2"></i>Validate (After Correction)';
    } else {
      btn.style.background = '#15803d';
      btn.innerHTML = '<i class="fas fa-check me-2"></i>Validate';
    }
  }
}

function setVTab(name, autoSelectFirst = true) {
  document.querySelectorAll('.workflow-tab').forEach(b => b.classList.remove('active'));
  const map = {'Transfer':'tabTransfer','Building Control':'tabBuilding','Land Acquisition':'tabLand'};
  if (map[name]) document.getElementById(map[name]).classList.add('active');

  // Land Acquisition uses its own field set (Mouaza, File No, Land Owner
  // Name, Kanal, Marla, Saledeed, Scan No) in place of SEC, PLOT NO, CLASS
  // NAME, FILE NAME, PAGE NO, PAGE COUNT and DATE — mirrors rename.php.
  const isLand = (name === 'Land Acquisition');
  const vLandFields = document.getElementById('vLandExtraFields');
  if (vLandFields) vLandFields.style.display = isLand ? '' : 'none';
  ['vSecField', 'vPlotField', 'vExtField', 'vClassNameField', 'vFileNameField', 'vPageDateFields'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = isLand ? 'none' : '';
  });

  // Keep the visible BRANCH dropdown in sync whenever the tab is set from
  // somewhere other than the dropdown itself (e.g. on initial page load).
  const branchSelect = document.getElementById('vBranchSelect');
  if (branchSelect && branchSelect.value !== name) branchSelect.value = name;

  // Keep the CLASS NAME dropdown's options scoped to whichever BRANCH is
  // now active, same as rename.php.
  applyVTabFiltering();

  // Filter the Document Queue items based on active tab
  let count = 0;
  let firstVisible = null;
  document.querySelectorAll('.queue-item').forEach(el => {
    const doc = JSON.parse(el.dataset.doc);
    const docCategory = getDocCategory(doc);

    if (docCategory === name) {
      el.style.setProperty('display', 'flex', 'important');
      if (!firstVisible) firstVisible = el;
      count++;
    } else {
      el.style.setProperty('display', 'none', 'important');
    }
  });

  // Update the count display for the queue
  const countEl = document.getElementById('queueCount');
  if (countEl) countEl.textContent = count;

  // Auto-select first matching document in the filtered list
  if (autoSelectFirst) {
    if (firstVisible) {
      selectValidateDoc(firstVisible, false);
    } else {
      // Clear preview and metadata fields if no documents match
      vCurrentDocId  = null;
      vCurrentCanAct = false;
      document.getElementById('previewTitle').textContent = 'Document Preview';
      document.getElementById('currentDocBadge').textContent = 'None Selected';
      document.getElementById('docPreviewArea').innerHTML = `
        <div class="text-center text-secondary py-5">
          <i class="fas fa-file fa-4x mb-3 opacity-30"></i>
          <p>No documents in this category</p>
        </div>`;
      const zoomOverlay = document.getElementById('zoomOverlay');
      if (zoomOverlay) zoomOverlay.style.display = 'none';

      ['vPH','vSEC','vPlot','vExt','vClass','vFileName','vPageNo','vPageCount','vDate'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
      });
      vCaptureOriginalMeta();
      const validateBtn = document.getElementById('btnTriggerValidate');
      if (validateBtn) validateBtn.disabled = true;
    }
  }
}

/**
 * Returns the workflow fields for a document.
 */
function parseRenamedFilename(doc) {
  if (doc && doc.rename_meta) {
    try {
      const meta = JSON.parse(doc.rename_meta);
      return {
        vClass:     meta.vClass     ?? '',
        vPH:        meta.vPH        ?? '',
        vSEC:       meta.vSEC       ?? '',
        vPlot:      meta.vPlot      ?? '',
        vExt:       meta.vExt       ?? '',
        vFileName:  meta.vFileName  ?? '',
        vPageNo:    meta.vPageNo    ?? '',
        vPageCount: meta.vPageCount ?? '',
        vDate:      (meta.vDate ?? '').replace(/\//g, '-'),
      };
    } catch (e) {
      // corrupt JSON — fall through to legacy parsing below
    }
  }

  // Legacy fallback (only for documents renamed before rename_meta existed)
  const f = {vClass:'',vPH:'',vSEC:'',vPlot:'',vExt:'',vFileName:'',vPageNo:'',vPageCount:'',vDate:''};
  const renamed = (doc && doc.renamed_filename) || '';
  if (!renamed) return f;

  // Clean prefix underscore and extension, split by "_" and filter out any empty parts
  const base  = renamed.replace(/^_/,'').replace(/\.[^.]+$/,'');
  const parts = base.split('_').filter(p => p !== '');

  // Find the 4-digit Year index
  let yearIndex = -1;
  for (let i = 0; i < parts.length; i++) {
    if (/^\d{4}$/.test(parts[i])) {
      yearIndex = i;
      break;
    }
  }

  if (yearIndex !== -1) {
    // 1. Date (Day, Month, Year)
    const day = parts[yearIndex - 2] || '';
    const month = parts[yearIndex - 1] || '';
    const year = parts[yearIndex] || '';
    if (day && month && year) {
      f.vDate = `${day}/${month}/${year}`;
    }

    // 2. Doc Number index
    const docNoIndex = yearIndex - 3;

    // 3. Page No & Page Count (after Year)
    const afterYear = parts.slice(yearIndex + 1);
    if (afterYear.length === 1) {
      f.vPageNo = afterYear[0];
    } else if (afterYear.length >= 2) {
      f.vPageNo = afterYear[0];
      f.vPageCount = afterYear[1];
    }

    // 4. File Name (immediately before Doc Number)
    if (docNoIndex - 1 >= 0) {
      f.vFileName = parts[docNoIndex - 1];
    }

    // 5. Branch and Class Name (from the start)
    let classStartIndex = 1; // Default for "Transfer"
    if (parts[0] === 'Building' && parts[1] === 'Control') {
      classStartIndex = 2;
    } else if (parts[0] === 'Land' && parts[1] === 'Acquisition') {
      classStartIndex = 2;
    }

    // 6. Phase, Sector, Plot
    const dbPlot = (doc && doc.plot) || '';
    const dbBranch = (doc && doc.branch) || '';
    const dbPhase = (doc && doc.phase) || '';

    let currentIdx = docNoIndex - 2;
    if (dbPlot && currentIdx >= classStartIndex && parts[currentIdx] === dbPlot) {
      f.vPlot = parts[currentIdx];
      currentIdx--;
    }
    if (dbBranch && currentIdx >= classStartIndex && parts[currentIdx] === dbBranch) {
      f.vSEC = parts[currentIdx];
      currentIdx--;
    }
    if (dbPhase && currentIdx >= classStartIndex && parts[currentIdx] === dbPhase) {
      f.vPH = parts[currentIdx];
      currentIdx--;
    }

    // Everything left between classStartIndex and currentIdx is the Class Name
    if (currentIdx >= classStartIndex) {
      f.vClass = parts.slice(classStartIndex, currentIdx + 1).join('_');
    }
  } else {
    // If no 4-digit Year is found, fall back to simple positional splitting
    if (parts[0] !== undefined) f.vClass     = parts[0];
    if (parts[1] !== undefined) f.vPH        = parts[1];
    if (parts[2] !== undefined) f.vSEC       = parts[2];
    if (parts[3] !== undefined) f.vPlot      = parts[3];
    if (parts[4] !== undefined) f.vFileName  = parts[4];
    if (parts[5] !== undefined) f.vPageNo    = parts[5];
    if (parts[6] !== undefined) f.vPageCount = parts[6];
  }

  return f;
}

/**
 * Determines which workflow tab (Transfer / Building Control / Land
 * Acquisition) a document belongs to.
 *
 * Previously derived by splitting doc.renamed_filename on '_' and checking
 * the first token for 'BC' / 'ACQN' — that worked only because Class Name
 * (e.g. 'ACQN_Advertisement') used to be the first segment of every
 * renamed file. The Land Acquisition token format no longer starts with
 * the class name (it starts with Phase), so filename-prefix detection no
 * longer works for Land docs. We now read Class Name from the rename_meta
 * snapshot instead (via parseRenamedFilename), and only fall back to the
 * old filename-prefix check for very old rows with neither rename_meta
 * nor a class name.
 */
function getDocCategory(doc) {
  // 1) Preferred: the explicit branch marker saved at rename time. Works
  //    even when Class Name is empty/hidden, as it is for Land Acquisition.
  if (doc && doc.rename_meta) {
    try {
      const meta = JSON.parse(doc.rename_meta);
      const catMap = { 'Transfer': 'Transfer', 'Building_Control': 'Building Control', 'Land_Acquisition': 'Land Acquisition' };
      if (meta.vCategory && catMap[meta.vCategory]) return catMap[meta.vCategory];
    } catch (e) { /* fall through */ }
  }

  // 2) Fallback: Class Name prefix (older documents saved before vCategory existed).
  const f = parseRenamedFilename(doc);
  const cls = (f.vClass || '').trim();
  if (cls.startsWith('BC_'))   return 'Building Control';
  if (cls.startsWith('ACQN_')) return 'Land Acquisition';
  if (cls) return 'Transfer';

  // 3) Last resort: legacy rows with neither vCategory nor a class name.
  const renamed = doc.renamed_filename || '';
  const base = renamed.replace(/^_/,'').replace(/\.[^.]+$/,'');
  const prefix = base.split('_')[0] || '';
  if (prefix === 'BC') return 'Building Control';
  if (prefix === 'ACQN') return 'Land Acquisition';
  return 'Transfer';
}

/**
 * Land Acquisition's renamed filename has a fixed, position-based format —
 * Phase_Mouaza_FileNo_LandOwnerName_Kanal_Marla_Saledeed_ScanNo_.PDF — with
 * no date to anchor on (unlike parseRenamedFilename's year-index logic), so
 * a straight positional split is reliable here.
 */
function parseLandFilename(doc) {
  const f = { vLandFileNo:'', vMouaza:'', vLandOwnerName:'', vKanal:'', vMarla:'', vSquareFoot:'', vSaledeed:'', vLitigationNo:'', vScanNo:'' };
  const renamed = (doc && doc.renamed_filename) || '';
  if (!renamed) return f;

  const base = renamed.replace(/^_/,'').replace(/\.[^.]+$/,'');
  const parts = base.split('_').filter(p => p !== '');

  // Token order: FileNo_Mouaza_OwnerName_Phase_Kanal_Marla_SquareFoot_SaleDeedNo_LiticationNo_ScanNo
  // parts[3] is Phase — already shown in the PH field via doc.phase, not read from here.
  f.vLandFileNo    = parts[0] || '';
  f.vMouaza        = parts[1] || '';
  f.vLandOwnerName = parts[2] || '';
  f.vKanal         = parts[4] || '';
  f.vMarla         = parts[5] || '';
  f.vSquareFoot    = parts[6] || '';
  f.vSaledeed      = parts[7] || '';
  f.vLitigationNo  = parts[8] || '';
  f.vScanNo        = parts[9] || '';
  return f;
}

function selectValidateDoc(el, updateTab = true) {
  document.querySelectorAll('.queue-item').forEach(i => i.classList.remove('active'));
  el.classList.add('active');

  const doc        = JSON.parse(el.dataset.doc);
  vCurrentDocId    = doc.document_id;
  vCurrentCanAct   = el.dataset.canaction === '1';

  // Badge + title
  const label = doc.renamed_filename || doc.raw_filename;
  document.getElementById('previewTitle').textContent    = 'Document Preview: ' + label;
  document.getElementById('currentDocBadge').textContent = label;

  // Preview — try the connected local folder first (reads the actual file
  // straight off disk via the folder handle from Browse); falls back to
  // the server's storage_path if no folder is connected, permission
  // hasn't been (re-)granted yet, or the file isn't found under either
  // its raw or renamed name.
  const area = document.getElementById('docPreviewArea');
  const zoom = document.getElementById('zoomOverlay');
  vZoomLevel = 100;
  document.getElementById('zoomPercent').textContent = '100%';

  if (vCurrentPreviewBlobUrl) { URL.revokeObjectURL(vCurrentPreviewBlobUrl); vCurrentPreviewBlobUrl = null; }

  function renderVPreview(src) {
    if (doc.file_type === 'pdf' && src) {
      area.innerHTML = `<iframe src="${src}" style="border:none;border-radius:8px;width:100%;height:100%;max-width:100%;display:block;overflow:hidden;"></iframe>`;
      zoom.style.display = 'none';
    } else if (['jpg','jpeg','png','tiff','tif'].includes(doc.file_type) && src) {
      area.innerHTML = `<div style="width:100%;height:100%;overflow-y:auto;overflow-x:hidden;padding:20px;display:flex;align-items:center;justify-content:center;">
        <img id="vPreviewImg" src="${src}" alt="" style="max-width:100%;max-height:100%;object-fit:contain;transition:transform .2s;transform-origin:center;"></div>`;
      zoom.style.display = 'flex';
    } else {
      area.innerHTML = `<div class="text-center text-secondary py-5"><i class="fas fa-file fa-4x mb-3 opacity-50"></i><p>Preview not available</p></div>`;
      zoom.style.display = 'none';
    }
  }

  const fallbackPath = doc.storage_path ? ('../' + doc.storage_path) : null;
  renderVPreview(fallbackPath); // show immediately so there's no blank/loading gap

  const docIdAtSelect = doc.document_id;
  tryLoadLocalPreview(doc).then(localUrl => {
    if (!localUrl) return;
    if (vCurrentDocId !== docIdAtSelect) { URL.revokeObjectURL(localUrl); return; } // user already moved on
    vCurrentPreviewBlobUrl = localUrl;
    renderVPreview(localUrl);
  });

  // Get the exact fields (from rename_meta snapshot, with legacy fallback)
  const f = parseRenamedFilename(doc);

  // Phase / Sector / Plot: prefer saved DB report columns if present,
  // otherwise fall back to the rename snapshot fields
  document.getElementById('vPH').value        = doc.phase  || f.vPH;
  document.getElementById('vSEC').value       = doc.branch || f.vSEC;
  document.getElementById('vPlot').value      = doc.plot   || f.vPlot;
  if (document.getElementById('vExt')) {
    document.getElementById('vExt').value     = f.vExt     || '';
  }
  document.getElementById('vClass').value     = f.vClass;
  document.getElementById('vFileName').value  = f.vFileName;
  document.getElementById('vPageNo').value    = f.vPageNo;
  document.getElementById('vPageCount').value = f.vPageCount;
  document.getElementById('vDate').value      = f.vDate;

  // Land Acquisition fields — parsed positionally from the filename (see
  // parseLandFilename) since this branch's values aren't part of the
  // standard rename_meta snapshot.
  const lf = parseLandFilename(doc);
  document.getElementById('vMouaza').value        = lf.vMouaza;
  document.getElementById('vLandFileNo').value    = lf.vLandFileNo;
  document.getElementById('vLandOwnerName').value = lf.vLandOwnerName;
  document.getElementById('vKanal').value         = lf.vKanal;
  document.getElementById('vMarla').value         = lf.vMarla;
  document.getElementById('vSquareFoot').value    = lf.vSquareFoot;
  document.getElementById('vSaledeed').value      = lf.vSaledeed;
  document.getElementById('vLitigationNo').value  = lf.vLitigationNo;
  document.getElementById('vScanNo').value        = lf.vScanNo;

  // Snapshot the values just loaded so any later edit by the validator can
  // be detected before they click Validate (see vCheckForChanges below).
  vCaptureOriginalMeta();

  // Automatically select the correct tab branch based on the document's
  // Class Name (see getDocCategory) rather than the filename prefix, since
  // Land Acquisition filenames no longer start with the class name.
  if (updateTab) {
    setVTab(getDocCategory(doc), false);
  }

  // Show/hide action buttons:
  // Show if user can act AND document is pending
  // Also allow admins to re-verify already-decided documents
  // The server already computed the full permission decision in data-canaction
  // (vCurrentCanAct) — trust it directly instead of re-deriving it here, so the
  // two checks can't drift out of sync.
  const canReVerify = vCurrentCanAct;
  const validateBtn = document.getElementById('btnTriggerValidate');
  if (validateBtn) {
    validateBtn.disabled = !canReVerify;
    validateBtn.title = canReVerify ? '' : 'You do not have permission to validate this document';
  }

  // Toggle input readonly status depending on whether the current user is allowed to action/edit
  const inputs = ['vSEC', 'vPlot', 'vExt', 'vClass', 'vFileName', 'vPageNo', 'vPageCount', 'vDate'];
  inputs.forEach(id => {
    const inputEl = document.getElementById(id);
    if (inputEl) {
      if (canReVerify) {
        inputEl.removeAttribute('readonly');
        inputEl.style.cursor = 'text';
      } else {
        inputEl.setAttribute('readonly', 'true');
        inputEl.style.cursor = 'default';
      }
    }
  });

  el.scrollIntoView({behavior:'smooth',block:'nearest'});
}

// Rebuilds the renamed filename exactly the way the server does in the
// Approve/Change POST handler above (Land Acquisition token order vs.
// Standard token order), so the local disk copy can be kept in sync with
// whatever the validator corrected — same fields, same order, same rules.
function buildValidateRenamedFilename(doc) {
  const category = getDocCategory(doc);
  const currentName = doc?.renamed_filename || doc?.raw_filename || '';
  const ext = (currentName.split('.').pop() || 'pdf');
  const val = id => (document.getElementById(id)?.value || '').trim();

  if (category === 'Land Acquisition') {
    const landParts = [val('vLandFileNo'), val('vMouaza'), val('vLandOwnerName'), val('vPH'), val('vKanal'), val('vMarla'), val('vSquareFoot'), val('vSaledeed'), val('vLitigationNo'), val('vScanNo')].filter(Boolean);
    if (landParts.length === 0) return currentName;
    return landParts.join('_') + '_.PDF';
  }

  const vDate = val('vDate');
  let dateToken = '';
  if (vDate) {
    const dateBits = vDate.split(/[\/\-]/);
    if (dateBits.length === 3) {
      dateToken = `${dateBits[0]}-${dateBits[1]}-${dateBits[2]}`;
    }
  }

  const vPlotVal = val('vPlot');
  let fullPlot = vPlotVal.replace(/[\/\\]/g, '-');

  const parts = [];
  if (val('vClass'))     parts.push(val('vClass'));
  if (val('vPH'))        parts.push(val('vPH'));
  if (val('vSEC'))       parts.push(val('vSEC'));
  if (fullPlot)          parts.push(fullPlot);
  if (val('vFileName'))  parts.push(val('vFileName'));
  if (dateToken)         parts.push(dateToken);
  if (val('vPageNo'))    parts.push(val('vPageNo'));
  if (val('vPageCount')) parts.push(val('vPageCount'));

  if (parts.length === 0) return currentName;
  return '_' + parts.join('_') + '.' + ext;
}

// If a local folder is connected (see restoreVerifyFolderHandle /
// browseVerifyFolder), keeps the actual file on disk in sync with a
// correction made here — mirroring the server's own physical rename of
// its copy — so the two never drift apart. Silently no-ops (server copy
// still gets renamed as usual) if no folder is connected, permission
// isn't granted, or the local file can't be found under its current name.
async function renameLocalCopyIfConnected(doc, newName) {
  if (!newName || newName === (doc.renamed_filename || doc.raw_filename)) return;
  if (!verifyDirHandle) {
    console.warn('renameLocalCopyIfConnected: no verifyDirHandle — folder was never connected in this session.');
    if (typeof showToast === 'function') {
      showToast('No local folder connected — only the server copy was renamed. Click Browse to connect the folder for local renaming.', 'warning', 8000);
    }
    return;
  }
  let permission = 'prompt';
  try { permission = await verifyDirHandle.queryPermission({ mode: 'readwrite' }); } catch (e) { console.warn('queryPermission threw:', e); }
  if (permission !== 'granted') {
    console.warn('renameLocalCopyIfConnected: permission is "' + permission + '", not "granted" — local file was not renamed.');
    if (typeof showToast === 'function') {
      showToast(`Folder permission is "${permission}" — click Grant Access (or Browse again) so local files can be renamed.`, 'warning', 8000);
    }
    return;
  }

  const oldName = doc.renamed_filename || doc.raw_filename;
  try {
    const oldFileHandle = await verifyDirHandle.getFileHandle(oldName);
    if (typeof oldFileHandle.move === 'function') {
      await oldFileHandle.move(newName);
    } else {
      const oldFile = await oldFileHandle.getFile();
      const newFileHandle = await verifyDirHandle.getFileHandle(newName, { create: true });
      const writable = await newFileHandle.createWritable();
      await writable.write(oldFile);
      await writable.close();
      await verifyDirHandle.removeEntry(oldName);
    }
    if (typeof showToast === 'function') {
      showToast(`Local file renamed: "${oldName}" → "${newName}"`, 'success', 5000);
    }
  } catch (err) {
    console.error(`renameLocalCopyIfConnected: failed to rename "${oldName}" to "${newName}" in folder "${verifyDirHandleName}":`, err.name, err.message);
    if (typeof showToast === 'function') {
      showToast(`Could not rename local file "${oldName}" (${err.name}: ${err.message}). Server copy was still renamed.`, 'error', 10000);
    }
    // Local file not found under its current name (folder may not be the
    // one this document actually lives in) — leave it alone, the server
    // copy is still the source of truth.
    console.warn('Could not rename local copy for', oldName, err);
  }
}

async function triggerApprove() {
  if (!vCurrentDocId || !vCurrentCanAct) return;

  // Re-check right before submitting in case a change wasn't caught yet.
  vCheckForChanges();

  const changes = vGetChangeDetails();
  const changeSummary = changes.join('; ');

  const confirmMsg = vDataChanged
    ? `You corrected the metadata for this document:\n${changes.join('\n')}\n\nIt will be recorded as Validated after Correction. Continue?`
    : 'Validate this document?';
  if (!confirm(confirmMsg)) return;

  if (vDataChanged) {
    const activeEl = document.querySelector('.queue-item.active');
    const doc = activeEl ? JSON.parse(activeEl.dataset.doc) : null;
    if (doc) {
      const newName = buildValidateRenamedFilename(doc);
      await renameLocalCopyIfConnected(doc, newName);
    }
  }

  const form = document.getElementById('approveForm');
  document.getElementById('approveDocId').value = vCurrentDocId;
  document.getElementById('approveAction').value  = vDataChanged ? 'change' : 'approve';
  document.getElementById('approveRemarks').value = vDataChanged
    ? `Validated after Correction — ${changeSummary}`
    : 'Approved.';

  // Clear any previously appended fields
  form.querySelectorAll('.temp-meta-field').forEach(el => el.remove());

  // Append current metadata field values
  const fields = ['vPH', 'vSEC', 'vPlot', 'vClass', 'vFileName', 'vPageNo', 'vPageCount', 'vDate', 'vMouaza', 'vLandFileNo', 'vLandOwnerName', 'vKanal', 'vMarla', 'vSquareFoot', 'vSaledeed', 'vLitigationNo', 'vScanNo'];
  fields.forEach(id => {
    const val = document.getElementById(id).value;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = id;
    input.value = val;
    input.className = 'temp-meta-field';
    form.appendChild(input);
  });

  // This form submit is a full page reload (not AJAX), so JS state resets
  // before we could focus anything directly. Leave a flag that survives the
  // reload — checked in the DOMContentLoaded handler below — so keyboard
  // focus loops back to the top of the Verify Workflow panel once the page
  // comes back, ready for the next document.
  sessionStorage.setItem('dha_focus_top_after_validate', '1');

  // Also persist the active BRANCH tab across the reload — otherwise
  // BRANCH snaps back to "None" after every single Validate, forcing the
  // validator to reselect it (and re-filter the queue) for every document
  // in the folder instead of staying put until the folder is done. Reuses
  // the same sessionStorage key / restore logic goToFolderVerifyQueue()
  // already relies on after a folder Browse.
  const activeBranchForReload = document.getElementById('vBranchSelect')?.value || '';
  if (activeBranchForReload) {
    sessionStorage.setItem('dha_verify_active_branch', activeBranchForReload);
  }

  form.submit();
}

function vZoom(dir) {
  const img = document.getElementById('vPreviewImg');
  if (!img) return;
  vZoomLevel = dir === 'in' ? Math.min(300, vZoomLevel + 20) : Math.max(40, vZoomLevel - 20);
  img.style.transform = `scale(${vZoomLevel / 100})`;
  document.getElementById('zoomPercent').textContent = vZoomLevel + '%';
}

// ── Browse a folder: only files ready for verification are ever shown ──
// Verify documents already live on the server once renamed (unlike Rename,
// which needs live local file access), so browsing here doesn't read file
// contents at all — it just reads the folder's NAME, then reloads the page
// filtered to that folder. The existing folder_id filter (status='pending'
// + file_exists() check) does the actual work of only showing documents
// from that folder that still need verification.
// Folders picked but not yet confirmed with "Start Verifying" — in the
// exact order they were selected. Cleared on Start (moved to the real,
// sessionStorage-backed queue) or on a fresh page load.
let pendingVerifyFolders = [];

function renderVerifyFolderQueuePanel() {
  const panel = document.getElementById('verifyFolderQueuePanel');
  const list  = document.getElementById('verifyFolderQueueList');
  if (!panel || !list) return;
  if (pendingVerifyFolders.length === 0) {
    panel.classList.add('d-none');
    return;
  }
  panel.classList.remove('d-none');
  list.innerHTML = '<strong>Will process, in this order:</strong><br>' +
    pendingVerifyFolders.map((f, i) => `${i + 1}. ${f.replace(/</g, '&lt;')}`).join('<br>');
}

async function browseVerifyFolder() {
  if ('showDirectoryPicker' in window) {
    try {
      const dirHandle = await window.showDirectoryPicker({ mode: 'readwrite' });

      // If the picked folder contains subfolders rather than files
      // directly, treat it as a container and auto-queue every subfolder
      // (alphabetical order) instead of adding just the one folder picked.
      const subDirs = [];
      for await (const entry of dirHandle.values()) {
        if (entry.kind === 'directory' && !entry.name.startsWith('.')) subDirs.push(entry);
      }

      if (subDirs.length > 0) {
        subDirs.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));
        const names = [];
        for (const subEntry of subDirs) {
          await saveVerifyFolderHandle(subEntry.name, subEntry);
          names.push(subEntry.name);
        }
        const first = names.shift();
        sessionStorage.setItem('dha_verify_folder_queue', JSON.stringify(names));
        if (typeof showToast === 'function') {
          showToast(`Queued ${subDirs.length} folders from "${dirHandle.name}" — starting with "${first}".`, 'success');
        }
        goToFolderVerifyQueue(first, true);
        return;
      }

      await saveVerifyFolderHandle(dirHandle.name, dirHandle);
      if (!pendingVerifyFolders.includes(dirHandle.name)) pendingVerifyFolders.push(dirHandle.name);
      renderVerifyFolderQueuePanel();
      return;
    } catch (err) {
      if (err.name === 'AbortError') return; // user cancelled — stay put
      console.warn('showDirectoryPicker unavailable or cancelled, falling back to input:', err);
    }
  }
  document.getElementById('verifyBrowseFileInput').click();
}

function handleVerifyBrowsedFolder(input) {
  const files = input.files;
  input.value = ''; // reset so picking the same folder again still fires change
  if (!files || files.length === 0) return;
  // webkitRelativePath looks like "FolderName/sub/file.pdf" — first segment is the folder name
  const rel = files[0].webkitRelativePath || '';
  const folderName = rel.split('/')[0] || '';
  if (folderName) {
    if (!pendingVerifyFolders.includes(folderName)) pendingVerifyFolders.push(folderName);
    renderVerifyFolderQueuePanel();
  }
}

// Confirms the picked folders and kicks off processing, starting with the
// first one — the rest are stored so the queue can auto-advance once each
// folder empties (see advanceVerifyFolderQueueIfEmpty below).
function startVerifyFolderQueue() {
  if (pendingVerifyFolders.length === 0) return;
  sessionStorage.setItem('dha_verify_folder_queue', JSON.stringify(pendingVerifyFolders));
  const first = pendingVerifyFolders[0];
  pendingVerifyFolders = [];
  goToFolderVerifyQueue(first, /* fromQueue */ true);
}

// Called once the current folder's queue is confirmed empty (see the
// DOMContentLoaded check near the bottom of this script). Moves to the
// next folder in the original selection order, or shows a completion
// message if none are left.
function advanceVerifyFolderQueueIfEmpty(totalDocsInFolder) {
  if (totalDocsInFolder > 0) return;
  const raw = sessionStorage.getItem('dha_verify_folder_queue');
  if (!raw) return;
  let queue;
  try { queue = JSON.parse(raw); } catch (_) { queue = []; }
  // Drop the folder we're currently on (front of the queue).
  const currentIdx = queue.indexOf(currentFilterFolderName);
  if (currentIdx >= 0) queue.splice(currentIdx, 1);
  if (queue.length === 0) {
    sessionStorage.removeItem('dha_verify_folder_queue');
    if (currentFilterFolderName) {
      showToast(`"${currentFilterFolderName}" is done — no folders left in the queue.`, 'success');
    }
    return;
  }
  sessionStorage.setItem('dha_verify_folder_queue', JSON.stringify(queue));
  showToast(`"${currentFilterFolderName}" is done — moving to "${queue[0]}".`, 'success');
  goToFolderVerifyQueue(queue[0], true);
}

function goToFolderVerifyQueue(folderName, fromQueue = false) {
  const url = new URL(window.location.href);
  url.searchParams.set('folder_name', folderName);
  url.searchParams.delete('folder_id'); // folder_name takes precedence

  // This navigation is a full page reload, so JS state (and focus) resets
  // before we could set anything directly. Leave a flag that survives the
  // reload — checked in the DOMContentLoaded handler below — so that once
  // the folder's queue loads, focus lands on Browse (the last thing before
  // the metadata textboxes in tab order), meaning the very next Tab press
  // moves straight into the textboxes instead of back through the BRANCH
  // dropdown and Browse button again.
  sessionStorage.setItem('dha_focus_browse_after_folder_select', '1');

  // Remember whichever BRANCH (Transfer / Building Control / Land
  // Acquisition) was selected before browsing, so the reload below can
  // restore it instead of the page falling back to its default
  // Transfer-first tab-selection logic. Read directly from the dropdown
  // rather than relying on any tab CSS state, since that's the one source
  // of truth the user actually interacted with.
  const activeBranch = document.getElementById('vBranchSelect')?.value || '';
  if (activeBranch) {
    sessionStorage.setItem('dha_verify_active_branch', activeBranch);
  } else {
    sessionStorage.removeItem('dha_verify_active_branch');
  }

  window.location.href = url.toString();
}

function clearValidateQueue() {
  const queueList = document.getElementById('validateQueueList');
  if (!queueList) return;

  // Clear all queue items from list DOM
  queueList.innerHTML = `
    <div class="text-center text-secondary py-4" style="font-size:.82rem;">
      <i class="fas fa-trash-alt fa-2x mb-2 d-block text-muted opacity-50"></i>
      Document queue cleared.
    </div>`;

  // Update queue count display to 0
  const countEl = document.getElementById('queueCount');
  if (countEl) countEl.textContent = '0';

  // Reset selected document state
  vCurrentDocId  = null;
  vCurrentCanAct = false;

  // Reset preview panel
  document.getElementById('previewTitle').textContent = 'Document Preview';
  document.getElementById('currentDocBadge').textContent = 'None Selected';
  document.getElementById('docPreviewArea').innerHTML = `
    <div class="text-center text-secondary py-5">
      <i class="fas fa-file fa-4x mb-3 opacity-30"></i>
      <p>Select a document from the queue to preview</p>
    </div>`;

  const zoomOverlay = document.getElementById('zoomOverlay');
  if (zoomOverlay) zoomOverlay.style.display = 'none';

  // Reset metadata input fields
  ['vPH','vSEC','vPlot','vClass','vFileName','vPageNo','vPageCount','vDate'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  vCaptureOriginalMeta();

  // Disable the Validate action button (it stays visible above the queue)
  const validateBtn = document.getElementById('btnTriggerValidate');
  if (validateBtn) validateBtn.disabled = true;
}

function updateTabCounts() {
  const counts = { 'Transfer': 0, 'Building Control': 0, 'Land Acquisition': 0 };
  document.querySelectorAll('.queue-item').forEach(el => {
    const doc = JSON.parse(el.dataset.doc);
    const docCategory = getDocCategory(doc);

    if (el.dataset.status === 'pending') {
      counts[docCategory]++;
    }
  });

  const tabNames = { 'Transfer': 'tabTransfer', 'Building Control': 'tabBuilding', 'Land Acquisition': 'tabLand' };
  for (const cat in counts) {
    const btn = document.getElementById(tabNames[cat]);
    if (btn) {
      btn.textContent = `${cat} (${counts[cat]})`;
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  // Reconnect to whichever local folder this queue is filtered to (if it
  // was browsed via Browse earlier), so previews load live from disk
  // instead of the server. No-op if nothing was ever browsed locally.
  restoreVerifyFolderHandle();

  // Tab must never carry keyboard focus out of the Verify Workflow panel.
  // The old version listened for Tab directly on btnTriggerValidate — but
  // that button is DISABLED in the HTML until a document is both selected
  // and the current user is permitted to action it (see selectValidateDoc()),
  // which is the normal state on every fresh page load. A disabled button
  // can never receive focus, so the browser's native Tab handling silently
  // skipped straight over it and escaped the panel entirely instead of
  // being trapped. This version looks up whichever element is actually the
  // last focusable one in the panel RIGHT NOW (an enabled Validate button,
  // else the last visible/enabled metadata field before it — DATE for the
  // standard branches, SCAN NO for Land Acquisition) and traps Tab there
  // instead of on one hardcoded node. Also traps Shift+Tab off the top of
  // the panel (BRANCH) so focus can't leak backward into the page header.
  // (Pressing Enter still submits normally — see the reload check below for
  // the loop-back after that runs.)
  const VERIFY_TAB_ORDER = [
    'vBranchSelect', 'browseVerifyBtn', 'vSEC', 'vPlot',
    'vLandFileNo', 'vMouaza', 'vLandOwnerName', 'vKanal', 'vMarla',
    'vSquareFoot', 'vSaledeed', 'vLitigationNo', 'vScanNo',
    'vClass', 'vFileName', 'vPageNo', 'vPageCount', 'vDate',
    'btnTriggerValidate'
  ];
  function getLastVerifyWorkflowFocusable() {
    for (let i = VERIFY_TAB_ORDER.length - 1; i >= 0; i--) {
      const el = document.getElementById(VERIFY_TAB_ORDER[i]);
      if (el && !el.disabled && el.tabIndex !== -1 && el.offsetParent !== null) return el;
    }
    return document.getElementById('vBranchSelect');
  }
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Tab') return;
    if (!e.shiftKey) {
      if (document.activeElement === getLastVerifyWorkflowFocusable()) {
        e.preventDefault();
      }
    } else if (document.activeElement === document.getElementById('vBranchSelect')) {
      e.preventDefault();
    }
  });

  // If this page load is the reload right after clicking Validate, remember
  // to loop keyboard focus back to CLASS NAME once the branch/queue below
  // has actually been restored and applied — doing it here immediately
  // would be too early: setVTab() (further down) is what shows/hides the
  // right fields and loads the next document into them, so focusing before
  // that ran could land on a field that gets hidden or overwritten a
  // moment later. See the flag set in triggerApprove().
  const shouldRefocusAfterValidate = sessionStorage.getItem('dha_focus_top_after_validate') === '1';
  sessionStorage.removeItem('dha_focus_top_after_validate');

  // If this page load is the reload right after picking a folder via
  // Browse, focus Browse itself so the next Tab press moves straight into
  // the metadata textboxes — see the flag set in goToFolderVerifyQueue().
  let focusedBrowseAfterFolderSelect = false;
  if (sessionStorage.getItem('dha_focus_browse_after_folder_select') === '1') {
    sessionStorage.removeItem('dha_focus_browse_after_folder_select');
    const browseBtn = document.getElementById('browseVerifyBtn');
    if (browseBtn) { browseBtn.focus(); focusedBrowseAfterFolderSelect = true; }
  }

  const vDateInput = document.getElementById('vDate');
  if (vDateInput) attachVDateMask(vDateInput);

  // Watch the metadata fields for manual edits so we can flag the document
  // as changed (and therefore "incorrect") the moment the validator types
  // something different from what was loaded.
  VMETA_FIELDS.forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('input', vCheckForChanges);
      el.addEventListener('change', vCheckForChanges);
    }
  });

  // Full keyboard interaction for the CLASS NAME combo box (mirrors the
  // ArrowDown/ArrowUp/Enter/Home/End handling already used by
  // pages/rename.php's #inputClass so both pages behave the same way):
  //   Enter / Space  -> open the dropdown if it's closed
  //   ArrowDown      -> open if closed, otherwise move to the next option
  //   ArrowUp        -> move to the previous option (dropdown must be open)
  //   Home / End     -> jump to the first / last option (opens if closed)
  //   Enter          -> select the highlighted option (when the dropdown is open)
  //   Escape         -> if the dropdown is open, just close it — leave the
  //                     current value/selection untouched. If the dropdown is
  //                     already closed, fall back to the existing "snap
  //                     whatever's typed to the closest valid option" repair
  //                     behaviour, unchanged.
  // Space only opens the dropdown while the field is empty — class names can
  // themselves contain spaces (e.g. "BC_RemovalofUnauthorized Signage"), so
  // once the validator has started typing, Space must keep typing normally.
  const vClassInput = document.getElementById('vClass');
  const vClassDropdownEl = document.getElementById('vClassDropdown');
  if (vClassInput && vClassDropdownEl) {
    let vHighlightedIndex = -1;

    function getVisibleVClassItems() {
      return Array.from(vClassDropdownEl.querySelectorAll('.class-combo-item:not(.hidden):not(.tab-filtered-out)'));
    }

    function highlightVClassItem(items, index) {
      items.forEach(item => item.classList.remove('highlighted'));
      if (index >= 0 && index < items.length) {
        items[index].classList.add('highlighted');
        items[index].scrollIntoView({ block: 'nearest' });
      }
    }

    function openVClassDropdownForKeyboard() {
      if (!vClassDropdownEl.classList.contains('open')) {
        toggleVClassDropdown();
      }
    }

    vClassInput.addEventListener('keydown', function(e) {
      const wasOpen = vClassDropdownEl.classList.contains('open');

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!wasOpen) openVClassDropdownForKeyboard();
        // Re-read the list after opening — toggleVClassDropdown() un-hides
        // the options, so the "closed" list read before opening may be stale.
        const items = getVisibleVClassItems();
        if (items.length === 0) return;
        vHighlightedIndex = wasOpen ? (vHighlightedIndex + 1) % items.length : 0;
        highlightVClassItem(items, vHighlightedIndex);
        return;
      }

      const isOpen = wasOpen;
      const visibleItems = getVisibleVClassItems();

      if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isOpen) return;
        if (visibleItems.length === 0) return;
        vHighlightedIndex = (vHighlightedIndex - 1 + visibleItems.length) % visibleItems.length;
        highlightVClassItem(visibleItems, vHighlightedIndex);
      } else if (e.key === 'Home') {
        e.preventDefault();
        if (!isOpen) openVClassDropdownForKeyboard();
        const items = getVisibleVClassItems();
        if (items.length === 0) return;
        vHighlightedIndex = 0;
        highlightVClassItem(items, vHighlightedIndex);
      } else if (e.key === 'End') {
        e.preventDefault();
        if (!isOpen) openVClassDropdownForKeyboard();
        const items = getVisibleVClassItems();
        if (items.length === 0) return;
        vHighlightedIndex = items.length - 1;
        highlightVClassItem(items, vHighlightedIndex);
      } else if (e.key === ' ' || e.key === 'Spacebar') {
        if (!isOpen && this.value === '') {
          e.preventDefault();
          openVClassDropdownForKeyboard();
        }
        // else: let Space type normally (dropdown already open, or the
        // validator is mid-typing a class name that contains a space).
      } else if (e.key === 'Enter') {
        if (!isOpen) {
          e.preventDefault();
          openVClassDropdownForKeyboard();
          return;
        }
        if (vHighlightedIndex >= 0 && vHighlightedIndex < visibleItems.length) {
          e.preventDefault();
          const selectedText = visibleItems[vHighlightedIndex].textContent.trim();
          selectVClassOption(selectedText);
          vHighlightedIndex = -1;
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        if (isOpen) {
          // Just close — leave the current value exactly as it was.
          vClassDropdownEl.classList.remove('open');
          const icon = document.getElementById('vClassArrowIcon');
          if (icon) icon.style.transform = 'rotate(0deg)';
          vHighlightedIndex = -1;
          return;
        }

        // Dropdown already closed: preserve the existing "snap whatever's
        // typed to the closest valid option" repair behaviour.
        const typed = this.value.trim();
        if (!typed) { this.blur(); return; }

        const branchPrefix = { 'Transfer': 'TFR_', 'Building Control': 'BC_', 'Land Acquisition': 'ACQN_' };
        const activeBranch  = document.getElementById('vBranchSelect')?.value || '';
        const prefix        = branchPrefix[activeBranch];
        const candidates    = prefix ? ALLOWED_CLASSES.filter(c => c.startsWith(prefix)) : [];

        const match = findClosestClassName(typed, candidates);
        if (match) {
          this.value = match;
          vCheckForChanges();
        }
        this.blur();
      }
    });

    vClassInput.addEventListener('input', () => {
      vHighlightedIndex = -1;
      vClassDropdownEl.querySelectorAll('.class-combo-item').forEach(item => item.classList.remove('highlighted'));
    });
  }

  // Build the CLASS NAME dropdown's option list once, from the same
  // ALLOWED_CLASSES array rename.php uses — so it can never drift out of
  // sync with the Rename Workflow's dropdown.
  buildVClassDropdown();

  // Read ?tab= URL param passed from rename.php and activate the correct tab
  // NOTE: we use ?tab= (not ?branch=) to avoid conflicting with the city-branch SQL filter
  const urlTab = new URLSearchParams(window.location.search).get('tab');
  let initialTab = null;
  if (urlTab) {
    const tabMap = {
      'Transfer':         'Transfer',
      'Building_Control': 'Building Control',
      'Land_Acquisition': 'Land Acquisition',
    };
    initialTab = tabMap[urlTab] || 'Transfer';
  }

  // Calculate pending counts for all tabs (used for the tab count badges,
  // and for the notification below when restoring a branch after a folder
  // browse)
  updateTabCounts();

  const counts = { 'Transfer': 0, 'Building Control': 0, 'Land Acquisition': 0 };
  document.querySelectorAll('.queue-item').forEach(el => {
    if (el.dataset.status === 'pending') {
      const doc = JSON.parse(el.dataset.doc);
      counts[getDocCategory(doc)]++;
    }
  });

  // If this reload came from browsing a folder while Building Control or
  // Land Acquisition (or Transfer) was selected, restore that exact branch
  // instead of falling through to the Transfer-first auto-pick below. A
  // folder browse should never silently swap the branch out from under the
  // user — if the folder simply has no files for that branch, we notify
  // instead of switching.
  const restoredBranch = sessionStorage.getItem('dha_verify_active_branch');
  sessionStorage.removeItem('dha_verify_active_branch');

  if (restoredBranch && counts.hasOwnProperty(restoredBranch)) {
    initialTab = restoredBranch;
    if (counts[initialTab] === 0) {
      showToast(`No "${initialTab}" documents found in this folder.`, 'warning');
    }
  }

  // A plain, fresh visit to Validate (no ?tab= from Rename, no restored
  // branch from a folder browse) leaves BRANCH on its "None" placeholder —
  // mirroring Rename, which never auto-picks a branch on load either.
  // setVTab() is only called once a real branch is known, whether that's
  // from the two cases above or from the user picking one from the
  // dropdown themselves.

  // A user locked to one branch always uses that branch, overriding
  // anything above — the dropdown itself is already locked HTML-side to
  // this single value, but without this, setVTab() never actually ran on a
  // plain fresh visit (initialTab stayed null unless ?tab= or a restored
  // session branch happened to be set), so CLASS NAME filtering, the Land
  // Acquisition field toggle, and the document queue's category filter
  // never applied even though the dropdown displayed the right branch.
  <?php if (count($myAssignedBranches) === 1 && !$isAdmin): ?>
  initialTab = <?= json_encode(str_replace('_', ' ', $myAssignedBranches[0])) ?>;
  <?php endif; ?>

  if (initialTab) {
    setVTab(initialTab, true);
  }

  // Now that the branch (if any) is restored and its fields/queue are
  // actually in their final state, it's safe to refocus for the reload
  // that follows a Validate submit. Keeps the validator parked on
  // CLASS NAME for the next document instead of having to reselect BRANCH
  // and click back into the form after every single document.
  if (shouldRefocusAfterValidate) {
    vFocusFirstEditableField();
  } else if (!focusedBrowseAfterFolderSelect) {
    // A plain, fresh visit — nothing above claimed focus, so without this
    // the page loads with nothing focused at all and the very first Tab
    // press falls through to the top navbar/logo instead of landing inside
    // the Verify Workflow panel. Put keyboard focus on BRANCH, the panel's
    // own starting field (mirrors the same fallback on Rename).
    const branchSelect = document.getElementById('vBranchSelect');
    if (branchSelect) branchSelect.focus();
  }

  // If this folder was reached as part of a multi-folder queue and it has
  // nothing left to verify (across every branch), auto-advance to the next
  // one in the original selection order.
  const totalDocsInFolder = document.querySelectorAll('#validateQueueList .queue-item').length;
  advanceVerifyFolderQueueIfEmpty(totalDocsInFolder);
});
</script>

<?php require_once '../includes/footer.php'; ?>