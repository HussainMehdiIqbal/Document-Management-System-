<?php
// ============================================================
// pages/rename.php — Rename Document Module (dms_database)
// ============================================================
require_once '../includes/auth.php';
requireLogin();

$pageTitle = 'Rename Documents';

$message = '';
$msgType = 'success';
$userId  = getUserId();

// ── Rename KPI — target set by management: 1000 renames/day ────
// Formula (as instructed): (count * 100) / target
// Independent of Scan/Validate: was previously keyed on `scanned_by`, which
// meant this KPI actually measured "documents this user scanned that got
// renamed by anyone today" — so a user's Rename KPI moved whenever they (or
// anyone else) renamed something THEY had scanned, and renaming something
// someone else scanned didn't count for the renamer at all. It now counts
// only documents THIS user actually renamed (`renamed_by`), nothing else.
// Also scoped to the shared "Reset Dashboard" cutoff instead of a hardcoded
// CURDATE() so pressing Reset actually zeroes it.
$renameKpiTarget = 1000;
$renameKpiCount  = 0;
$renameKpiCutoff = getDashboardResetCutoff($conn, $userId);
$renameKpiStmt = $conn->prepare("SELECT COUNT(*) FROM documents WHERE renamed_by = ? AND renamed_filename IS NOT NULL AND renamed_at >= ?");
if ($renameKpiStmt) {
    $renameKpiStmt->bind_param("is", $userId, $renameKpiCutoff);
    $renameKpiStmt->execute();
    $renameKpiCount = (int)$renameKpiStmt->get_result()->fetch_row()[0];
    $renameKpiStmt->close();
}
$renameKpiPercent          = ($renameKpiCount * 100) / $renameKpiTarget;
$renameKpiPercentFormatted = number_format($renameKpiPercent, 1);
$renameKpiPercentWidth     = min(100, $renameKpiPercent);

// ── Handle Rename ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename') {
    $docId   = (int)($_POST['doc_id'] ?? 0);
    $newName = clean($_POST['new_name'] ?? '');
    $isAjax  = isset($_POST['is_ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    $allowedClasses = [
        // General / Land Acquisition
        'ACQN_Advertisement', 'ACQN_Affidavit', 'ACQN_ApplicationGen', 'ACQN_Challan', 'ACQN_CNIC',
        'ACQN_CourtOrder', 'ACQN_DuplicateCourtOreder', 'ACQN_FardeMalkiat', 'ACQN_FileCover', 'ACQN_IncomingMails',
        'ACQN_IndemnityBond', 'ACQN_IntimationLetter', 'ACQN_ION', 'ACQN_JamaBandi', 'ACQN_KhasraMaps',
        'ACQN_LandSaleForm', 'ACQN_MinuteSheet', 'ACQN_Misc', 'ACQN_Mutation', 'ACQN_NDC',
        'ACQN_NonEncumbranceCertificate', 'ACQN_OutgoingMails', 'ACQN_PatwariReport', 'ACQN_Photographs1xLO1xInvestor', 'ACQN_PossessionCertificate',
        'ACQN_PowerOfAttorney', 'ACQN_PropertyDistributionApplication', 'ACQN_ProvisionOfRecord', 'ACQN_SaleAgreementorDeed', 'ACQN_UndertakinginFavorofInvestor',
        // Building Control
        'BC_Advertisement','BC_Affidavit','BC_Agreement','BC_AllocationLetter','BC_AllotmentLetter',
        'BC_AlterationWithoutPermission','BC_AmalgamationofPlots','BC_Application',
        'BC_ApplicationForCompletionCertificate','BC_ApplicationForInstallationOfGenerator',
        'BC_ApplicationForPossesion','BC_ApplicationForRevisedDrawing','BC_ApplicationForSignBoard',
        'BC_ApplicationForSitePlan','BC_ApplicationForTheTransfer','BC_ApplicationForWaterConnection',
        'BC_ApplicationGen','BC_ApplicationOfLiNkATMBoard','BC_ApplicationOfRadioANTENA',
        'BC_ApprovalOfDrawing','BC_ApprovalOfHousePlan','BC_ApprovalOfProposedDrawing',
        'BC_ApprovalOfRevisedDrawing','BC_ArchitectCertificates','BC_ArchitectureEngeneeringForm',
        'BC_AuthorityLetter','BC_AutoWorkShopInCommercialArea','BC_Ballot','BC_BlankPage',
        'BC_BurialCertificate','BC_CancellationDeed','BC_CancellationofpowerofAttorney',
        'BC_CantonmentBoard','BC_Certificate','BC_Challan','BC_ChangeofAddress',
        'BC_ChangingOfMailAddress','BC_CheckSheet','BC_ClearenceCertificate','BC_ClearenceOfWaterBill',
        'BC_ClubMembership','BC_CNIC','BC_CommunicationOfErictionTower','BC_Complaint',
        'BC_ComplaintRegardingSewerageWaterStreming','BC_CompletionCertificate','BC_CompletionDrawing',
        'BC_Construction','BC_ConstructionOfBuilding','BC_ConstructionViolations',
        'BC_Constructionoffoundation','BC_ConstructionofLiftControl','BC_CourtCase','BC_CourtDecree',
        'BC_CuttingofTrees','BC_DamageCausedToTheCommercialPlaza','BC_DataCommuncationFacilities',
        'BC_DebrisClearance','BC_DelayInConstructionPeriod','BC_DemarcationForm','BC_DemarcationOfPlot',
        'BC_Demolishingbuildingorhouse','BC_DensionCopy','BC_DepositOfDrawing','BC_DevalopmentCharges',
        'BC_DisconnectionOfWaterSupply','BC_DPC','BC_DraftAgreement','BC_DrainageSystemInCommercialArea',
        'BC_Drawings','BC_DuesForPossession','BC_DuplicateCompletionCertificate','BC_DuplicateSitePlan',
        'BC_DuplicateSiteplan','BC_ElectricBill','BC_EstateAgentCard','BC_FileCover',
        'BC_FinalInspectionProforma','BC_FixingOfLogoOnBuilding','BC_Form','BC_FormA','BC_FormB',
        'BC_FrameAnalysisforCommercialBuilding','BC_GarbageDrumbs','BC_GasBill',
        'BC_GeneralCorrespondence','BC_GeneralPowerOfAttorney','BC_GeoTechnicalEngineeringLaboratory',
        'BC_HygienceAndCleanLiness','BC_ImportantInstructionForOwnerOfCommercialPlot',
        'BC_IncomingMails','BC_InformationRegardingTransaction','BC_InstallationOfElectricMotor',
        'BC_InstallationOfSignBoard','BC_InstallationOfWaterPump','BC_InstallationOfWaterTapOutsidePremises',
        'BC_InstallationofAntena','BC_InstallationofGenerator','BC_InstallationofSecurityCamera',
        'BC_InstallmentOFUndergroundElectrificationPtclWorkBill','BC_IntimationLetter','BC_ION',
        'BC_LeakageOfWaterSupply','BC_LegalizationOfBuilding','BC_LegalNotice','BC_Letter',
        'BC_LiftingOfSteelSteps','BC_MasterPlan','BC_MembershipCard','BC_MembershipForm',
        'BC_MinuteSheet','BC_Misc','BC_MiscCharges','BC_MiscellaneousComplains',
        'BC_MiscellaneousRefunds','BC_MiscellaneousRequests','BC_NDC','BC_NDCApplyReceipt',
        'BC_NOC','BC_NikahNama','BC_Non-Transferable','BC_NonPayment','BC_Notice',
        'BC_OpeningsSewargeConnection','BC_OpeningofResturant','BC_OutgoingMails','BC_OwnershipDeed',
        'BC_ParticularsPerformaOwners','BC_Passport','BC_PaymentofDues','BC_PermissionForDigging',
        'BC_PermissionForInstallationOfSkyBoardOnRoof','BC_PermissionForWaterPump',
        'BC_PermissionToUseThe RooftopOfCommercialBuilding','BC_PermissionforMaterial',
        'BC_PermissionforRenovation','BC_PermissionforUseofExcavator',
        'BC_PermissionforinstallationofWindowAC','BC_PermissiontoUsePlotasLoan',
        'BC_Permissiontomortgage','BC_PhysicalDemarcation','BC_Picture','BC_Plantation',
        'BC_PoliceReport','BC_PossesionOfPlot','BC_PossesionofPlot','BC_ProposedDrawing',
        'BC_ProposedPlan','BC_RefundOfExcavation','BC_RegistrationCertificate','BC_RegistrationForm',
        'BC_RegistrationOfLabour','BC_RegistrationofArchitects','BC_RelinquishmentOfAllotment',
        'BC_RemovalOfPartitionWall','BC_RemovalOfTheSkyBoard','BC_RemovalOfViolations',
        'BC_RemovalofElectricTransformer','BC_RemovalofUnauthorized Signage','BC_Removalofplantation',
        'BC_RenovationOfDetailOfBuilding','BC_RenovationOfHouse','BC_RenovationofBuilding',
        'BC_RenovationwithoutPermission','BC_ReplacementOfSeweragePipes','BC_ReqForConstruction',
        'BC_RequestForHandingOverOfDemarcationOfPlot','BC_RequestForNDC','BC_RequestForm',
        'BC_RentAgreement','BC_RevisedDrawings','BC_RoutineInspectionReport','BC_SaleDeed',
        'BC_SaleOfPlot','BC_SanctionofBuildingPlan','BC_SewerageOpening',
        'BC_ShiftingofTelephonePoleWire','BC_SiteCheckForm','BC_SitePlan','BC_SiteReportLetter',
        'BC_SpecialPowerOfAttorney','BC_StackingOfConstructionMaterial','BC_StabilityCertificate',
        'BC_StatementofAccount','BC_StructureDetailOfPlaza','BC_SubDivisionofPlots',
        'BC_SubmissionOfDrawing','BC_SuiGasConnection','BC_SurchargesOfPlot','BC_SurveyorReport',
        'BC_TIPTax','BC_TelephoneBill','BC_TemporarySewerageConnection','BC_ToWhomItMayConcern',
        'BC_TowerSpecification','BC_TransferDeed','BC_TransferLetter','BC_TransferOfPlot',
        'BC_Transferable','BC_UnauthorizedWaterConnection','BC_Undertakings',
        'BC_UsingOpenplotforMaterialHut','BC_UtilityBill','BC_Violation','BC_ViolationOfDHAByelaws',
        'BC_ViolationofAgreement','BC_ViolationofConstructionByLaws','BC_WallChalkingOnCommercialBuilding',
        'BC_WaterConnection','BC_WaterSewerageBill','BC_WierlessLocalLoopLicense',
        // Transfer Branch
        'TFR_Acceptance', 'TFR_Advertisement', 'TFR_Affidavit', 'TFR_AgreementToSellaPlot', 'TFR_AllocationLetter',
        'TFR_AllotmentLetter', 'TFR_AllotmentOfAccessArea', 'TFR_AmalgamationofPlots', 'TFR_Application',
        'TFR_Application(LeackageOfConfidentialFile)', 'TFR_ApplicationForAllocationLetter', 'TFR_ApplicationForAllotmentLetter',
        'TFR_ApplicationForAssociateMemberShip', 'TFR_ApplicationforAuthorityLetter', 'TFR_ApplicationForChangeOfAddress',
        'TFR_ApplicationForChangeOfName', 'TFR_ApplicationForCuttingOfTree', 'TFR_ApplicationForDuplicateAllotmentLetter',
        'TFR_ApplicationForIntimation', 'TFR_ApplicationForm', 'TFR_ApplicationForMembershipCard', 'TFR_ApplicationForNDC',
        'TFR_ApplicationForNOC', 'TFR_ApplicationForPaymentOfDues', 'TFR_ApplicationForRegistration',
        'TFR_ApplicationForRegularMemebership', 'TFR_ApplicationForSitePlan', 'TFR_ApplicationforTransfer',
        'TFR_ApplicationForVerification', 'TFR_ApplicationStayOrderRemoval', 'TFR_ApprovalOfDrawing',
        'TFR_ApprovalofRevised Drawing', 'TFR_Attestment', 'TFR_AuthorityLetter', 'TFR_AuthorityLetterPaymentReceiving',
        'TFR_Ballot', 'TFR_BankLetter', 'TFR_B-FormforMinors', 'TFR_BianaPapers', 'TFR_CancellationDeed',
        'TFR_CancellationOfPowerOfAttorney', 'TFR_CancellationOfSpecialAttorney', 'TFR_CanttBoardTransferTax',
        'TFR_CapitalValueTax', 'TFR_CautionDocs', 'TFR_CautionOnAllotmentOfPlot', 'TFR_Certificate', 'TFR_Challan',
        'TFR_ChangeOfAddress', 'TFR_ChangeOfName', 'TFR_ChangeOfNameConfirmation', 'TFR_ChangeOfOwnership',
        'TFR_Changeofplot', 'TFR_CheckList', 'TFR_CheckListForDeathCertificate', 'TFR_CheckListForNDCIssue',
        'TFR_CheckSheet', 'TFR_CheckSheetForChangeOfName', 'TFR_ClearenceLetter', 'TFR_ClearenceOfDues',
        'TFR_ClientLedger', 'TFR_CNIC', 'TFR_Complain', 'TFR_CompletionCertificate', 'TFR_ConfirmationLetterforForeignCase',
        'TFR_ConfirmationOfBooking', 'TFR_Construction', 'TFR_ConstructionBoundaryWall', 'TFR_ConstructionOfShop',
        'TFR_ConstructionVoilation', 'TFR_CornerPlotCharges', 'TFR_CourtCase', 'TFR_CourtDecree', 'TFR_CourtNotice',
        'TFR_CoveringLetter', 'TFR_DeathCertificate', 'TFR_DeclarationOfOralGift', 'TFR_DemandDraft', 'TFR_Demarcation',
        'TFR_DemorcationOfPlot', 'TFR_DevelopmentCharges', 'TFR_DhaCityProject', 'TFR_Drawing', 'TFR_DuplicateAllotmentLetter',
        'TFR_DuplicateTransferLetter', 'TFR_EstateAgentCard', 'TFR_FileCover', 'TFR_FileMovementRecord', 'TFR_Form',
        'TFR_FormA', 'TFR_FormB', 'TFR_ForwardingLetter', 'TFR_GasConnection', 'TFR_GeneralCorrespondence',
        'TFR_GeneralPowerOfAttorney', 'TFR_GeninenessCertificate', 'TFR_IncomingMails', 'TFR_IndividualAttestationConfirmation',
        'TFR_Installations', 'TFR_IntimationLetter', 'TFR_ION', 'TFR_IssuanceOfAllocationLetterAgainstAffidavit',
        'TFR_LegalHiersLetterandNOKs', 'TFR_Letter', 'TFR_LienAgainstPlot', 'TFR_LienMarking', 'TFR_LienRemoval',
        'TFR_LitigationOfPlot', 'TFR_MemberhipOfTheSociety', 'TFR_Membershipcard', 'TFR_MembershipForm', 'TFR_MinuteSheet',
        'TFR_Misc', 'TFR_MiscellaniousCharges', 'TFR_MiscellaniousRefunds', 'TFR_MortgageDeed', 'TFR_MortgageOf Plot',
        'TFR_MutationOfProperty', 'TFR_NDC', 'TFR_NDCApplyReciept', 'TFR_NikahNama', 'TFR_NOC', 'TFR_NOCforARMYOfficers',
        'TFR_NonEncumbrnceCertificate', 'TFR_NonTransferable', 'TFR_Notice', 'TFR_OpeningOfSewerage', 'TFR_OralGiftDeed',
        'TFR_OutgoingMails', 'TFR_OwnershipCertificate', 'TFR_Passport', 'TFR_PaymentOfConstructionPenality',
        'TFR_PaymentOfInstallments', 'TFR_PaymentofOutStandingDues', 'TFR_PaymentOfPension', 'TFR_PaymentorDevelopmentCharges',
        'TFR_PaymentPlan', 'TFR_PaymentReceipt', 'TFR_PermissionToMortgage', 'TFR_PermissionTosaleofplot',
        'TFR_PlotVerification', 'TFR_PoliceReport', 'TFR_PossesionOfplot', 'TFR_ProvisionOfInformation',
        'TFR_ProvisionOfPropertyRecord', 'TFR_PublicNotice', 'TFR_Receipt', 'TFR_ReceiptOfDevelopmentCharges',
        'TFR_RecieptAndAcknowledgement', 'TFR_RedemptionDeed', 'TFR_RedressalOfgrieveness', 'TFR_RegistrationForm',
        'TFR_RelinquishmentOfAllotment', 'TFR_RemovalOfCaution', 'TFR_RemovalofElectricpool', 'TFR_RequestforNDC',
        'TFR_RequestForOutstandingDues', 'TFR_RevisedPaymentShedule', 'TFR_SaleDeed', 'TFR_Saleofplot', 'TFR_ScrutinyReport',
        'TFR_SitePlan', 'TFR_SpecialPowerofAttorney', 'TFR_StampDutyTax', 'TFR_StandardPlot', 'TFR_StatementofAccount',
        'TFR_StatementOfNdcDeatails', 'TFR_StatementOfOutstandingDues', 'TFR_SubDivisionofPlots', 'TFR_SubmissionOfCertificate',
        'TFR_SubmissionReceipt', 'TFR_SurchargesOfPlot', 'TFR_SurveyReport', 'TFR_TCSPaper', 'TFR_Terms&Conditions',
        'TFR_TermsAndConditionsOfBooking', 'TFR_ToWhomItmayConcern', 'TFR_TransferAndMutation', 'TFR_TransferDeed',
        'TFR_TransferLetter', 'TFR_TransferOfPlaza', 'TFR_TransferOfPlot', 'TFR_TransferPhotographs', 'TFR_Undertaking',
        'TFR_UndertakingByTheDonee', 'TFR_UndertakingByThePurchaser', 'TFR_VariationCertificate', 'TFR_Verification',
        'TFR_VerificationForm'
    ];
    $classNameInput = clean($_POST['class_name'] ?? '');
    $phaseInput     = clean($_POST['phase']      ?? '');
    $branchInput    = clean($_POST['branch']     ?? '');
    $plotInput      = clean($_POST['plot']       ?? '');
    $renameMeta     = isset($_POST['rename_meta']) ? trim($_POST['rename_meta']) : '';

    if (empty($newName)) {
        $message = 'New name cannot be empty.'; $msgType = 'danger';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $message]); exit(); }
    } elseif (!empty($classNameInput) && !in_array($classNameInput, $allowedClasses)) {
        $message = 'Invalid Class Name. Please select a valid Class Name from the list.'; $msgType = 'danger';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $message]); exit(); }
    } else {
        $stmt = $conn->prepare("SELECT raw_filename, storage_path FROM documents WHERE document_id=? LIMIT 1");
        $stmt->bind_param("i", $docId);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($doc) {
            $ext     = pathinfo($doc['raw_filename'], PATHINFO_EXTENSION);
            $newName = pathinfo($newName, PATHINFO_EXTENSION) ? $newName : "$newName.$ext";

            // Extract the directory part to keep the renamed file in the same physical folder
            $dirPart = dirname($doc['storage_path']); // e.g. "uploads/DHA Lahore — Phase 1 Records" or "uploads"
            $newPath = $dirPart . '/' . $newName;
            $renamedAt = date('Y-m-d H:i:s');

            // Rename physical file — safeRenameFile() (includes/config.php)
            // also makes sure the target directory exists, and handles the
            // Windows-specific cases where the destination name already
            // exists or differs from the source only by letter case (plain
            // rename() silently fails in both of those on Windows).
            $oldFull = __DIR__ . '/../' . $doc['storage_path'];
            $newFull = __DIR__ . '/../' . $newPath;

            safeRenameFile($oldFull, $newFull);

            $stmt2 = documentsHasRenameMetaColumn($conn)
                ? $conn->prepare("UPDATE documents SET renamed_filename=?, storage_path=?, renamed_by=?, renamed_at=?, phase=?, branch=?, plot=?, status='pending', rename_meta=? WHERE document_id=?")
                : $conn->prepare("UPDATE documents SET renamed_filename=?, storage_path=?, renamed_by=?, renamed_at=?, phase=?, branch=?, plot=?, status='pending' WHERE document_id=?");

            if (!$stmt2) {
                $execRes = false;
            } else {
                if (documentsHasRenameMetaColumn($conn)) {
                    $stmt2->bind_param("ssisssssi", $newName, $newPath, $userId, $renamedAt, $phaseInput, $branchInput, $plotInput, $renameMeta, $docId);
                } else {
                    $stmt2->bind_param("ssisssi", $newName, $newPath, $userId, $renamedAt, $phaseInput, $branchInput, $plotInput, $docId);
                }
                $execRes = $stmt2->execute();
                if ($execRes) {
                    logActivity($conn, $userId, 'update', "Renamed document #$docId to '$newName'", $docId);
                }
                $stmt2->close();
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                if ($execRes) {
                    echo json_encode([
                        'success' => true,
                        'doc_id'  => $docId,
                        'new_name'=> $newName,
                        'message' => "Renamed document #$docId to '$newName'"
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Rename failed: ' . $conn->error]);
                }
                exit();
            }

            if ($execRes) {
                header('Location: rename.php?renamed=1');
                exit();
            } else {
                $message = 'Rename failed: ' . $conn->error; $msgType = 'danger';
            }
        } else {
            $message = 'Document not found.'; $msgType = 'danger';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $message]); exit(); }
        }
    }
}

// ── Search docs ───────────────────────────────────────────────
// Independent-module rule: rename must never auto-fill with every file
// scan.php just saved — renamers work by physically browsing to a folder
// on disk (see the "Browse" flow below) or, if they know what they're
// looking for, by explicitly searching for it. Nothing loads here on its
// own just because it was recently scanned.
$isAdmin = ((int)getUserRoleId() === 1);
if (!in_array((int)getUserRoleId(), [1, 4])) {
    header('Location: ../user_dashboard.php');
    exit();
}
$myAssignedBranches = getUserAssignedBranches();
$search = clean($_GET['search'] ?? '');

// ── "Files to Rename" folder deep-link ──────────────────────────
// Lets pages/rename_folders.php link straight into a specific folder's
// still-unrenamed documents, the same way validate.php already supports
// ?folder_name= for its own queue. Matched by name (not a single resolved
// folder_id) for the same reason documented in validate.php: folder_name
// has no unique constraint, so two folders can share a name.
$filterFolderName = clean($_GET['folder_name'] ?? '');

$sql = "SELECT d.*, f.folder_name, u.full_name AS renamed_by_name
        FROM documents d
        LEFT JOIN folders f ON d.folder_id = f.folder_id
        LEFT JOIN users   u ON d.renamed_by = u.user_id";
$whereParts = [];
if ($filterFolderName !== '') {
    $whereParts[] = "(d.renamed_filename IS NULL OR d.renamed_filename = '')";
    if (!$isAdmin) {
        $whereParts[] = "d.scanned_by = $userId";
    }
    $fn = $conn->real_escape_string($filterFolderName);
    $whereParts[] = "f.folder_name = '$fn'";
} elseif ($search) {
    $whereParts[] = "(d.renamed_filename IS NULL OR d.renamed_filename = '')";
    if (!$isAdmin) {
        $whereParts[] = "d.scanned_by = $userId";
    }
    $s = $conn->real_escape_string($search);
    $whereParts[] = "(d.raw_filename LIKE '%$s%' OR d.renamed_filename LIKE '%$s%'
              OR d.doc_type LIKE '%$s%' OR d.file_no LIKE '%$s%'
              OR d.branch LIKE '%$s%')";
} else {
    // No explicit search or folder filter — never auto-populate the queue from the DB.
    $whereParts[] = "1=0";
}
if (!empty($whereParts)) {
    $sql .= " WHERE " . implode(" AND ", $whereParts);
}
$sql .= " ORDER BY d.document_id DESC";
$docs = $conn->query($sql);

if (isset($_GET['get_db_queue'])) {
    header('Content-Type: text/html');
    if ($docs && $docs->num_rows > 0) {
        $docs->data_seek(0);
        while ($doc = $docs->fetch_assoc()) {
            $isPdf = ($doc['file_type'] === 'pdf');
            $icon = $isPdf ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
            $isRenamed = !empty($doc['renamed_filename']);
            ?>
            <div class="queue-item"
                 data-id="<?= $doc['document_id'] ?>"
                 data-raw="<?= esc($doc['raw_filename']) ?>"
                 data-renamed="<?= esc($doc['renamed_filename'] ?? '') ?>"
                 data-db-folder="<?= esc($doc['folder_name'] ?? '') ?>"
                 data-branch="<?= esc($doc['branch'] ?? '') ?>"
                 data-doctype="<?= esc($doc['doc_type'] ?? '') ?>"
                 data-fileno="<?= esc($doc['file_no'] ?? '') ?>"
                 data-phase="<?= esc($doc['phase'] ?? '') ?>"
                 data-plot="<?= esc($doc['plot'] ?? '') ?>"
                 data-year="<?= esc($doc['doc_year'] ?? '') ?>"
                 data-path="<?= esc($doc['storage_path']) ?>"
                 data-ext="<?= esc($doc['file_type']) ?>"
                 onclick="selectDocument(this)">
              <i class="fas <?= $icon ?>"></i>
              <span class="text-truncate" style="max-width: 250px;"><?= esc($doc['renamed_filename'] ?: $doc['raw_filename']) ?></span>
              <?php if ($isRenamed): ?>
                <i class="fas fa-check-circle text-success ms-auto" style="font-size: 0.8rem;"></i>
              <?php endif; ?>
            </div>
            <?php
        }
    } else {
        echo '<div class="p-3 text-center text-muted" style="font-size: 0.8rem;">No documents found.</div>';
    }
    exit();
}

if ($isAdmin) {
    require_once '../includes/header.php';
} else {
    require_once '../includes/user_header.php';
}
?>




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
  <strong>Filtered to folder:</strong> "<?= esc($filterFolderName) ?>" — showing only its documents still awaiting rename.
  <a href="rename.php" class="alert-link ms-2">View all documents</a>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="rename-container">
  <!-- Drag & Drop Overlay -->
  <div id="dragDropOverlay" class="drag-drop-overlay" style="display: none;">
    <div class="drag-drop-content">
      <i class="fas fa-folder-open fa-4x mb-3 text-info"></i>
      <h4>Drop Folders Here</h4>
      <p class="text-muted">Drop multiple folders at once to load them into the Document Queue.</p>
    </div>
  </div>

  <!-- Left Side: Document Preview -->
  <div class="preview-column">
    <div class="dha-card h-100 d-flex flex-column">
      <div class="dha-card-header py-3">
        <h5 id="previewTitle" class="mb-0 fw-600 text-truncate">Document Preview</h5>
      </div>
      <div class="dha-card-body flex-grow-1 p-0 position-relative" style="background:var(--surface-2); overflow:hidden; min-height: 400px;">
        <div id="docPreviewArea" class="w-100 h-100 d-flex align-items-center justify-content-center">
          <div class="text-center text-secondary py-5">
            <i class="fas fa-file fa-4x mb-3 opacity-30"></i>
            <p>Select a document from the queue to start</p>
          </div>
        </div>
        <!-- Zoom Controls Overlay -->
        <div class="zoom-controls-overlay" style="display: none;">
          <button type="button" class="zoom-btn" onclick="zoom('out')" title="Zoom Out"><i class="fas fa-minus"></i></button>
          <span id="zoomPercent" class="zoom-text">100%</span>
          <button type="button" class="zoom-btn" onclick="zoom('in')" title="Zoom In"><i class="fas fa-plus"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Right Side: Rename Workflow -->
  <div class="workflow-column">
    <div class="dha-card h-100 d-flex flex-column">
      <div class="dha-card-header py-3">
        <h5 class="mb-0 fw-700 text-truncate d-flex align-items-center gap-2">
          Rename Workflow
          <span id="currentNameBadge" class="badge bg-primary text-white" style="font-size:0.75rem; font-weight:normal; max-width: 150px; overflow:hidden; text-overflow:ellipsis;">None Selected</span>
          <span class="badge bg-warning text-dark" style="font-size:0.75rem; font-weight:700;">Token: <span id="inputDocNo">—</span></span>
        </h5>
      </div>
      
      <div class="dha-card-body flex-grow-1 d-flex flex-column gap-3 py-3 px-3 overflow-y-auto">
        <!-- Tabs (Hidden, but kept in DOM to prevent breaking JS references) -->
        <div class="workflow-tabs d-none" style="display: none !important;">
          <button type="button" class="workflow-tab" id="tabTransfer" onclick="setTab('Transfer')">Transfer</button>
          <button type="button" class="workflow-tab" id="tabBuilding" onclick="setTab('Building_Control')">Building Control</button>
          <button type="button" class="workflow-tab" id="tabLand" onclick="setTab('Land_Acquisition')">Land Acquisition</button>
        </div>

        <form method="POST" id="renameForm" class="d-flex flex-column gap-3">
          <input type="hidden" name="action" value="rename">
          <input type="hidden" name="doc_id" id="renameDocId">
          <!-- Tracks which workflow tab (Transfer / Building_Control / Land_Acquisition)
               was active when this document was renamed. Kept separate from CLASS NAME
               because Land Acquisition hides that field, so Class Name can no longer be
               relied on to identify the branch on the Verify page. -->
          <input type="hidden" id="inputWorkflowCategory" value="Transfer">

          <!-- BRANCH -->
          <!-- Selecting a BRANCH used to also call focusFirstEditableField(),
               which jumped keyboard focus straight to CLASS NAME — skipping
               over the Browse & Add Folders button entirely. That call was
               removed so Tab now walks the natural order a keyboard user
               expects: BRANCH -> Browse & Add Folders -> CLASS NAME -> ... -->
          <div>
            <label class="workflow-label">BRANCH</label>
            <?php $branchLabels = ['Transfer' => 'Transfer', 'Building_Control' => 'Building Control', 'Land_Acquisition' => 'Land Acquisition']; ?>
            <?php if (count($myAssignedBranches) === 1 && !$isAdmin): ?>
              <!-- Exactly one assigned branch — fully locked, nothing to choose. -->
              <select class="workflow-input" id="branchSelect" disabled tabindex="-1">
                <option value="<?= esc($myAssignedBranches[0]) ?>" selected><?= esc($branchLabels[$myAssignedBranches[0]] ?? $myAssignedBranches[0]) ?></option>
              </select>
            <?php elseif (count($myAssignedBranches) > 1 && !$isAdmin): ?>
              <!-- Multiple assigned branches — still a real choice, but the
                   options are restricted to only the ones this user was
                   actually assigned. -->
              <select class="workflow-input" id="branchSelect" onchange="setTab(this.value); updateRenameBrowseBtnState();">
                <option value="" selected disabled hidden>None</option>
                <?php foreach ($myAssignedBranches as $mb): ?>
                  <option value="<?= esc($mb) ?>"><?= esc($branchLabels[$mb] ?? $mb) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <!-- No assignment (or Admin) — unrestricted, same as before. -->
              <select class="workflow-input" id="branchSelect" onchange="setTab(this.value); updateRenameBrowseBtnState();">
                <option value="" selected disabled hidden>None</option>
                <option value="Transfer">Transfer</option>
                <option value="Building_Control">Building Control</option>
                <option value="Land_Acquisition">Land Acquisition</option>
              </select>
            <?php endif; ?>
          </div>

          <!-- Browse Button — click multiple times to accumulate more folders -->
          <div class="browse-btn-wrap">
            <button type="button" class="workflow-btn workflow-btn-browse w-100" id="browseRenameBtn" onclick="openBrowseDialog()" disabled title="Select a BRANCH first">
              <i class="fas fa-folder-open me-2"></i>Browse &amp; Add Folders
              <span class="browse-badge ms-2">MULTI-FOLDER</span>
            </button>
            <input type="file" id="browseFileInput"
                   webkitdirectory directory multiple
                   style="display:none;"
                   onchange="handleBrowsedFiles(this)">
          </div>

          <!-- NEW FILENAME (Display Only, Non-editable) -->
          <div>
            <label class="workflow-label text-primary fw-700">NEW FILENAME</label>
            <input type="text" class="workflow-input fw-600 border-primary new-filename-readonly" name="new_name" id="newNameInput" readonly tabindex="-1" placeholder="Auto-Generated Filename">
          </div>

          <!-- Row 1: PH, SEC, PLOT NO -->
          <div class="row g-2">
            <div class="col-4">
              <label class="workflow-label">PH</label>
              <input type="text" class="workflow-input text-center" id="inputPH" name="phase" readonly tabindex="-1" placeholder="PH">
            </div>
            <div class="col-4" id="secField">
              <label class="workflow-label">SEC</label>
              <input type="text" class="workflow-input text-center" id="inputSEC" name="branch" readonly tabindex="-1" placeholder="SEC">
            </div>
            <div class="col-4" id="plotField">
              <label class="workflow-label">PLOT NO</label>
              <input type="text" class="workflow-input text-center" id="inputPlot" name="plot" readonly tabindex="-1" placeholder="PLOT NO">
            </div>
          </div>

          <!-- Row 1B: LAND ACQUISITION ONLY FIELDS -->
          <!-- Token order: FileNo_Mouaza_OwnerName_Phase_Kanal_Marla_SquareFoot_SaleDeedNo_LiticationNo_ScanNo_ -->
          <div class="row g-2" id="landExtraFields" style="display:none;">
            <div class="col-6">
              <label class="workflow-label">FILE NO</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input" id="inputLandFileNo" oninput="this.value = this.value.replace(/[^0-9]/g, ''); autoGenerateName();" placeholder="File No">
            </div>
            <div class="col-6">
              <label class="workflow-label">MUZA</label>
              <input type="text" class="workflow-input" id="inputMouaza" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, ''); autoGenerateName();" placeholder="Muza">
            </div>
            <div class="col-12">
              <label class="workflow-label">LAND OWNER NAME</label>
              <input type="text" class="workflow-input" id="inputLandOwnerName" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, ''); autoGenerateName();" placeholder="Land Owner Name">
            </div>
            <div class="col-3">
              <label class="workflow-label">KANAL</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="inputKanal" oninput="this.value = this.value.replace(/[^0-9]/g, ''); autoGenerateName();" placeholder="Kanal">
            </div>
            <div class="col-3">
              <label class="workflow-label">MARLA</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="inputMarla" oninput="this.value = this.value.replace(/[^0-9]/g, ''); autoGenerateName();" placeholder="Marla">
            </div>
            <div class="col-3">
              <label class="workflow-label">SQ. FT</label>
              <input type="text" inputmode="decimal" class="workflow-input text-center" id="inputSquareFoot" oninput="this.value = this.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1'); autoGenerateName();" placeholder="Sq. Ft">
            </div>
            <div class="col-3">
              <label class="workflow-label">SALE DEED</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="inputSaledeed" oninput="this.value = this.value.replace(/[^0-9]/g, ''); autoGenerateName();" placeholder="Sale Deed">
            </div>
            <div class="col-3">
              <label class="workflow-label">LITIGATION NO</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="inputLitigationNo" oninput="this.value = this.value.replace(/[^0-9]/g, ''); autoGenerateName();" placeholder="Litigation No">
            </div>
            <div class="col-3">
              <label class="workflow-label">SCAN NO</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="inputScanNo" oninput="this.value = this.value.replace(/[^0-9]/g, ''); autoGenerateName();" placeholder="Scan No">
            </div>
          </div>

          <!-- Row 2: CLASS NAME (Custom Combo Box: type + dropdown) -->
          <div style="position:relative;" id="classNameField">
            <label class="workflow-label">CLASS NAME</label>
            <div class="class-combo-wrap">
              <input type="text" class="workflow-input class-combo-input" id="inputClass" name="class_name"
                     oninput="onClassChange(); filterClassOptions()"
                     onchange="onClassChange()"
                     autocomplete="off"
                     placeholder="Select Class Name">
              <button type="button" class="class-combo-arrow" onclick="toggleClassDropdown()" tabindex="-1" title="Show options">
                <i class="fas fa-chevron-down" id="classArrowIcon"></i>
              </button>
            </div>
            <div class="class-combo-dropdown" id="classDropdown">
              <!-- Building Control Classes -->
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Advertisement')">BC_Advertisement</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Affidavit')">BC_Affidavit</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Agreement')">BC_Agreement</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_AllocationLetter')">BC_AllocationLetter</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_AllotmentLetter')">BC_AllotmentLetter</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_AlterationWithoutPermission')">BC_AlterationWithoutPermission</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_AmalgamationofPlots')">BC_AmalgamationofPlots</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Application')">BC_Application</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationForCompletionCertificate')">BC_ApplicationForCompletionCertificate</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationForInstallationOfGenerator')">BC_ApplicationForInstallationOfGenerator</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationForPossesion')">BC_ApplicationForPossesion</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationForRevisedDrawing')">BC_ApplicationForRevisedDrawing</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationForSignBoard')">BC_ApplicationForSignBoard</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationForSitePlan')">BC_ApplicationForSitePlan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationForTheTransfer')">BC_ApplicationForTheTransfer</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationForWaterConnection')">BC_ApplicationForWaterConnection</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationGen')">BC_ApplicationGen</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationOfLiNkATMBoard')">BC_ApplicationOfLiNkATMBoard</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApplicationOfRadioANTENA')">BC_ApplicationOfRadioANTENA</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApprovalOfDrawing')">BC_ApprovalOfDrawing</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApprovalOfHousePlan')">BC_ApprovalOfHousePlan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApprovalOfProposedDrawing')">BC_ApprovalOfProposedDrawing</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ApprovalOfRevisedDrawing')">BC_ApprovalOfRevisedDrawing</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ArchitectCertificates')">BC_ArchitectCertificates</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ArchitectureEngeneeringForm')">BC_ArchitectureEngeneeringForm</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_AuthorityLetter')">BC_AuthorityLetter</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_AutoWorkShopInCommercialArea')">BC_AutoWorkShopInCommercialArea</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Ballot')">BC_Ballot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_BlankPage')">BC_BlankPage</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_BurialCertificate')">BC_BurialCertificate</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CancellationDeed')">BC_CancellationDeed</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CancellationofpowerofAttorney')">BC_CancellationofpowerofAttorney</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CantonmentBoard')">BC_CantonmentBoard</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Certificate')">BC_Certificate</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Challan')">BC_Challan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ChangeofAddress')">BC_ChangeofAddress</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ChangingOfMailAddress')">BC_ChangingOfMailAddress</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CheckSheet')">BC_CheckSheet</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ClearenceCertificate')">BC_ClearenceCertificate</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ClearenceOfWaterBill')">BC_ClearenceOfWaterBill</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ClubMembership')">BC_ClubMembership</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CNIC')">BC_CNIC</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CommunicationOfErictionTower')">BC_CommunicationOfErictionTower</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Complaint')">BC_Complaint</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ComplaintRegardingSewerageWaterStreming')">BC_ComplaintRegardingSewerageWaterStreming</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CompletionCertificate')">BC_CompletionCertificate</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CompletionDrawing')">BC_CompletionDrawing</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Construction')">BC_Construction</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ConstructionOfBuilding')">BC_ConstructionOfBuilding</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ConstructionViolations')">BC_ConstructionViolations</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Constructionoffoundation')">BC_Constructionoffoundation</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ConstructionofLiftControl')">BC_ConstructionofLiftControl</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CourtCase')">BC_CourtCase</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CourtDecree')">BC_CourtDecree</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_CuttingofTrees')">BC_CuttingofTrees</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DamageCausedToTheCommercialPlaza')">BC_DamageCausedToTheCommercialPlaza</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DataCommuncationFacilities')">BC_DataCommuncationFacilities</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DebrisClearance')">BC_DebrisClearance</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DelayInConstructionPeriod')">BC_DelayInConstructionPeriod</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DemarcationForm')">BC_DemarcationForm</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DemarcationOfPlot')">BC_DemarcationOfPlot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Demolishingbuildingorhouse')">BC_Demolishingbuildingorhouse</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DensionCopy')">BC_DensionCopy</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DepositOfDrawing')">BC_DepositOfDrawing</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DevalopmentCharges')">BC_DevalopmentCharges</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DisconnectionOfWaterSupply')">BC_DisconnectionOfWaterSupply</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DPC')">BC_DPC</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DraftAgreement')">BC_DraftAgreement</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DrainageSystemInCommercialArea')">BC_DrainageSystemInCommercialArea</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Drawings')">BC_Drawings</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DuesForPossession')">BC_DuesForPossession</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DuplicateCompletionCertificate')">BC_DuplicateCompletionCertificate</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DuplicateSitePlan')">BC_DuplicateSitePlan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_DuplicateSiteplan')">BC_DuplicateSiteplan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ElectricBill')">BC_ElectricBill</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_EstateAgentCard')">BC_EstateAgentCard</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_FileCover')">BC_FileCover</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_FinalInspectionProforma')">BC_FinalInspectionProforma</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_FixingOfLogoOnBuilding')">BC_FixingOfLogoOnBuilding</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Form')">BC_Form</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_FormA')">BC_FormA</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_FormB')">BC_FormB</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_FrameAnalysisforCommercialBuilding')">BC_FrameAnalysisforCommercialBuilding</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_GarbageDrumbs')">BC_GarbageDrumbs</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_GasBill')">BC_GasBill</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_GeneralCorrespondence')">BC_GeneralCorrespondence</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_GeneralPowerOfAttorney')">BC_GeneralPowerOfAttorney</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_GeoTechnicalEngineeringLaboratory')">BC_GeoTechnicalEngineeringLaboratory</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_HygienceAndCleanLiness')">BC_HygienceAndCleanLiness</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ImportantInstructionForOwnerOfCommercialPlot')">BC_ImportantInstructionForOwnerOfCommercialPlot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_IncomingMails')">BC_IncomingMails</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InformationRegardingTransaction')">BC_InformationRegardingTransaction</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InstallationOfElectricMotor')">BC_InstallationOfElectricMotor</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InstallationOfSignBoard')">BC_InstallationOfSignBoard</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InstallationOfWaterPump')">BC_InstallationOfWaterPump</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InstallationOfWaterTapOutsidePremises')">BC_InstallationOfWaterTapOutsidePremises</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InstallationofAntena')">BC_InstallationofAntena</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InstallationofGenerator')">BC_InstallationofGenerator</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InstallationofSecurityCamera')">BC_InstallationofSecurityCamera</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_InstallmentOFUndergroundElectrificationPtclWorkBill')">BC_InstallmentOFUndergroundElectrificationPtclWorkBill</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_IntimationLetter')">BC_IntimationLetter</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ION')">BC_ION</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_LeakageOfWaterSupply')">BC_LeakageOfWaterSupply</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_LegalizationOfBuilding')">BC_LegalizationOfBuilding</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_LegalNotice')">BC_LegalNotice</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Letter')">BC_Letter</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_LiftingOfSteelSteps')">BC_LiftingOfSteelSteps</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_MasterPlan')">BC_MasterPlan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_MembershipCard')">BC_MembershipCard</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_MembershipForm')">BC_MembershipForm</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_MinuteSheet')">BC_MinuteSheet</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Misc')">BC_Misc</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_MiscCharges')">BC_MiscCharges</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_MiscellaneousComplains')">BC_MiscellaneousComplains</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_MiscellaneousRefunds')">BC_MiscellaneousRefunds</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_MiscellaneousRequests')">BC_MiscellaneousRequests</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_NDC')">BC_NDC</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_NDCApplyReceipt')">BC_NDCApplyReceipt</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_NOC')">BC_NOC</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_NikahNama')">BC_NikahNama</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Non-Transferable')">BC_Non-Transferable</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_NonPayment')">BC_NonPayment</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Notice')">BC_Notice</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_OpeningsSewargeConnection')">BC_OpeningsSewargeConnection</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_OpeningofResturant')">BC_OpeningofResturant</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_OutgoingMails')">BC_OutgoingMails</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_OwnershipDeed')">BC_OwnershipDeed</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ParticularsPerformaOwners')">BC_ParticularsPerformaOwners</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Passport')">BC_Passport</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PaymentofDues')">BC_PaymentofDues</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissionForDigging')">BC_PermissionForDigging</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissionForInstallationOfSkyBoardOnRoof')">BC_PermissionForInstallationOfSkyBoardOnRoof</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissionForWaterPump')">BC_PermissionForWaterPump</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissionToUseThe RooftopOfCommercialBuilding')">BC_PermissionToUseThe RooftopOfCommercialBuilding</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissionforMaterial')">BC_PermissionforMaterial</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissionforRenovation')">BC_PermissionforRenovation</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissionforUseofExcavator')">BC_PermissionforUseofExcavator</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissionforinstallationofWindowAC')">BC_PermissionforinstallationofWindowAC</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PermissiontoUsePlotasLoan')">BC_PermissiontoUsePlotasLoan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Permissiontomortgage')">BC_Permissiontomortgage</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PhysicalDemarcation')">BC_PhysicalDemarcation</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Picture')">BC_Picture</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Plantation')">BC_Plantation</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PoliceReport')">BC_PoliceReport</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PossesionOfPlot')">BC_PossesionOfPlot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_PossesionofPlot')">BC_PossesionofPlot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ProposedDrawing')">BC_ProposedDrawing</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ProposedPlan')">BC_ProposedPlan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RefundOfExcavation')">BC_RefundOfExcavation</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RegistrationCertificate')">BC_RegistrationCertificate</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RegistrationForm')">BC_RegistrationForm</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RegistrationOfLabour')">BC_RegistrationOfLabour</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RegistrationofArchitects')">BC_RegistrationofArchitects</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RelinquishmentOfAllotment')">BC_RelinquishmentOfAllotment</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RemovalOfPartitionWall')">BC_RemovalOfPartitionWall</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RemovalOfTheSkyBoard')">BC_RemovalOfTheSkyBoard</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RemovalOfViolations')">BC_RemovalOfViolations</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RemovalofElectricTransformer')">BC_RemovalofElectricTransformer</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RemovalofUnauthorized Signage')">BC_RemovalofUnauthorized Signage</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Removalofplantation')">BC_Removalofplantation</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RenovationOfDetailOfBuilding')">BC_RenovationOfDetailOfBuilding</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RenovationOfHouse')">BC_RenovationOfHouse</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RenovationofBuilding')">BC_RenovationofBuilding</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RenovationwithoutPermission')">BC_RenovationwithoutPermission</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ReplacementOfSeweragePipes')">BC_ReplacementOfSeweragePipes</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ReqForConstruction')">BC_ReqForConstruction</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RequestForHandingOverOfDemarcationOfPlot')">BC_RequestForHandingOverOfDemarcationOfPlot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RequestForNDC')">BC_RequestForNDC</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RequestForm')">BC_RequestForm</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RentAgreement')">BC_RentAgreement</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RevisedDrawings')">BC_RevisedDrawings</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_RoutineInspectionReport')">BC_RoutineInspectionReport</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SaleDeed')">BC_SaleDeed</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SaleOfPlot')">BC_SaleOfPlot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SanctionofBuildingPlan')">BC_SanctionofBuildingPlan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SewerageOpening')">BC_SewerageOpening</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ShiftingofTelephonePoleWire')">BC_ShiftingofTelephonePoleWire</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SiteCheckForm')">BC_SiteCheckForm</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SitePlan')">BC_SitePlan</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SiteReportLetter')">BC_SiteReportLetter</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SpecialPowerOfAttorney')">BC_SpecialPowerOfAttorney</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_StackingOfConstructionMaterial')">BC_StackingOfConstructionMaterial</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_StabilityCertificate')">BC_StabilityCertificate</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_StatementofAccount')">BC_StatementofAccount</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_StructureDetailOfPlaza')">BC_StructureDetailOfPlaza</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SubDivisionofPlots')">BC_SubDivisionofPlots</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SubmissionOfDrawing')">BC_SubmissionOfDrawing</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SuiGasConnection')">BC_SuiGasConnection</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SurchargesOfPlot')">BC_SurchargesOfPlot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_SurveyorReport')">BC_SurveyorReport</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_TIPTax')">BC_TIPTax</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_TelephoneBill')">BC_TelephoneBill</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_TemporarySewerageConnection')">BC_TemporarySewerageConnection</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ToWhomItMayConcern')">BC_ToWhomItMayConcern</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_TowerSpecification')">BC_TowerSpecification</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_TransferDeed')">BC_TransferDeed</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_TransferLetter')">BC_TransferLetter</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_TransferOfPlot')">BC_TransferOfPlot</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Transferable')">BC_Transferable</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_UnauthorizedWaterConnection')">BC_UnauthorizedWaterConnection</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Undertakings')">BC_Undertakings</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_UsingOpenplotforMaterialHut')">BC_UsingOpenplotforMaterialHut</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_UtilityBill')">BC_UtilityBill</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_Violation')">BC_Violation</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ViolationOfDHAByelaws')">BC_ViolationOfDHAByelaws</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ViolationofAgreement')">BC_ViolationofAgreement</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_ViolationofConstructionByLaws')">BC_ViolationofConstructionByLaws</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_WallChalkingOnCommercialBuilding')">BC_WallChalkingOnCommercialBuilding</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_WaterConnection')">BC_WaterConnection</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_WaterSewerageBill')">BC_WaterSewerageBill</div>
              <div class="class-combo-item" data-tab="Building_Control" onclick="selectClassOption('BC_WierlessLocalLoopLicense')">BC_WierlessLocalLoopLicense</div>

              <!-- Land Acquisition Classes -->
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_Advertisement')">ACQN_Advertisement</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_Affidavit')">ACQN_Affidavit</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_ApplicationGen')">ACQN_ApplicationGen</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_Challan')">ACQN_Challan</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_CNIC')">ACQN_CNIC</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_CourtOrder')">ACQN_CourtOrder</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_DuplicateCourtOreder')">ACQN_DuplicateCourtOreder</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_FardeMalkiat')">ACQN_FardeMalkiat</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_FileCover')">ACQN_FileCover</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_IncomingMails')">ACQN_IncomingMails</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_IndemnityBond')">ACQN_IndemnityBond</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_IntimationLetter')">ACQN_IntimationLetter</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_ION')">ACQN_ION</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_JamaBandi')">ACQN_JamaBandi</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_KhasraMaps')">ACQN_KhasraMaps</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_LandSaleForm')">ACQN_LandSaleForm</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_MinuteSheet')">ACQN_MinuteSheet</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_Misc')">ACQN_Misc</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_Mutation')">ACQN_Mutation</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_NDC')">ACQN_NDC</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_NonEncumbranceCertificate')">ACQN_NonEncumbranceCertificate</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_OutgoingMails')">ACQN_OutgoingMails</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_PatwariReport')">ACQN_PatwariReport</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_Photographs1xLO1xInvestor')">ACQN_Photographs1xLO1xInvestor</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_PossessionCertificate')">ACQN_PossessionCertificate</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_PowerOfAttorney')">ACQN_PowerOfAttorney</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_PropertyDistributionApplication')">ACQN_PropertyDistributionApplication</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_ProvisionOfRecord')">ACQN_ProvisionOfRecord</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_SaleAgreementorDeed')">ACQN_SaleAgreementorDeed</div>
              <div class="class-combo-item" data-tab="Land_Acquisition" onclick="selectClassOption('ACQN_UndertakinginFavorofInvestor')">ACQN_UndertakinginFavorofInvestor</div>


              <!-- Transfer Branch Classes -->
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Acceptance')">TFR_Acceptance</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Advertisement')">TFR_Advertisement</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Affidavit')">TFR_Affidavit</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_AgreementToSellaPlot')">TFR_AgreementToSellaPlot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_AllocationLetter')">TFR_AllocationLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_AllotmentLetter')">TFR_AllotmentLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_AllotmentOfAccessArea')">TFR_AllotmentOfAccessArea</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_AmalgamationofPlots')">TFR_AmalgamationofPlots</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Application')">TFR_Application</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Application(LeackageOfConfidentialFile)')">TFR_Application(LeackageOfConfidentialFile)</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForAllocationLetter')">TFR_ApplicationForAllocationLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForAllotmentLetter')">TFR_ApplicationForAllotmentLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForAssociateMemberShip')">TFR_ApplicationForAssociateMemberShip</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationforAuthorityLetter')">TFR_ApplicationforAuthorityLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForChangeOfAddress')">TFR_ApplicationForChangeOfAddress</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForChangeOfName')">TFR_ApplicationForChangeOfName</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForCuttingOfTree')">TFR_ApplicationForCuttingOfTree</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForDuplicateAllotmentLetter')">TFR_ApplicationForDuplicateAllotmentLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForIntimation')">TFR_ApplicationForIntimation</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForm')">TFR_ApplicationForm</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForMembershipCard')">TFR_ApplicationForMembershipCard</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForNDC')">TFR_ApplicationForNDC</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForNOC')">TFR_ApplicationForNOC</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForPaymentOfDues')">TFR_ApplicationForPaymentOfDues</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForRegistration')">TFR_ApplicationForRegistration</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForRegularMemebership')">TFR_ApplicationForRegularMemebership</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForSitePlan')">TFR_ApplicationForSitePlan</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationforTransfer')">TFR_ApplicationforTransfer</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationForVerification')">TFR_ApplicationForVerification</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApplicationStayOrderRemoval')">TFR_ApplicationStayOrderRemoval</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApprovalOfDrawing')">TFR_ApprovalOfDrawing</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ApprovalofRevised Drawing')">TFR_ApprovalofRevised Drawing</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Attestment')">TFR_Attestment</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_AuthorityLetter')">TFR_AuthorityLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_AuthorityLetterPaymentReceiving')">TFR_AuthorityLetterPaymentReceiving</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Ballot')">TFR_Ballot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_BankLetter')">TFR_BankLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_B-FormforMinors')">TFR_B-FormforMinors</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_BianaPapers')">TFR_BianaPapers</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CancellationDeed')">TFR_CancellationDeed</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CancellationOfPowerOfAttorney')">TFR_CancellationOfPowerOfAttorney</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CancellationOfSpecialAttorney')">TFR_CancellationOfSpecialAttorney</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CanttBoardTransferTax')">TFR_CanttBoardTransferTax</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CapitalValueTax')">TFR_CapitalValueTax</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CautionDocs')">TFR_CautionDocs</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CautionOnAllotmentOfPlot')">TFR_CautionOnAllotmentOfPlot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Certificate')">TFR_Certificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Challan')">TFR_Challan</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ChangeOfAddress')">TFR_ChangeOfAddress</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ChangeOfName')">TFR_ChangeOfName</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ChangeOfNameConfirmation')">TFR_ChangeOfNameConfirmation</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ChangeOfOwnership')">TFR_ChangeOfOwnership</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Changeofplot')">TFR_Changeofplot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CheckList')">TFR_CheckList</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CheckListForDeathCertificate')">TFR_CheckListForDeathCertificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CheckListForNDCIssue')">TFR_CheckListForNDCIssue</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CheckSheet')">TFR_CheckSheet</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CheckSheetForChangeOfName')">TFR_CheckSheetForChangeOfName</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ClearenceLetter')">TFR_ClearenceLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ClearenceOfDues')">TFR_ClearenceOfDues</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ClientLedger')">TFR_ClientLedger</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CNIC')">TFR_CNIC</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Complain')">TFR_Complain</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CompletionCertificate')">TFR_CompletionCertificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ConfirmationLetterforForeignCase')">TFR_ConfirmationLetterforForeignCase</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ConfirmationOfBooking')">TFR_ConfirmationOfBooking</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Construction')">TFR_Construction</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ConstructionBoundaryWall')">TFR_ConstructionBoundaryWall</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ConstructionOfShop')">TFR_ConstructionOfShop</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ConstructionVoilation')">TFR_ConstructionVoilation</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CornerPlotCharges')">TFR_CornerPlotCharges</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CourtCase')">TFR_CourtCase</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CourtDecree')">TFR_CourtDecree</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CourtNotice')">TFR_CourtNotice</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_CoveringLetter')">TFR_CoveringLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_DeathCertificate')">TFR_DeathCertificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_DeclarationOfOralGift')">TFR_DeclarationOfOralGift</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_DemandDraft')">TFR_DemandDraft</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Demarcation')">TFR_Demarcation</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_DemorcationOfPlot')">TFR_DemorcationOfPlot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_DevelopmentCharges')">TFR_DevelopmentCharges</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_DhaCityProject')">TFR_DhaCityProject</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Drawing')">TFR_Drawing</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_DuplicateAllotmentLetter')">TFR_DuplicateAllotmentLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_DuplicateTransferLetter')">TFR_DuplicateTransferLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_EstateAgentCard')">TFR_EstateAgentCard</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_FileCover')">TFR_FileCover</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_FileMovementRecord')">TFR_FileMovementRecord</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Form')">TFR_Form</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_FormA')">TFR_FormA</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_FormB')">TFR_FormB</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ForwardingLetter')">TFR_ForwardingLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_GasConnection')">TFR_GasConnection</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_GeneralCorrespondence')">TFR_GeneralCorrespondence</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_GeneralPowerOfAttorney')">TFR_GeneralPowerOfAttorney</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_GeninenessCertificate')">TFR_GeninenessCertificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_IncomingMails')">TFR_IncomingMails</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_IndividualAttestationConfirmation')">TFR_IndividualAttestationConfirmation</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Installations')">TFR_Installations</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_IntimationLetter')">TFR_IntimationLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ION')">TFR_ION</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_IssuanceOfAllocationLetterAgainstAffidavit')">TFR_IssuanceOfAllocationLetterAgainstAffidavit</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_LegalHiersLetterandNOKs')">TFR_LegalHiersLetterandNOKs</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Letter')">TFR_Letter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_LienAgainstPlot')">TFR_LienAgainstPlot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_LienMarking')">TFR_LienMarking</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_LienRemoval')">TFR_LienRemoval</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_LitigationOfPlot')">TFR_LitigationOfPlot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_MemberhipOfTheSociety')">TFR_MemberhipOfTheSociety</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Membershipcard')">TFR_Membershipcard</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_MembershipForm')">TFR_MembershipForm</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_MinuteSheet')">TFR_MinuteSheet</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Misc')">TFR_Misc</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_MiscellaniousCharges')">TFR_MiscellaniousCharges</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_MiscellaniousRefunds')">TFR_MiscellaniousRefunds</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_MortgageDeed')">TFR_MortgageDeed</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_MortgageOf Plot')">TFR_MortgageOf Plot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_MutationOfProperty')">TFR_MutationOfProperty</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_NDC')">TFR_NDC</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_NDCApplyReciept')">TFR_NDCApplyReciept</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_NikahNama')">TFR_NikahNama</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_NOC')">TFR_NOC</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_NOCforARMYOfficers')">TFR_NOCforARMYOfficers</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_NonEncumbrnceCertificate')">TFR_NonEncumbrnceCertificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_NonTransferable')">TFR_NonTransferable</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Notice')">TFR_Notice</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_OpeningOfSewerage')">TFR_OpeningOfSewerage</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_OralGiftDeed')">TFR_OralGiftDeed</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_OutgoingMails')">TFR_OutgoingMails</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_OwnershipCertificate')">TFR_OwnershipCertificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Passport')">TFR_Passport</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PaymentOfConstructionPenality')">TFR_PaymentOfConstructionPenality</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PaymentOfInstallments')">TFR_PaymentOfInstallments</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PaymentofOutStandingDues')">TFR_PaymentofOutStandingDues</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PaymentOfPension')">TFR_PaymentOfPension</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PaymentorDevelopmentCharges')">TFR_PaymentorDevelopmentCharges</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PaymentPlan')">TFR_PaymentPlan</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PaymentReceipt')">TFR_PaymentReceipt</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PermissionToMortgage')">TFR_PermissionToMortgage</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PermissionTosaleofplot')">TFR_PermissionTosaleofplot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PlotVerification')">TFR_PlotVerification</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PoliceReport')">TFR_PoliceReport</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PossesionOfplot')">TFR_PossesionOfplot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ProvisionOfInformation')">TFR_ProvisionOfInformation</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ProvisionOfPropertyRecord')">TFR_ProvisionOfPropertyRecord</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_PublicNotice')">TFR_PublicNotice</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Receipt')">TFR_Receipt</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ReceiptOfDevelopmentCharges')">TFR_ReceiptOfDevelopmentCharges</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RecieptAndAcknowledgement')">TFR_RecieptAndAcknowledgement</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RedemptionDeed')">TFR_RedemptionDeed</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RedressalOfgrieveness')">TFR_RedressalOfgrieveness</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RegistrationForm')">TFR_RegistrationForm</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RelinquishmentOfAllotment')">TFR_RelinquishmentOfAllotment</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RemovalOfCaution')">TFR_RemovalOfCaution</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RemovalofElectricpool')">TFR_RemovalofElectricpool</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RequestforNDC')">TFR_RequestforNDC</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RequestForOutstandingDues')">TFR_RequestForOutstandingDues</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_RevisedPaymentShedule')">TFR_RevisedPaymentShedule</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_SaleDeed')">TFR_SaleDeed</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Saleofplot')">TFR_Saleofplot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ScrutinyReport')">TFR_ScrutinyReport</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_SitePlan')">TFR_SitePlan</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_SpecialPowerofAttorney')">TFR_SpecialPowerofAttorney</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_StampDutyTax')">TFR_StampDutyTax</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_StandardPlot')">TFR_StandardPlot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_StatementofAccount')">TFR_StatementofAccount</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_StatementOfNdcDeatails')">TFR_StatementOfNdcDeatails</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_StatementOfOutstandingDues')">TFR_StatementOfOutstandingDues</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_SubDivisionofPlots')">TFR_SubDivisionofPlots</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_SubmissionOfCertificate')">TFR_SubmissionOfCertificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_SubmissionReceipt')">TFR_SubmissionReceipt</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_SurchargesOfPlot')">TFR_SurchargesOfPlot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_SurveyReport')">TFR_SurveyReport</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_TCSPaper')">TFR_TCSPaper</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Terms&Conditions')">TFR_Terms&amp;Conditions</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_TermsAndConditionsOfBooking')">TFR_TermsAndConditionsOfBooking</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_ToWhomItmayConcern')">TFR_ToWhomItmayConcern</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_TransferAndMutation')">TFR_TransferAndMutation</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_TransferDeed')">TFR_TransferDeed</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_TransferLetter')">TFR_TransferLetter</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_TransferOfPlaza')">TFR_TransferOfPlaza</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_TransferOfPlot')">TFR_TransferOfPlot</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_TransferPhotographs')">TFR_TransferPhotographs</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Undertaking')">TFR_Undertaking</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_UndertakingByTheDonee')">TFR_UndertakingByTheDonee</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_UndertakingByThePurchaser')">TFR_UndertakingByThePurchaser</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_VariationCertificate')">TFR_VariationCertificate</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_Verification')">TFR_Verification</div>
              <div class="class-combo-item" data-tab="Transfer" onclick="selectClassOption('TFR_VerificationForm')">TFR_VerificationForm</div>
            </div>
            <span id="classError" style="display:none;color:#dc2626;font-size:0.75rem;margin-top:3px;display:none;"><i class="fas fa-exclamation-circle me-1"></i>Please select a valid Class Name from the list.</span>
          </div>

          <!-- Row 3: FILE NAME / NO -->
          <div id="fileNameField">
            <label class="workflow-label">FILE NAME</label>
            <input type="text" class="workflow-input" id="inputFileName" oninput="autoGenerateName()" placeholder="File Name">
          </div>



          <!-- Row 4: PAGE NO, PAGE COUNT, DATE -->
          <div class="row g-2" id="pageDateFields">
            <div class="col-3">
              <label class="workflow-label">PAGE NO</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="inputPageNo" oninput="this.value = this.value.replace(/[^0-9]/g, ''); autoGenerateName();" placeholder="Page">
            </div>
            <div class="col-3">
              <label class="workflow-label">PAGE COUNT</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="workflow-input text-center" id="inputPageCount" oninput="this.value = this.value.replace(/[^0-9]/g, ''); autoGenerateName();" placeholder="Count">
            </div>
            <div class="col-6">
              <label class="workflow-label"><i class="fas fa-calendar-alt text-primary me-1"></i>DATE</label>
              <input type="text" inputmode="numeric" class="workflow-input" id="inputDate" placeholder="dd-mm-yyyy" autocomplete="off">
            </div>
          </div>

          <!-- Form Buttons -->
          <div class="row g-2">
            <div class="col-12">
              <button type="submit" class="workflow-btn workflow-btn-gold w-100" id="renameSubmitBtn">Rename File</button>
            </div>
            <div class="col-12" id="finishRenameRow">
              <button type="button" class="workflow-btn workflow-btn-green w-100" id="finishRenameBtn" onclick="finishRename()">Finish Rename</button>
            </div>
          </div>
        </form>

        <!-- Document Queue: Browsed folder files (top) + DB docs (below) -->
        <div class="queue-container flex-grow-1 mt-2">
          <div id="folderQueueOrder" class="d-none px-2 py-1" style="font-size:0.7rem;color:var(--text-secondary);background:var(--surface-2);border-bottom:1px solid var(--border);"></div>
          <div class="queue-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-1">
              <span>Document Queue (<span id="queueTotalCount"><?= $docs->num_rows ?></span>)</span>
              <span class="badge text-bg-success ms-1 d-none" id="autoSyncBadge" style="font-size:0.6rem;font-weight:600;"><i class="fas fa-sync-alt fa-spin me-1"></i>Auto-Sync</span>
            </div>
            <div class="d-flex align-items-center gap-1">
              <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 d-none" id="refreshDirBtn"
                      onclick="rescanWatchedDirectories()" style="font-size:0.65rem;height:22px;" title="Refresh System Directory">
                <i class="fas fa-sync-alt me-1"></i>Refresh
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 d-none" id="clearBrowsedBtn"
                      onclick="clearAllBrowsed()" style="font-size:0.65rem;height:22px;" title="Clear browsed files">
                <i class="fas fa-folder-minus me-1"></i>Clear
              </button>
              <form method="GET" class="d-flex align-items-center gap-1" style="margin:0;">
                <input type="text" name="search" class="form-control form-control-sm"
                       style="font-size:0.7rem; height:24px; padding:2px 6px; width:100px;"
                       placeholder="Search..." value="<?= esc($search) ?>" <?= $filterFolderName !== '' ? 'disabled' : '' ?>>
                <button type="submit" class="btn btn-sm btn-primary py-0 px-2" style="font-size:0.7rem;height:24px;" <?= $filterFolderName !== '' ? 'disabled' : '' ?>><i class="fas fa-search"></i></button>
                <?php if ($search || $filterFolderName !== ''): ?>
                  <a href="rename.php" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.7rem;height:24px;display:flex;align-items:center;">Clear</a>
                <?php endif; ?>
              </form>
            </div>
          </div>
          <div class="queue-list" id="mainQueueList">
            <!-- ▲ Browsed folder files appear here (pending files always on top) -->
            <div id="browsedQueueSection"></div>
            <!-- ▼ DB documents awaiting rename appear here -->
            <div id="dbQueueSection">
            <?php
            if ($docs->num_rows > 0):
              $docs->data_seek(0);
              while ($doc = $docs->fetch_assoc()):
                  $isPdf = ($doc['file_type'] === 'pdf');
                  $icon = $isPdf ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
                  $isRenamed = !empty($doc['renamed_filename']);
              ?>
                <div class="queue-item"
                     data-id="<?= $doc['document_id'] ?>"
                     data-raw="<?= esc($doc['raw_filename']) ?>"
                     data-renamed="<?= esc($doc['renamed_filename'] ?? '') ?>"
                     data-db-folder="<?= esc($doc['folder_name'] ?? '') ?>"
                     data-branch="<?= esc($doc['branch'] ?? '') ?>"
                     data-doctype="<?= esc($doc['doc_type'] ?? '') ?>"
                     data-fileno="<?= esc($doc['file_no'] ?? '') ?>"
                     data-phase="<?= esc($doc['phase'] ?? '') ?>"
                     data-plot="<?= esc($doc['plot'] ?? '') ?>"
                     data-year="<?= esc($doc['doc_year'] ?? '') ?>"
                     data-path="<?= esc($doc['storage_path']) ?>"
                     data-ext="<?= esc($doc['file_type']) ?>"
                     onclick="selectDocument(this)">
                  <i class="fas <?= $icon ?>"></i>
                  <span class="text-truncate" style="max-width: 250px;"><?= esc($doc['renamed_filename'] ?: $doc['raw_filename']) ?></span>
                  <?php if ($isRenamed): ?>
                    <i class="fas fa-check-circle text-success ms-auto" style="font-size: 0.8rem;"></i>
                  <?php endif; ?>
                </div>
              <?php endwhile;
            elseif ($search): ?>
              <div class="p-3 text-center text-muted" style="font-size: 0.8rem;">No documents found.</div>
            <?php else: ?>
              <div class="p-3 text-center text-muted" style="font-size: 0.8rem;">
                <i class="fas fa-folder-open mb-2 d-block" style="font-size:1.4rem;opacity:.5;"></i>
                Click <strong>Browse</strong> above to open a folder and load files to rename.
              </div>
            <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<style>
  /* ── Drag & Drop Overlay ── */
  .drag-drop-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 31, 61, 0.85);
    backdrop-filter: blur(8px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    transition: opacity 0.3s ease;
    pointer-events: none;
  }
  .drag-drop-overlay.active {
    pointer-events: auto;
  }
  .drag-drop-content {
    text-align: center;
    background: #ffffff;
    color: #0f1f3d;
    padding: 40px;
    border-radius: 16px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
    max-width: 500px;
    width: 90%;
    border: 3px dashed #0ea5e9;
    animation: scaleUp 0.2s ease-out;
  }
  @keyframes scaleUp {
    from { transform: scale(0.9); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
  }

  /* ── Split Container ── */
  .rename-container {
    display: grid;
    grid-template-columns: 1fr 480px;
    gap: 20px;
    height: calc(100vh - 180px);
    min-height: 600px;
    margin-bottom: 20px;
  }

  @media (max-width: 992px) {
    .rename-container {
      grid-template-columns: 1fr;
      height: auto;
    }
  }

  /* ── Left Preview Panel ── */
  .preview-column {
    display: flex;
    flex-direction: column;
    height: 100%;
  }

  .preview-column .dha-card {
    height: 100%;
  }

  .img-preview-container {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: auto;
    padding: 20px;
  }

  /* ── Floating Zoom Overlay ── */
  .zoom-controls-overlay {
    position: absolute;
    bottom: 20px;
    right: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    background: #0f172a;
    border-radius: 30px;
    padding: 6px 14px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    z-index: 100;
  }

  .zoom-btn {
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    font-size: 0.9rem;
    padding: 4px;
    transition: color 0.2s;
  }

  .zoom-btn:hover {
    color: #fff;
  }

  .zoom-text {
    color: #fff;
    font-size: 0.85rem;
    font-weight: 600;
    min-width: 45px;
    text-align: center;
  }

  /* ── Right Workflow Panel ── */
  .workflow-column {
    height: 100%;
  }

  .workflow-column .dha-card {
    height: 100%;
  }

  /* ── Workflow Tabs ── */
  .workflow-tabs {
    background: #f1f5f9;
    padding: 4px;
    border-radius: 8px;
  }
  
  [data-theme="dark"] .workflow-tabs {
    background: #1e293b;
  }

  .workflow-tab {
    flex: 1;
    padding: 10px;
    border: none;
    background: none;
    border-radius: 6px;
    font-size: 0.92rem;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    text-align: center;
  }

  .workflow-tab:hover {
    color: #0f172a;
  }
  
  [data-theme="dark"] .workflow-tab:hover {
    color: #fff;
  }

  .workflow-tab.active {
    background: #d97706;
    color: #fff;
  }

  /* ── Form Inputs ── */
  .workflow-label {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 4px;
    display: block;
  }

  .workflow-input {
    width: 100%;
    height: 42px;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    padding: 6px 12px;
    font-size: 0.95rem;
    color: #0f172a;
    outline: none;
    transition: border-color 0.2s;
  }
  
  [data-theme="dark"] .workflow-input {
    border-color: #334155;
    background: #0f172a;
    color: #f1f5f9;
  }

  .workflow-input::placeholder {
    color: #9ca3af;
  }

  .workflow-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
  }

  .new-filename-readonly {
    background-color: #eff6ff !important;
    border-color: #3b82f6 !important;
    color: #1e3a8a !important;
    cursor: not-allowed;
    font-weight: 700;
  }
  [data-theme="dark"] .new-filename-readonly {
    background-color: #1e293b !important;
    border-color: #60a5fa !important;
    color: #93c5fd !important;
  }

  .workflow-input[readonly],
  .workflow-input:disabled {
    background-color: #f1f5f9 !important;
    color: #64748b !important;
    cursor: not-allowed;
    border-color: #cbd5e1 !important;
    opacity: 1 !important;
    -webkit-appearance: none;
    appearance: none;
  }
  [data-theme="dark"] .workflow-input[readonly],
  [data-theme="dark"] .workflow-input:disabled {
    background-color: #1e293b !important;
    color: #94a3b8 !important;
    border-color: #334155 !important;
  }

  /* ══ Custom Class Combo Box ════════════════════════════════ */
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

  .workflow-select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    background-size: 14px;
    padding-right: 32px;
  }

  /* ── Custom Buttons ── */
  .workflow-btn {
    height: 46px;
    border-radius: 6px;
    border: none;
    font-size: 0.95rem;
    font-weight: 600;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: opacity 0.2s, transform 0.1s;
  }

  .workflow-btn:hover {
    opacity: 0.9;
  }

  .workflow-btn:active {
    transform: scale(0.98);
  }

  .workflow-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }

  .workflow-btn:disabled:hover {
    opacity: 0.45;
  }

  .workflow-btn-gold {
    background: #d97706;
  }

  .workflow-btn-blue {
    background: #1d4ed8;
  }

  .workflow-btn-green {
    background: #15803d;
    width: 100%;
  }

  /* ── Document List Queue ── */
  .queue-container {
    display: flex;
    flex-direction: column;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    min-height: 180px;
    max-height: 250px;
  }
  
  [data-theme="dark"] .queue-container {
    border-color: #334155;
  }

  .queue-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 8px 12px;
    font-size: 0.75rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
  }
  
  [data-theme="dark"] .queue-header {
    background: #1e293b;
    border-color: #334155;
  }

  .queue-list {
    overflow-y: auto;
    background: #ffffff;
    flex-grow: 1;
  }
  
  [data-theme="dark"] .queue-list {
    background: #0f172a;
  }

  .queue-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    font-size: 0.8rem;
    color: #334155;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.2s;
  }
  
  [data-theme="dark"] .queue-item {
    color: #cbd5e1;
    border-color: #1e293b;
  }

  .queue-item:hover {
    background: #f1f5f9;
  }
  
  [data-theme="dark"] .queue-item:hover {
    background: #1e293b;
  }

  .queue-item.active {
    background: #eff6ff;
    border-left: 3px solid #3b82f6;
    font-weight: 600;
  }
  
  [data-theme="dark"] .queue-item.active {
    background: #1e3a8a;
    border-left-color: #60a5fa;
  }

  .queue-item i {
    flex-shrink: 0;
  }

  /* ══ Browse Button ══════════════════════════════════════════ */
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
  .browse-badge {
    background: rgba(96,165,250,0.18); color: #93c5fd;
    font-size: 0.62rem; font-weight: 700; padding: 2px 8px;
    border-radius: 20px; letter-spacing: 0.5px; text-transform: uppercase;
    border: 1px solid rgba(96,165,250,0.3);
  }

  /* ══ Folder Group Header (inside Queue) ═════════════════════ */
  .folder-group-header {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 12px;
    background: linear-gradient(135deg, #f0f7ff 0%, #eff6ff 100%);
    border-top: 2px solid #bfdbfe;
    border-bottom: 1px solid #dbeafe;
    font-size: 0.72rem; font-weight: 700;
    color: #1e3a8a; letter-spacing: 0.4px;
    position: sticky; top: 0; z-index: 3;
  }
  [data-theme="dark"] .folder-group-header {
    background: linear-gradient(135deg, #1e293b 0%, #172554 100%);
    color: #93c5fd; border-color: #334155;
  }
  .folder-group-header:first-child { border-top: none; }

  .folder-file-count {
    background: #dbeafe; color: #1d4ed8;
    font-size: 0.62rem; font-weight: 700;
    padding: 2px 8px; border-radius: 20px;
    border: 1px solid #bfdbfe;
  }
  [data-theme="dark"] .folder-file-count {
    background: #1e3a8a; color: #93c5fd; border-color: #2563eb;
  }

  /* pending amber dot */
  .pending-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #f59e0b; flex-shrink: 0;
    box-shadow: 0 0 0 2px rgba(245,158,11,0.2);
    transition: background 0.2s, box-shadow 0.2s;
  }
  .queue-item.active .pending-dot {
    background: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59,130,246,0.25);
  }

  /* ══ Queue scroll smooth ════════════════════════════════════ */
  .queue-list { scroll-behavior: smooth; }
  .queue-list::-webkit-scrollbar { width: 4px; }
  .queue-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
  [data-theme="dark"] .queue-list::-webkit-scrollbar-thumb { background: #475569; }

  .workflow-btn-browse {
    background: linear-gradient(135deg, #0c1445 0%, #1e3a8a 100%);
    border: 1.5px dashed rgba(96,165,250,0.55);
    transition: all 0.25s;
    gap: 8px;
  }
  .workflow-btn-browse:hover {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    border-color: rgba(147,197,253,0.85);
    box-shadow: 0 4px 22px rgba(30,58,138,0.5);
    opacity: 1;
    transform: translateY(-1px);
  }
  .browse-badge {
    background: rgba(96,165,250,0.18);
    color: #93c5fd;
    font-size: 0.62rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    border: 1px solid rgba(96,165,250,0.3);
  }

  /* ══ Folder Selector ════════════════════════════════════════ */
  .folder-selector-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .folder-pending-badge {
    flex-shrink: 0;
    background: #fef3c7;
    color: #92400e;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 20px;
    border: 1px solid #fcd34d;
    white-space: nowrap;
    transition: all 0.3s;
  }
  .folder-pending-badge.done {
    background: #dcfce7;
    color: #166534;
    border-color: #86efac;
  }

  /* ══ Browsed Files Panel ════════════════════════════════════ */
  .browsed-panel {
    border: 1px solid rgba(96,165,250,0.25);
    border-radius: 10px;
    overflow: hidden;
    background: var(--bs-white, #ffffff);
    box-shadow: 0 2px 12px rgba(30,58,138,0.08);
  }
  [data-theme="dark"] .browsed-panel {
    background: #0d1b2e;
    border-color: rgba(96,165,250,0.18);
  }
  .browsed-panel-header {
    background: linear-gradient(135deg, rgba(30,58,138,0.1), rgba(59,130,246,0.06));
    padding: 8px 14px;
    font-size: 0.72rem;
    font-weight: 700;
    color: #1d4ed8;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    display: flex;
    align-items: center;
    gap: 7px;
    border-bottom: 1px solid rgba(96,165,250,0.18);
  }
  [data-theme="dark"] .browsed-panel-header { color: #60a5fa; }

  .browsed-clear-btn {
    background: none; border: none;
    color: #94a3b8; cursor: pointer;
    font-size: 0.75rem; padding: 2px 6px;
    border-radius: 4px; line-height: 1;
    transition: color 0.2s, background 0.2s;
  }
  .browsed-clear-btn:hover { color: #ef4444; background: rgba(239,68,68,0.08); }

  .browsed-file-list {
    max-height: 180px;
    overflow-y: auto;
    scroll-behavior: smooth;
    padding: 4px 0;
  }
  .browsed-file-list::-webkit-scrollbar { width: 4px; }
  .browsed-file-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

  .browsed-file-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 14px;
    font-size: 0.78rem;
    cursor: pointer;
    border-bottom: 1px solid rgba(96,165,250,0.07);
    transition: background 0.15s;
    color: #1e293b;
    position: relative;
  }
  [data-theme="dark"] .browsed-file-item { color: #cbd5e1; }
  .browsed-file-item:last-child { border-bottom: none; }
  .browsed-file-item:hover { background: rgba(59,130,246,0.06); }
  .browsed-file-item.active {
    background: #eff6ff;
    border-left: 3px solid #2563eb;
    font-weight: 600;
    padding-left: 11px;
  }
  [data-theme="dark"] .browsed-file-item.active {
    background: rgba(30,58,138,0.35);
    border-left-color: #60a5fa;
  }
  .browsed-file-item.renamed {
    opacity: 0.45;
    text-decoration: line-through;
    pointer-events: none;
  }

  .browsed-file-icon { flex-shrink: 0; }
  .browsed-file-name {
    flex: 1; overflow: hidden;
    text-overflow: ellipsis; white-space: nowrap;
  }
  .browsed-file-size { font-size: 0.62rem; color: #94a3b8; flex-shrink: 0; }
  .browsed-file-remove {
    background: none; border: none; color: #94a3b8;
    cursor: pointer; padding: 0 4px; font-size: 0.7rem;
    line-height: 1; border-radius: 3px; flex-shrink: 0;
    transition: color 0.15s;
  }
  .browsed-file-remove:hover { color: #ef4444; }

  /* ══ All-Done Message ═══════════════════════════════════════ */
  .all-done-msg {
    padding: 14px;
    text-align: center;
    font-size: 0.82rem;
    font-weight: 700;
    color: #15803d;
    background: #f0fdf4;
    border-top: 1px solid #bbf7d0;
    animation: fadeSlideIn 0.4s ease;
  }
  [data-theme="dark"] .all-done-msg {
    background: #052e16;
    color: #86efac;
    border-color: #166534;
  }
  @keyframes fadeSlideIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  /* ── Rename KPI Banner ────────────────────────────────────── */
  .rename-kpi-card {
    background: linear-gradient(135deg, rgba(30,77,183,0.08) 0%, rgba(11,19,41,0.04) 100%);
    border-left: 5px solid var(--accent);
  }
  .rename-kpi-body {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1.5rem; flex-wrap: wrap; padding: 1.25rem 1.5rem;
  }
  .rename-kpi-info { display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 260px; }
  .rename-kpi-icon {
    width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
    background-color: rgba(30,77,183,0.15); color: #1e4db7;
    display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
  }
  .rename-kpi-title { font-weight: 800; color: var(--text-primary); margin: 0 0 0.2rem 0; font-size: 1rem; }
  .rename-kpi-sub   { color: var(--text-secondary); font-size: 0.8rem; margin: 0; }
  .rename-kpi-progress-wrap { flex: 1.4; min-width: 240px; }
  .rename-kpi-progress-track { height: 10px; background-color: var(--border); border-radius: 8px; overflow: hidden; }
  .rename-kpi-progress-fill  { height: 100%; background: linear-gradient(90deg, #1e4db7, #0ea5e9); border-radius: 8px; transition: width 0.4s ease; }
  .rename-kpi-progress-labels { display: flex; justify-content: space-between; margin-top: 0.4rem; font-size: 0.72rem; font-weight: 600; color: var(--text-secondary); }
  .rename-kpi-percent { flex-shrink: 0; text-align: center; min-width: 110px; }
  .rename-kpi-percent-value { font-size: 1.8rem; font-weight: 900; color: var(--text-primary); line-height: 1; }
  .rename-kpi-percent-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-top: 0.2rem; }
</style>

<!-- CLASS NAME reference list + fuzzy-match helper — shared with
     pages/validate.php so both pages' Escape-to-autocomplete stay in sync. -->
<script src="<?= $base ?>assets/js/allowed_classes.js"></script>

<script>
// ══════════════════════════════════════════════════════════════
// rename.php — JavaScript v4
// ══════════════════════════════════════════════════════════════
let currentZoom = 100;

// ── IndexedDB Helper to persist directory handles ────────────────
const DB_NAME = 'DMS_FolderSelectorDB';
const DB_VERSION = 1;
const STORE_NAME = 'folders';

function getDB() {
  return new Promise((resolve, change) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = (e) => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'folderName' });
      }
    };
    request.onsuccess = (e) => resolve(e.target.result);
    request.onerror = (e) => change(e.target.error);
  });
}

async function saveFolderHandle(folderName, dirHandle) {
  try {
    const db = await getDB();
    const tx = db.transaction(STORE_NAME, 'readwrite');
    const store = tx.objectStore(STORE_NAME);
    await new Promise((resolve, change) => {
      const req = store.put({ folderName, dirHandle });
      req.onsuccess = resolve;
      req.onerror = () => change(req.error);
    });
  } catch (err) {
    console.error('Failed to save folder handle to IndexedDB:', err);
  }
}

async function deleteFolderHandle(folderName) {
  try {
    const db = await getDB();
    const tx = db.transaction(STORE_NAME, 'readwrite');
    const store = tx.objectStore(STORE_NAME);
    await new Promise((resolve, change) => {
      const req = store.delete(folderName);
      req.onsuccess = resolve;
      req.onerror = () => change(req.error);
    });
  } catch (err) {
    console.error('Failed to delete folder handle from IndexedDB:', err);
  }
}

async function clearFolderHandles() {
  try {
    const db = await getDB();
    const tx = db.transaction(STORE_NAME, 'readwrite');
    const store = tx.objectStore(STORE_NAME);
    await new Promise((resolve, change) => {
      const req = store.clear();
      req.onsuccess = resolve;
      req.onerror = () => change(req.error);
    });
  } catch (err) {
    console.error('Failed to clear folder handles from IndexedDB:', err);
  }
}

async function loadSavedFolderHandles() {
  try {
    const db = await getDB();
    const tx = db.transaction(STORE_NAME, 'readonly');
    const store = tx.objectStore(STORE_NAME);
    return await new Promise((resolve, change) => {
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => change(req.error);
    });
  } catch (err) {
    console.error('Failed to load folder handles from IndexedDB:', err);
    return [];
  }
}

async function restoreSavedFolders() {
  // Clear all saved folder handles on page load so the browser
  // never shows a "Grant Access" / permission-restore prompt.
  // Users simply browse their folders fresh each session.
  await clearFolderHandles();
}

function renderRestoreButton(folderName, dirHandle) {
  const container = document.getElementById('browsedQueueSection');
  if (!container) return;

  const existing = document.getElementById(`restore-btn-${folderName.replace(/[^A-Za-z0-9]/g, '_')}`);
  if (existing) return;

  const div = document.createElement('div');
  div.id = `restore-btn-${folderName.replace(/[^A-Za-z0-9]/g, '_')}`;
  div.className = 'p-3 mb-2 rounded border d-flex flex-column gap-2';
  div.style.fontSize = '0.78rem';
  div.style.backgroundColor = '#2a1a08';
  div.style.color = '#eab308';
  div.style.borderColor = '#d97706';
  div.innerHTML = `
    <div class="d-flex align-items-center gap-2">
      <i class="fas fa-folder-open fa-lg"></i>
      <span>Folder <strong>${safeText(folderName)}</strong> needs permission to reload.</span>
    </div>
    <button type="button" class="btn btn-sm btn-warning w-100 fw-600 mt-1" style="font-size:0.75rem; background:#d97706; border:none; color:#fff;" onclick="requestFolderPermission('${encJs(folderName)}')">
      <i class="fas fa-key me-1"></i>Grant Access
    </button>
  `;
  container.appendChild(div);
  
  const clearBtn = document.getElementById('clearBrowsedBtn');
  if (clearBtn) clearBtn.classList.remove('d-none');
}

async function requestFolderPermission(folderName) {
  const item = watchedHandles.find(h => h.folderName === folderName);
  if (!item) return;

  try {
    const status = await item.dirHandle.requestPermission({ mode: 'readwrite' });
    if (status === 'granted') {
      const el = document.getElementById(`restore-btn-${folderName.replace(/[^A-Za-z0-9]/g, '_')}`);
      if (el) el.remove();

      await processDirectoryHandle(item.dirHandle, folderName);
      startAutoSyncWatcher();
      
      if (typeof showToast === 'function') {
        showToast(`Access granted to folder "${folderName}"`, 'success');
      }
    } else {
      alert('Access was not granted. Cannot load files.');
    }
  } catch (err) {
    console.error('Error requesting permission:', err);
    alert('Error requesting permission: ' + err.message);
  }
}

window.requestFolderPermission = requestFolderPermission;

// ── Data Store ─────────────────────────────────────────────────
// folderMap[folderName] = [{name, ext, objectUrl, size}, ...]
// Files are SPLICED OUT (deleted) when renamed — not just flagged
let folderMap         = {};
let folderOrder       = []; // Top-level folder names in the exact order they were selected via Browse — this is the single source of truth for processing order.
let currentFolder     = null;
let currentBrowsedIdx = -1;
let watchedHandles    = []; // Store of directory handles for auto-refresh
let autoSyncTimer     = null;
let renamedFilesSet      = new Set(); // Tracks 'folderName::fileName' of already-renamed files
let sessionDocNumber     = 1;         // Auto-incrementing Document Number (resets on page load)
let sessionRenamedDocIds = new Set(); // Tracks document_id of files renamed this session (for Verification filter)

// Per-folder rename token: folderTokenCounters[folderName] starts at 1 the
// moment that folder is (re-)opened and goes up by 1 every time a file from
// that SAME folder gets renamed. It's keyed off folderMap the same way the
// queue itself is, so it naturally resets to 1 whenever a folder is opened
// fresh — either the first time, or again later after all its files were
// renamed and it dropped out of folderMap (see renameBrowsedFile()).
let folderTokenCounters  = {};
// DB-queue items aren't tied to a browsed folder, so they get one running
// token instead, mirroring sessionDocNumber's existing reset-on-Finish
// behavior.
let dbQueueTokenCounter  = 1;

// ── Folder Name Parser: PHASE_SECTOR_PLOTNO & EXTENSION ──────────
function parseFolderName(folderName) {
  if (!folderName) return null;

  const normalized = folderName.replace(/\\/g, '/').replace(/\/+/g, '/').trim();
  const segments = normalized.split('/').filter(s => s.length > 0);
  if (segments.length === 0) return null;

  let targetIdx = -1;
  let targetParts = [];

  for (let i = segments.length - 1; i >= 0; i--) {
    const seg = segments[i];
    const parts = seg.split(/[_-]/).map(p => p.trim()).filter(p => p.length > 0);
    if (parts.length >= 3) {
      targetIdx = i;
      targetParts = parts;
      break;
    }
  }

  if (targetIdx === -1) return null;

  const phase = targetParts[0];
  const sector = targetParts[1];
  const plot = targetParts.slice(2).join('/');

  if (!phase || !sector || !plot) return null;

  return { phase, sector, plot, extension: '' };
}

// Expose current search term so refreshDBQueue() can preserve active filters
window._renameSearch  = <?= json_encode($search) ?>;
window._renameFolderName = <?= json_encode($filterFolderName) ?>;

function isHiddenOrSystemFile(filename) {
  const lower = filename.toLowerCase();
  return filename.startsWith('.') || 
         filename.startsWith('._') || 
         lower === 'thumbs.db' || 
         lower === 'desktop.ini' || 
         lower === 'ehthumbs.db';
}

// ── 1. BROWSE & DIRECTORY AUTO-REFRESH ──────────────────────────────────────────
async function openBrowseDialog() {
  const activeBranch = document.getElementById('branchSelect')?.value || '';
  if (!activeBranch) {
    if (typeof showToast === 'function') {
      showToast('Please select a BRANCH before browsing a folder.', 'warning');
    } else {
      alert('Please select a BRANCH before browsing a folder.');
    }
    return;
  }

  if ('showDirectoryPicker' in window) {
    try {
      const dirHandle = await window.showDirectoryPicker({ mode: 'readwrite' });

      // Check if we already have this EXACT directory handle watched (isSameEntry API)
      // If so, refresh it instead of adding a duplicate entry.
      let existingEntry = null;
      for (const h of watchedHandles) {
        try {
          if (await h.dirHandle.isSameEntry(dirHandle)) {
            existingEntry = h;
            break;
          }
        } catch (_) { /* isSameEntry not available in all browsers */ }
      }

      let folderName;
      if (existingEntry) {
        // Re-selected the same directory — just refresh its contents
        folderName = existingEntry.folderName;
      } else {
        folderName = getUniqueFolderName(dirHandle.name);
        if (!watchedHandles.some(h => h.folderName === folderName)) {
          watchedHandles.push({ dirHandle, folderName });
          await saveFolderHandle(folderName, dirHandle);
        }
        if (!folderOrder.includes(folderName)) folderOrder.push(folderName);
      }

      // If the picked folder itself contains subfolders (rather than PDFs
      // directly), treat it as a "container" — auto-queue each subfolder
      // as its own entry instead of processing the container itself. This
      // is how multiple folders get queued in one pick instead of clicking
      // Browse repeatedly. Queue order is alphabetical by subfolder name.
      const subDirs = [];
      for await (const entry of dirHandle.values()) {
        if (entry.kind === 'directory' && !entry.name.startsWith('.')) subDirs.push(entry);
      }

      if (subDirs.length > 0) {
        subDirs.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));
        for (const subEntry of subDirs) {
          const subName = getUniqueFolderName(subEntry.name);
          if (!watchedHandles.some(h => h.folderName === subName)) {
            watchedHandles.push({ dirHandle: subEntry, folderName: subName });
            await saveFolderHandle(subName, subEntry);
          }
          if (!folderOrder.includes(subName)) folderOrder.push(subName);
          await processDirectoryHandle(subEntry, subName);
        }
        startAutoSyncWatcher();
        if (typeof showToast === 'function') {
          showToast(`Queued ${subDirs.length} folders from "${dirHandle.name}".`, 'success');
        }
        return;
      }

      await processDirectoryHandle(dirHandle, folderName);
      startAutoSyncWatcher();
      return;
    } catch (err) {
      if (err.name === 'AbortError') return;
      console.warn('showDirectoryPicker unavailable or cancelled, falling back to input:', err);
    }
  }
  document.getElementById('browseFileInput').click();
}

async function logFolderBrowseDB(folderName, filesList) {
  try {
    const fd = new FormData();
    fd.append('folder_name', folderName);
    if (filesList && filesList.length > 0) {
      fd.append('files', JSON.stringify(filesList.map(f => ({ name: f.name, size: f.size || 0, ext: f.ext }))));
    }
    const resp = await fetch('../ajax/log_browse.php', { method: 'POST', body: fd });
    return await resp.json(); // { success, folder_id, statuses: { filename: alreadyRenamedBool } }
  } catch (err) {
    console.warn('DB browse log error:', err);
    return null;
  }
}

// Drops any file from folderMap[folderName] that the DB says already has a
// saved renamed_filename (i.e. was renamed in an earlier session), so it
// doesn't reappear in the Rename queue. Returns true if anything was removed.
function applyRenameStatuses(folderName, statuses) {
  if (!statuses || !folderMap[folderName]) return false;
  const before = folderMap[folderName].length;
  folderMap[folderName] = folderMap[folderName].filter(f => {
    if (statuses[f.name] === true) {
      if (f.objectUrl) URL.revokeObjectURL(f.objectUrl);
      return false; // already renamed in the DB — skip it
    }
    return true;
  });
  return folderMap[folderName].length !== before;
}

// Stamps each browsed file with its real database document_id (log_browse.php
// creates/finds the row the moment the folder is opened, before any renaming
// happens) so autoGenerateName() can embed the permanent ID in the filename
// instead of a session-only counter.
function applyDocIds(folderName, docIds) {
  if (!docIds || !folderMap[folderName]) return;
  folderMap[folderName].forEach(f => {
    if (docIds[f.name] !== undefined) f.docId = docIds[f.name];
  });
}

// Returns the document_id of whichever document is currently loaded in the
// form — from the DB queue (renameDocId hidden field) or from a browsed
// folder file (stamped onto it by applyDocIds). Null if not yet known (e.g.
// the browse request is still in flight).
function getCurrentDocId() {
  const queueId = document.getElementById('renameDocId')?.value;
  if (queueId) return parseInt(queueId, 10);
  if (currentFolder && currentBrowsedIdx >= 0 && folderMap[currentFolder]) {
    const f = folderMap[currentFolder][currentBrowsedIdx];
    if (f && f.docId) return f.docId;
  }
  return null;
}

async function processDirectoryHandle(dirHandle, folderName) {
  let newDiscovered = false;
  // Only files newly discovered in *this* pass get sent to the server below —
  // files already known (queued, already renamed, or logged in an earlier
  // auto-sync tick) are never re-sent, since nothing about them changed.
  const newlyDiscoveredFiles = [];
  if (!folderMap[folderName]) {
    folderMap[folderName] = [];
    folderTokenCounters[folderName] = 1; // Fresh folder (or reopened after being fully renamed) — token restarts at 1
    newDiscovered = true;
  }

  for await (const entry of dirHandle.values()) {
    if (entry.kind === 'file') {
      console.log('Scanning file:', entry.name);

      if (isHiddenOrSystemFile(entry.name)) {
        console.log('Skipping hidden/system file:', entry.name);
        continue;
      }

      const ext = entry.name.split('.').pop().toLowerCase();
      const allowed = ['pdf'];
      if (!allowed.includes(ext)) {
        console.log('Skipping file (unsupported type):', entry.name);
        continue;
      }

      // Skip files that were already renamed this session
      if (renamedFilesSet.has(folderName + '::' + entry.name)) {
        console.log('Skipping file (already renamed this session):', entry.name);
        continue;
      }

      // Skip if already in list
      if (folderMap[folderName].some(f => f.name === entry.name)) {
        console.log('Skipping file (already in queue):', entry.name);
        continue;
      }

      try {
        const fileObj = await entry.getFile();
        const objectUrl = URL.createObjectURL(fileObj);
        const fileEntry = {
          name: entry.name,
          ext: ext,
          objectUrl: objectUrl,
          size: fileObj.size,
          fileObj: fileObj,
          dirHandle: dirHandle,
          fileHandle: entry
        };
        folderMap[folderName].push(fileEntry);
        newlyDiscoveredFiles.push(fileEntry);
        console.log('File successfully added to queue:', entry.name);
        newDiscovered = true;
      } catch (e) {
        console.error('Failed to get file handle:', e);
      }
    } else if (entry.kind === 'directory') {
      if (entry.name.startsWith('.')) {
        console.log('Skipping hidden directory:', entry.name);
        continue;
      }
      const subPath = `${folderName}/${entry.name}`;
      if (!folderMap[subPath]) {
        folderMap[subPath] = [];
        folderTokenCounters[subPath] = 1; // Fresh subfolder — own token, starts at 1
        newDiscovered = true;
      }
      // Recursively scan subfolders
      const subDiscovered = await processDirectoryHandle(entry, subPath);
      if (subDiscovered) newDiscovered = true;
    }
  }

  // Send only the files discovered in this pass — an auto-sync tick that
  // finds nothing new skips the network call entirely instead of re-posting
  // the whole folder's file list again.
  if (newlyDiscoveredFiles.length > 0) {
    const dbResult = await logFolderBrowseDB(folderName, newlyDiscoveredFiles);
    if (dbResult && dbResult.docIds) applyDocIds(folderName, dbResult.docIds);
    if (dbResult && dbResult.statuses) {
      const removed = applyRenameStatuses(folderName, dbResult.statuses);
      if (removed) newDiscovered = true;
    }
  }

  if (newDiscovered) {
    renderBrowsedQueue();
    updateQueueCount();
    if (!currentFolder) autoLoadNextPending();
  }
  return newDiscovered;
}

// Auto-Refresh Watcher: Rescans watched directory handles every 3 seconds
function startAutoSyncWatcher() {
  const badge = document.getElementById('autoSyncBadge');
  const btn = document.getElementById('refreshDirBtn');
  if (badge) badge.classList.remove('d-none');
  if (btn) btn.classList.remove('d-none');

  if (!autoSyncTimer) {
    autoSyncTimer = setInterval(rescanWatchedDirectories, 3000);
  }
}

async function rescanWatchedDirectories() {
  if (watchedHandles.length === 0) return;

  // 1. Verify and remove files/folders deleted on disk
  await verifyDeletedFilesAndFolders();

  // 2. Discover new files/folders created on disk
  for (const h of watchedHandles) {
    try {
      await processDirectoryHandle(h.dirHandle, h.folderName);
    } catch (e) {
      console.warn('Auto-rescan note:', e);
    }
  }
}

async function verifyDeletedFilesAndFolders() {
  let changed = false;
  const folderNames = Object.keys(folderMap);

  for (const folderName of folderNames) {
    const files = folderMap[folderName];
    if (!files) continue;

    // Check each file in folder
    for (let i = files.length - 1; i >= 0; i--) {
      const f = files[i];
      if (f.dirHandle) {
        try {
          // Attempt to check if file still exists on disk
          await f.dirHandle.getFileHandle(f.name);
        } catch (err) {
          // File or parent directory was deleted on disk!
          if (f.objectUrl) URL.revokeObjectURL(f.objectUrl);
          files.splice(i, 1);
          changed = true;

          if (folderName === currentFolder && i === currentBrowsedIdx) {
            currentBrowsedIdx = -1;
          }
        }
      }
    }

    // If folder is empty and was deleted on disk or has no files
    if (files.length === 0 && folderName.includes('/')) {
      delete folderMap[folderName];
      if (currentFolder === folderName) {
        currentFolder = null;
        currentBrowsedIdx = -1;
      }
      changed = true;
    }
  }

  if (changed) {
    renderBrowsedQueue();
    updateQueueCount();
    if (!currentFolder || currentBrowsedIdx < 0) {
      autoLoadNextPending();
    }
  }
}

async function handleBrowsedFiles(input) {
  const files   = Array.from(input.files);
  const allowed = ['pdf'];

  files.forEach(file => {
    console.log('Scanning file:', file.name);

    if (isHiddenOrSystemFile(file.name)) {
      console.log('Skipping hidden/system file:', file.name);
      return;
    }

    // Also check if any parent directory of the file starts with a dot (hidden directory)
    const rel = file.webkitRelativePath || '';
    const parts = rel.split('/');
    const hasHiddenDir = parts.some((part, idx) => idx < parts.length - 1 && part.startsWith('.'));
    if (hasHiddenDir) {
      console.log('Skipping file inside hidden directory:', file.name);
      return;
    }

    const ext = file.name.split('.').pop().toLowerCase();
    if (!allowed.includes(ext)) {
      console.log('Skipping file (unsupported type):', file.name);
      return;
    }

    const folder = parts.length > 1 ? parts[parts.length - 2] : 'Browsed Files';

    if (!folderMap[folder]) {
      folderMap[folder] = [];
      if (!folderOrder.includes(folder)) folderOrder.push(folder);
    }
    if (folderMap[folder].some(f => f.name === file.name)) {
      console.log('Skipping file (already in queue):', file.name);
      return;
    }

    folderMap[folder].push({
      name: file.name, ext,
      objectUrl: URL.createObjectURL(file),
      size: file.size,
      fileObj: file
    });
    console.log('File successfully added to queue:', file.name);
  });

  // Log populated folders with their file lists to DB, then drop any file
  // the DB says is already renamed so it doesn't show up in the queue.
  await Promise.all(Object.keys(folderMap).map(async folder => {
    if (folderMap[folder] && folderMap[folder].length > 0) {
      const dbResult = await logFolderBrowseDB(folder, folderMap[folder]);
      if (dbResult && dbResult.docIds) applyDocIds(folder, dbResult.docIds);
      if (dbResult && dbResult.statuses) applyRenameStatuses(folder, dbResult.statuses);
    }
  }));

  input.value = '';
  renderBrowsedQueue();
  updateQueueCount();
  if (!currentFolder) autoLoadNextPending();
}

// ── 2. RENDER BROWSED FOLDERS IN QUEUE ─────────────────────────
function renderBrowsedQueue() {
  const section = document.getElementById('browsedQueueSection');
  const clearBtn = document.getElementById('clearBrowsedBtn');
  if (!section) return;

  // Order top-level folders by selection order (folderOrder); subfolders
  // stay grouped right after whichever top folder they belong to.
  const topOrder = folderOrder.filter(f => folderMap[f]);
  const folders = Object.keys(folderMap).sort((a, b) => {
    const ia = topOrder.indexOf(a.split('/')[0]);
    const ib = topOrder.indexOf(b.split('/')[0]);
    if (ia !== ib) return ia - ib;
    return a.localeCompare(b);
  });
  if (folders.length === 0) {
    section.innerHTML = '';
    if (clearBtn) clearBtn.classList.add('d-none');
    syncDeduplicateDBQueue();
    updateFinishRenameVisibility();
    return;
  }
  if (clearBtn) clearBtn.classList.remove('d-none');

  // Show the processing order explicitly so it's clear which folder is
  // active and what's queued after it.
  const queueBadge = document.getElementById('folderQueueOrder');
  if (queueBadge) {
    if (topOrder.length > 1) {
      queueBadge.classList.remove('d-none');
      queueBadge.innerHTML = 'Processing order: ' + topOrder.map((f, i) =>
        `<span class="${f === currentFolder ? 'fw-bold text-primary' : ''}">${i + 1}. ${safeText(f)}</span>`
      ).join(' → ');
    } else {
      queueBadge.classList.add('d-none');
    }
  }

  let html = '';
  folders.forEach(folder => {
    const files = folderMap[folder] || [];
    if (files.length === 0) return; // Skip rendering empty folders

    const isSub = folder.includes('/');
    const folderIcon = isSub ? 'fa-folder-tree text-primary' : 'fa-folder-open text-warning';

    // Render subfolder header with file items
    html += `
        <div class="folder-group-header" style="${isSub ? 'padding-left: 20px;' : ''}">
          <i class="fas ${folderIcon}" style="font-size:0.85rem;"></i>
          <span class="text-truncate">${safeText(folder)}</span>
          <span class="folder-file-count ms-auto">${files.length} pending</span>
        </div>`;

      files.forEach((f, i) => {
        const isPdf    = f.ext === 'pdf';
        const icon     = isPdf ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
        const isActive = (folder === currentFolder && i === currentBrowsedIdx);
        html += `
          <div class="queue-item${isActive ? ' active' : ''}"
               style="${isSub ? 'padding-left: 28px;' : ''}"
               data-folder="${encAttr(folder)}"
               data-bidx="${i}"
               onclick="selectBrowsedFile('${encJs(folder)}',${i})">
            <i class="fas ${icon}"></i>
            <span class="text-truncate" style="max-width:190px;">${safeText(f.name)}</span>
            <span class="ms-auto pending-dot"></span>
          </div>`;
      });
  });

  section.innerHTML = html;
  // Hide any DB-queue duplicates now that the browsed section has been updated
  syncDeduplicateDBQueue();
  updateFinishRenameVisibility();
}

function safeText(s)  { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function encAttr(s)   { return s.replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function encJs(s)     { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }

function getUniqueFolderName(name) {
  // If this folder name is already tracked in folderMap, return it as-is so
  // processDirectoryHandle refreshes it rather than creating a duplicate key.
  if (name in folderMap) return name;
  // If a DIFFERENT directory happens to share the same display name, suffix it.
  let uniqueName = name;
  let counter = 1;
  while (uniqueName in folderMap) {
    uniqueName = `${name} (${counter})`;
    counter++;
  }
  return uniqueName;
}

function updateQueueCount() {
  const el = document.getElementById('queueTotalCount');
  if (!el) return;
  const b = Object.values(folderMap).reduce((n, arr) => n + arr.length, 0);
  const dbSection = document.getElementById('dbQueueSection');
  // Count only DB items that are currently visible (not hidden by dedup)
  const d = dbSection
    ? dbSection.querySelectorAll('.queue-item:not([data-hidden-dedup])').length
    : 0;
  el.textContent = b + d;
}

// ── 2b. REFRESH DB QUEUE FROM SERVER ─────────────────────────
async function refreshDBQueue() {
  try {
    const url = 'rename.php?get_db_queue=1' +
                (window._renameFolderName ? '&folder_name=' + encodeURIComponent(window._renameFolderName) : '') +
                (window._renameSearch ? '&search=' + encodeURIComponent(window._renameSearch) : '');
    const res  = await fetch(url);
    const html = await res.text();
    const section = document.getElementById('dbQueueSection');
    if (section) section.innerHTML = html;
  } catch (e) {
    console.warn('refreshDBQueue failed:', e);
  }
  syncDeduplicateDBQueue();
}

// ── 2c. HIDE DB QUEUE ITEMS ALREADY IN BROWSED SECTION ───────
// Prevents the same document appearing twice: once under a browsed folder
// header and again as a standalone DB record. Called whenever either section
// changes. Uses data-hidden-dedup so the element stays in the DOM (keeps its
// click handler intact) but is invisible and excluded from the count.
function syncDeduplicateDBQueue() {
  // Build a Set of 'folderName::rawFilename' pairs from the in-memory browsed queue
  const browsedKeys = new Set();
  for (const [folder, files] of Object.entries(folderMap)) {
    // Strip sub-path prefix to get the top-level folder name for comparison
    const topFolder = folder.split('/')[0];
    for (const f of files) {
      browsedKeys.add(topFolder + '::' + f.name);
      browsedKeys.add(folder   + '::' + f.name); // also try full sub-path
    }
  }

  document.querySelectorAll('#dbQueueSection .queue-item').forEach(el => {
    const dbFolder = (el.dataset.dbFolder || '').trim();
    const raw      = (el.dataset.raw      || '').trim();
    const isDupe   = browsedKeys.has(dbFolder + '::' + raw);
    if (isDupe) {
      el.style.display = 'none';
      el.dataset.hiddenDedup = '1';
    } else {
      el.style.display = '';
      delete el.dataset.hiddenDedup;
    }
  });

  updateQueueCount();
}

// ── 3. SELECT A BROWSED FILE ────────────────────────────────────
function parseFilenameDetails(filename) {
  const result = {
    className: '',
    ph: '',
    sec: '',
    plot: '',
    fileName: '',
    pageNo: '',
    pageCount: '',
    date: ''
  };

  if (!filename) return result;

  let base = filename.replace(/\.[^.]+$/, '');
  const hasLeadingUnderscore = base.startsWith('_');
  if (hasLeadingUnderscore) {
    base = base.substring(1);
  }

  const parts = base.split('_');

  if (parts.length >= 2) {
    const potentialClass = parts[0] + '_' + parts[1];
    if (ALLOWED_CLASSES.includes(potentialClass)) {
      result.className = potentialClass;
      if (parts[2] !== undefined) result.ph = parts[2];
      if (parts[3] !== undefined) result.sec = parts[3];
      if (parts[4] !== undefined) result.plot = parts[4];
      if (parts[5] !== undefined) result.fileName = parts[5];
      // Page number and Page count not auto-detected as per user request
      if (parts[8] !== undefined) result.date = parts.slice(8).join('_');
      return result;
    }
  }

  if (hasLeadingUnderscore && parts.length >= 8) {
    result.className = parts[0] + '_' + parts[1];
    result.ph = parts[2];
    result.sec = parts[3];
    result.plot = parts[4];
    result.fileName = parts[5];
    // Page number and Page count not auto-detected as per user request
    result.date = parts.slice(8).join('_');
    return result;
  }

  // Couldn't confidently parse a structured filename. Guessing wrong here is
  // worse than leaving it blank — dumping the whole raw name into File Name
  // corrupts it further on every future rename (each one nests the last).
  // Only auto-fill for genuinely plain names with no underscores; anything
  // more complex is left blank for the user to type manually.
  if (!base.includes('_')) {
    result.fileName = base;
  }
  return result;
}

function setActiveTabByClass(className) {
  let cat = 'Transfer';
  if (className.startsWith('BC_')) {
    cat = 'Building_Control';
  } else if (className.startsWith('ACQN_')) {
    cat = 'Land_Acquisition';
  }
  document.querySelectorAll('.workflow-tab').forEach(t => t.classList.remove('active'));
  const tabIds = { Transfer: 'tabTransfer', Building_Control: 'tabBuilding', Land_Acquisition: 'tabLand' };
  const tabId = tabIds[cat];
  if (tabId) {
    document.getElementById(tabId).classList.add('active');
  }
  applyTabFiltering();
}

function selectBrowsedFile(folder, idx) {
  if (!folderMap[folder] || !folderMap[folder][idx]) return;
  const f = folderMap[folder][idx];

  currentFolder     = folder;
  currentBrowsedIdx = idx;

  document.querySelectorAll('.queue-item').forEach(el => el.classList.remove('active'));
  const row = document.querySelector(`.queue-item[data-folder="${encAttr(folder)}"][data-bidx="${idx}"]`);
  if (row) { row.classList.add('active'); row.scrollIntoView({ behavior:'smooth', block:'nearest' }); }

  document.getElementById('currentNameBadge').textContent = f.name;
  document.getElementById('renameDocId').value         = '';
  document.getElementById('newNameInput').value        = f.name;

  // Clear/Reset fields so they don't carry over from previously selected files
  document.getElementById('inputPH').value = '';
  document.getElementById('inputSEC').value = '';
  document.getElementById('inputPlot').value = '';
  if (document.getElementById('inputExt')) document.getElementById('inputExt').value = '';
  document.getElementById('inputClass').value = '';
  document.getElementById('inputFileName').value = '';
  document.getElementById('inputPageNo').value = '';
  document.getElementById('inputPageCount').value = '';
  document.getElementById('inputDate').value = todayDDMMYYYY();

  // Parse filename format if present
  const parsed = parseFilenameDetails(f.name);

  // CLASS NAME always defaults to "not chosen yet" here (already cleared
  // above) rather than auto-filling from whatever the filename happens to
  // parse to — the validator should consciously pick it every time a file
  // loads, whether that's from Browse or from advancing after a Rename.
  const currentCat = getActiveCategory() || '';
  setTab(currentCat, false);

  // Auto-fill PH/SEC/PLOT/EXT from folder name (PHASE_SECTOR_PLOTNO_EXT format)
  const folderParsed = parseFolderName(folder);
  if (folderParsed) {
    document.getElementById('inputPH').value = folderParsed.phase;
    document.getElementById('inputSEC').value = folderParsed.sector;
    document.getElementById('inputPlot').value = folderParsed.plot;
    if (document.getElementById('inputExt')) document.getElementById('inputExt').value = folderParsed.extension || '';
  } else {
    document.getElementById('inputPH').value = '';
    document.getElementById('inputSEC').value = '';
    document.getElementById('inputPlot').value = '';
    if (document.getElementById('inputExt')) document.getElementById('inputExt').value = '';
    if (typeof showToast === 'function') {
      showToast('Warning: Folder name does not match expected PHASE_SECTOR_PLOTNO format.', 'warning');
    }
  }
  document.getElementById('inputFileName').value = parsed.fileName;
  document.getElementById('inputPageNo').value = parsed.pageNo;
  document.getElementById('inputPageCount').value = parsed.pageCount;

  if (parsed.date) {
    const dParts = parsed.date.split(/[\/\-]/); // dParts = [Day, Month, Year]
    if (dParts.length === 3) {
      document.getElementById('inputDate').value = `${dParts[0]}-${dParts[1]}-${dParts[2]}`;
    }
  }

  validateClassName();

  const area  = document.getElementById('docPreviewArea');
  const zoom  = document.querySelector('.zoom-controls-overlay');
  currentZoom = 100;
  document.getElementById('zoomPercent').textContent = '100%';
  document.getElementById('previewTitle').textContent = '\uD83D\uDCC4 ' + f.name;

  if (f.ext === 'pdf') {
    area.innerHTML = `<iframe src="${f.objectUrl}" width="100%" height="100%" style="border:none;border-radius:8px;"></iframe>`;
    zoom.style.display = 'none';
  } else {
    area.innerHTML = `<div class="img-preview-container" style="width:100%;height:100%;overflow:auto;">
      <img id="previewImage" src="${f.objectUrl}" alt="" style="max-width:100%;max-height:100%;object-fit:contain;transition:transform .2s;">
    </div>`;
    zoom.style.display = 'flex';
  }
  autoGenerateName();
}

// ── 4. RENAME ON REAL DISK DIRECTORY → REMOVE FROM QUEUE → AUTO-ADVANCE ──
async function renameBrowsedFile() {
  if (!currentFolder || currentBrowsedIdx < 0) return;
  const files = folderMap[currentFolder];
  if (!files || !files[currentBrowsedIdx]) return;

  const f = files[currentBrowsedIdx];
  const oldName = f.name;
  let newName = document.getElementById('newNameInput').value.trim();
  if (!newName) { alert('Please enter a new filename.'); return; }

  // Ensure correct extension
  if (!newName.toLowerCase().endsWith('.' + f.ext)) {
    newName = newName + '.' + f.ext;
  }

  // ▼ PHYSICAL DISK RENAME in main system directory
  if (f.dirHandle) {
    try {
      if (f.fileHandle && typeof f.fileHandle.move === 'function') {
        // Direct move/rename in directory handle
        await f.fileHandle.move(newName);
      } else {
        // Fallback: Create file with new name, write contents, remove old file handle
        const newFileHandle = await f.dirHandle.getFileHandle(newName, { create: true });
        const writable = await newFileHandle.createWritable();
        await writable.write(f.fileObj);
        await writable.close();
        await f.dirHandle.removeEntry(oldName);
      }
    } catch (diskErr) {
      console.warn('Physical disk rename note:', diskErr);
    }
  }

  // Log rename action + upload file to server so it's accessible for preview
  try {
    // Try to re-read file from disk after rename for freshest content
    let fileBlob = f.fileObj;
    try {
      if (f.dirHandle) {
        const newHandle = await f.dirHandle.getFileHandle(newName, { create: false });
        fileBlob = await newHandle.getFile();
      }
    } catch (e) { /* fall back to original fileObj */ }

    const fd = new FormData();
    fd.append('folder_name', currentFolder);
    fd.append('raw_filename', oldName);
    fd.append('renamed_filename', newName);
    fd.append('file_type', f.ext);
    fd.append('file_size', fileBlob.size || f.size || '');
    fd.append('file_content', fileBlob, newName); // actual file bytes for server-side storage
    // Append metadata fields (Phase/Sector/Plot/Extension) so they are saved to the database
    fd.append('phase',     document.getElementById('inputPH').value.trim());
    fd.append('branch',    document.getElementById('inputSEC').value.trim());
    fd.append('plot',      document.getElementById('inputPlot').value.trim());
    fd.append('extension', document.getElementById('inputExt')?.value.trim() || '');
    
    // Construct and append rename_meta JSON snapshot
    const renameMeta = {
      // Which BRANCH tab (Transfer / Building_Control / Land_Acquisition) was
      // active for this file. This is the ONLY place that branch selection is
      // recorded — without it, Validate falls back to guessing from Class
      // Name (which Land Acquisition doesn't even have) and mis-sorts the
      // document into the Transfer tab. Must be captured per-file, at the
      // moment each file is renamed, so batches mixing branches don't bleed
      // into each other.
      vCategory:  document.getElementById('inputWorkflowCategory')?.value.trim() || '',
      vClass:     document.getElementById('inputClass')?.value.trim() || '',
      vPH:        document.getElementById('inputPH')?.value.trim() || '',
      vSEC:       document.getElementById('inputSEC')?.value.trim() || '',
      vPlot:      document.getElementById('inputPlot')?.value.trim() || '',
      vExt:       document.getElementById('inputExt')?.value.trim() || '',
      vFileName:  document.getElementById('inputFileName')?.value.trim() || '',
      vPageNo:    document.getElementById('inputPageNo')?.value.trim() || '',
      vPageCount: document.getElementById('inputPageCount')?.value.trim() || '',
      vDate:      document.getElementById('inputDate')?.value.trim() || ''
    };
    fd.append('rename_meta', JSON.stringify(renameMeta));

    const logRes  = await fetch('../ajax/log_rename.php', { method: 'POST', body: fd });
    const logData = await logRes.json().catch(() => null);
    if (logData && logData.document_id) {
      sessionRenamedDocIds.add(logData.document_id); // Track for Verification Page filter
    } else {
      // Server rejected the upload (e.g. uploads/ permissions) or returned
      // something unexpected. The file still gets renamed on disk and
      // removed from this queue below, but it will NEVER show up in
      // Validate — that page only lists documents whose file actually made
      // it to the server. Surface this loudly instead of only logging it,
      // so it doesn't look like a silent, unexplained gap later.
      const reason = (logData && logData.message) ? logData.message : 'no response from server';
      showToast(`"${newName}" renamed locally, but failed to save to the server (${reason}). It will not appear in Validate.`, 'error', 10000);
      console.error('log_rename.php failed:', reason, logData);
    }
  } catch (dbErr) {
    showToast(`"${newName}" renamed locally, but failed to save to the server. It will not appear in Validate.`, 'error', 10000);
    console.warn('Failed to log rename to DB:', dbErr);
  }

  // Increment session Document Number after each successful rename
  sessionDocNumber++;
  const docNoEl = document.getElementById('inputDocNo');
  if (docNoEl) docNoEl.textContent = sessionDocNumber;

  // Increment this folder's own rename token — separate from sessionDocNumber
  // above, and separate from every other folder's count.
  folderTokenCounters[currentFolder] = (folderTokenCounters[currentFolder] || 1) + 1;

  // Remember both old AND new names so auto-sync never re-adds either
  renamedFilesSet.add(currentFolder + '::' + oldName);
  renamedFilesSet.add(currentFolder + '::' + newName);

  // Revoke blob URL
  if (f.objectUrl) URL.revokeObjectURL(f.objectUrl);

  // Remove from queue
  files.splice(currentBrowsedIdx, 1);

  if (files.length === 0) {
    delete folderMap[currentFolder];
    const orderIdx = folderOrder.indexOf(currentFolder);
    if (orderIdx >= 0) folderOrder.splice(orderIdx, 1);
    const idx = watchedHandles.findIndex(h => h.folderName === currentFolder);
    if (idx >= 0) {
      watchedHandles.splice(idx, 1);
      deleteFolderHandle(currentFolder); // Delete from IndexedDB since all files are renamed
    }
    currentFolder = null;
  }
  currentBrowsedIdx = -1;

  renderBrowsedQueue();
  // Also refresh DB section from server so its state stays in sync
  refreshDBQueue();
  autoLoadNextPending();

  // Loop keyboard focus back after Rename runs, so a keyboard user pressing
  // Enter repeatedly cycles straight back through the editable form fields
  // for the next document instead of staying parked on the button — no
  // longer routes back through the BRANCH dropdown first.
  focusFirstEditableField();
}

function autoLoadNextPending() {
  // Same folder first (spliced item — next slides up to same index)
  if (currentFolder && folderMap[currentFolder] && folderMap[currentFolder].length > 0) {
    const idx = Math.min(currentBrowsedIdx >= 0 ? currentBrowsedIdx : 0,
                         folderMap[currentFolder].length - 1);
    selectBrowsedFile(currentFolder, Math.max(0, idx));
    return;
  }
  // Any other folder — strictly in the order folders were selected
  const folders = folderOrder.filter(f => folderMap[f] && folderMap[f].length > 0);
  if (folders.length > 0) { selectBrowsedFile(folders[0], 0); return; }

  // No browsed files left — fall through to any remaining DB queue item
  // instead of stopping, so the New Name preview keeps auto-generating for
  // whatever's next rather than sitting on a "done" message while documents
  // are still waiting below.
  currentFolder = null; currentBrowsedIdx = -1;
  const nextDbItem = document.querySelector('#dbQueueSection .queue-item:not([data-folder])');
  if (nextDbItem) {
    selectDocument(nextDbItem);
    nextDbItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return;
  }

  // Truly nothing left anywhere
  document.getElementById('docPreviewArea').innerHTML = `
    <div class="text-center py-5">
      <i class="fas fa-check-circle fa-4x mb-3" style="color:#22c55e;"></i>
      <p style="font-weight:700;color:#15803d;">All browsed files have been renamed!</p>
    </div>`;
  document.querySelector('.zoom-controls-overlay').style.display = 'none';
  document.getElementById('newNameInput').value = ''; // nothing left to generate a name for
}

function clearAllBrowsed() {
  if (autoSyncTimer) { clearInterval(autoSyncTimer); autoSyncTimer = null; }
  watchedHandles = [];
  Object.values(folderMap).flat().forEach(f => { if (f.objectUrl) URL.revokeObjectURL(f.objectUrl); });
  folderMap = {}; folderOrder = []; currentFolder = null; currentBrowsedIdx = -1;
  renamedFilesSet.clear(); // Reset so next Browse shows all files fresh
  clearFolderHandles(); // Clear IndexedDB handles
  renderBrowsedQueue(); updateQueueCount();
  const badge = document.getElementById('autoSyncBadge');
  const btn = document.getElementById('refreshDirBtn');
  if (badge) badge.classList.add('d-none');
  if (btn) btn.classList.add('d-none');
  document.getElementById('docPreviewArea').innerHTML = `
    <div class="text-center text-secondary py-5">
      <i class="fas fa-folder-open fa-4x mb-3 opacity-30"></i>
      <p>Browse folders to begin renaming</p>
    </div>`;
  document.querySelector('.zoom-controls-overlay').style.display = 'none';
}

// ── 5. DB DOCUMENT SELECTION ────────────────────────────────────
function selectDocument(el) {
  if (el.hasAttribute('data-folder')) { /* browsed item — handled by selectBrowsedFile */ return; }
  document.querySelectorAll('.queue-item').forEach(x => x.classList.remove('active'));
  el.classList.add('active');
  currentFolder = null; currentBrowsedIdx = -1;

  const id = el.dataset.id, raw = el.dataset.raw, renamed = el.dataset.renamed,
        branch = el.dataset.branch, doctype = el.dataset.doctype,
        fileno = el.dataset.fileno, phase = el.dataset.phase,
        plot = el.dataset.plot, year = el.dataset.year,
        path = el.dataset.path, ext = el.dataset.ext;

  document.getElementById('renameDocId').value             = id;
  document.getElementById('currentNameBadge').textContent  = renamed || raw;

  // Clear/Reset fields first so they don't carry over
  document.getElementById('inputPH').value = '';
  document.getElementById('inputSEC').value = '';
  document.getElementById('inputPlot').value = '';
  if (document.getElementById('inputExt')) document.getElementById('inputExt').value = '';
  document.getElementById('inputClass').value = '';
  document.getElementById('inputFileName').value = '';
  document.getElementById('inputPageNo').value = '';
  document.getElementById('inputPageCount').value = '';
  document.getElementById('inputDate').value = todayDDMMYYYY();

  // Try to parse raw or renamed name if database metadata columns are empty
  let parsed = { className: '', ph: '', sec: '', plot: '', fileName: '', pageNo: '', pageCount: '', date: '' };
  if (!doctype && !phase && !plot && !fileno) {
    parsed = parseFilenameDetails(renamed || raw);
  }

  // Auto-fill PH/SEC/PLOT/EXT from saved DB metadata, or parse folder name
  if (phase || branch || plot) {
    document.getElementById('inputPH').value    = phase;
    document.getElementById('inputSEC').value   = branch;
    document.getElementById('inputPlot').value  = plot;
    if (document.getElementById('inputExt')) document.getElementById('inputExt').value = el.dataset.extension || '';
  } else {
    const dbFolder = el.dataset.dbFolder || '';
    const folderParsed = parseFolderName(dbFolder);
    if (folderParsed) {
      document.getElementById('inputPH').value    = folderParsed.phase;
      document.getElementById('inputSEC').value   = folderParsed.sector;
      document.getElementById('inputPlot').value  = folderParsed.plot;
      if (document.getElementById('inputExt')) document.getElementById('inputExt').value = folderParsed.extension || '';
    } else {
      document.getElementById('inputPH').value    = parsed.ph;
      document.getElementById('inputSEC').value   = parsed.sec;
      document.getElementById('inputPlot').value  = parsed.plot;
      if (document.getElementById('inputExt')) document.getElementById('inputExt').value = parsed.ext || '';
    }
  }
  document.getElementById('inputFileName').value           = fileno || parsed.fileName;
  document.getElementById('inputPageNo').value             = parsed.pageNo;
  document.getElementById('inputPageCount').value          = parsed.pageCount;

  if (parsed.date) {
    const dParts = parsed.date.split(/[\/\-]/); // dParts = [Day, Month, Year]
    if (dParts.length === 3) {
      document.getElementById('inputDate').value = `${dParts[0]}-${dParts[1]}-${dParts[2]}`;
    }
  } else {
    const dateInput = document.getElementById('inputDate');
    if (year && year.length === 4) {
      dateInput.value = `01-01-${year}`;
    } else if (year && year.includes('-')) {
      // Legacy stored value happened to be ISO (yyyy-mm-dd) — reformat to dd-mm-yyyy.
      const yParts = year.split('-');
      dateInput.value = (yParts.length === 3) ? `${yParts[2]}-${yParts[1]}-${yParts[0]}` : todayDDMMYYYY();
    } else {
      dateInput.value = todayDDMMYYYY();
    }
  }

  // CLASS NAME always defaults to "not chosen yet" here (already cleared
  // above) rather than auto-filling from the document's saved doctype or a
  // filename parse — the validator should consciously pick it every time a
  // document loads, whether that's the initial queue load or advancing
  // after a Rename.
  const currentCat = getActiveCategory() || '';
  setTab(currentCat, false);

  document.getElementById('newNameInput').value = renamed || raw;

  validateClassName();

  const area = document.getElementById('docPreviewArea');
  const zc   = document.querySelector('.zoom-controls-overlay');
  const fp   = '../' + path;
  currentZoom = 100;
  document.getElementById('zoomPercent').textContent = '100%';

  if (ext === 'pdf' && path) {
    area.innerHTML = `<iframe src="${fp}" width="100%" height="100%" style="border:none;border-radius:8px;"></iframe>`;
    zc.style.display = 'none';
  } else if (['jpg','jpeg','png','tiff','tif'].includes(ext) && path) {
    area.innerHTML = `<div class="img-preview-container" style="width:100%;height:100%;overflow:auto;"><img id="previewImage" src="${fp}" alt="" style="max-width:100%;max-height:100%;object-fit:contain;transition:transform .2s;"></div>`;
    zc.style.display = 'flex';
  } else {
    area.innerHTML = `<div class="text-center py-5"><i class="fas fa-file fa-4x mb-3 opacity-30"></i><p>Preview not available</p></div>`;
    zc.style.display = 'none';
  }
  document.getElementById('previewTitle').textContent = 'Document Preview: ' + (renamed || raw);
  autoGenerateName();
}

// ── 6. FORM SUBMIT ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
<?php if (count($myAssignedBranches) === 1 && !$isAdmin): ?>
setTab('<?= $myAssignedBranches[0] ?>');
<?php endif; ?>
  // Keep the Browse & Add Folders button's disabled state honest even if
  // the browser restores a previously-selected BRANCH value on back/forward
  // navigation (the "disabled" attribute above only reflects a truly fresh
  // page load).
  updateRenameBrowseBtnState();

  // finishRenameRow has no default display:none anywhere (HTML or CSS) — it
  // only gets hidden once this runs, which previously never happened on
  // initial page load. Until this call, isFinishRenameVisible() below
  // incorrectly reported the Finish Rename row as visible from the very
  // start, which threw off which button the Tab trap treated as "last".
  updateFinishRenameVisibility();

  // Tab must never carry keyboard focus out of the Rename Workflow panel.
  // The old version listened for Tab directly on renameSubmitBtn /
  // finishRenameBtn — but renameSubmitBtn is DISABLED by validateClassName()
  // whenever CLASS NAME is empty, which is the normal state every time a
  // new document loads. A disabled button can never receive focus, so the
  // browser's native Tab handling silently skipped straight over it — and
  // since Finish Rename is hidden at that point too, Tab escaped the panel
  // entirely instead of being trapped. This version looks up whichever
  // element is actually the last focusable one in the panel RIGHT NOW
  // (Finish Rename if it's showing, else an enabled Rename File, else the
  // last visible/enabled field before it) and traps Tab there instead of on
  // one hardcoded node. Also traps Shift+Tab off the top of the panel
  // (BRANCH) so focus can't leak backward into the page header either.
  // (Pressing Enter still submits normally — see the form submit handler /
  // finishRename() for the loop-back after that runs.)
  const renameForm      = document.getElementById('renameForm');
  const renameSubmitBtn = document.getElementById('renameSubmitBtn');
  const finishRenameBtn = document.getElementById('finishRenameBtn');
  const finishRenameRow = document.getElementById('finishRenameRow');
  const branchSelectEl  = document.getElementById('branchSelect');
  const isFinishRenameVisible = () => !!finishRenameRow && finishRenameRow.style.display !== 'none';

  function getLastWorkflowFocusable() {
    if (isFinishRenameVisible()) return finishRenameBtn;
    if (renameSubmitBtn && !renameSubmitBtn.disabled) return renameSubmitBtn;
    if (!renameForm) return null;
    const candidates = Array.from(renameForm.querySelectorAll('input, select, textarea, button'))
      .filter(el => !el.disabled && el.tabIndex !== -1 && el.offsetParent !== null);
    return candidates.length ? candidates[candidates.length - 1] : null;
  }

  if (renameForm) {
    renameForm.addEventListener('keydown', function(e) {
      if (e.key !== 'Tab') return;
      if (!e.shiftKey) {
        if (document.activeElement === getLastWorkflowFocusable()) {
          e.preventDefault();
        }
      } else if (document.activeElement === branchSelectEl) {
        e.preventDefault();
      }
    });
  }

  // Keydown / keyboard selection logic inside custom class selection combo
  const inputClass = document.getElementById('inputClass');
  const dropdown = document.getElementById('classDropdown');

  if (inputClass && dropdown) {
    let highlightedIndex = -1;

    function getVisibleItems() {
      return Array.from(dropdown.querySelectorAll('.class-combo-item:not(.hidden):not(.tab-filtered-out)'));
    }

    function highlightItem(items, index) {
      items.forEach(item => item.classList.remove('highlighted'));
      if (index >= 0 && index < items.length) {
        items[index].classList.add('highlighted');
        items[index].scrollIntoView({ block: 'nearest' });
      }
    }

    inputClass.addEventListener('keydown', function(e) {
      const isOpen = dropdown.classList.contains('open');
      const visibleItems = getVisibleItems();

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!isOpen) {
          toggleClassDropdown();
          return;
        }
        if (visibleItems.length === 0) return;
        highlightedIndex = (highlightedIndex + 1) % visibleItems.length;
        highlightItem(visibleItems, highlightedIndex);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isOpen) return;
        if (visibleItems.length === 0) return;
        highlightedIndex = (highlightedIndex - 1 + visibleItems.length) % visibleItems.length;
        highlightItem(visibleItems, highlightedIndex);
      } else if (e.key === 'Enter') {
        if (isOpen && highlightedIndex >= 0 && highlightedIndex < visibleItems.length) {
          e.preventDefault();
          const selectedText = visibleItems[highlightedIndex].textContent.trim();
          selectClassOption(selectedText);
          highlightedIndex = -1;
        }
      } else if (e.key === 'Escape') {
        if (isOpen) {
          e.preventDefault();
          dropdown.classList.remove('open');
          document.getElementById('classArrowIcon').style.transform = 'rotate(0deg)';
          highlightedIndex = -1;
        }
      } else if (e.key === 'Tab' && !e.shiftKey) {
        // Block moving on until a class name has actually been chosen —
        // empty or a value not in ALLOWED_CLASSES both count as "not chosen
        // yet" (Shift+Tab still works normally to go backwards).
        const clsVal = inputClass.value.trim();
        if (!clsVal || !ALLOWED_CLASSES.includes(clsVal)) {
          e.preventDefault();
        }
      }
    });

    inputClass.addEventListener('input', () => {
      highlightedIndex = -1;
      dropdown.querySelectorAll('.class-combo-item').forEach(item => item.classList.remove('highlighted'));
    });
  }

  // Apply tab filtering immediately so Transfer tab shows only TFR_ classes on load
  applyTabFiltering();

  const dateInput = document.getElementById('inputDate');
  attachDateMask(dateInput);
  if (dateInput && !dateInput.value) {
    dateInput.value = todayDDMMYYYY();
  }

  // Restore saved folders from IndexedDB
  restoreSavedFolders();

  const form = document.getElementById('renameForm');
  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const docId = document.getElementById('renameDocId').value;
    if (!docId) {
      renameBrowsedFile();
      return;
    }

    const btn = form.querySelector('button[type="submit"]');
    const origText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Renaming…';
    btn.disabled = true;

    try {
      const formData = new FormData(form);
      formData.append('is_ajax', '1');

      // Construct and append rename_meta JSON snapshot
      const renameMeta = {
        // See identical comment in renameBrowsedFile() above — this is what
        // lets Validate put the document in the right BRANCH tab.
        vCategory:  document.getElementById('inputWorkflowCategory')?.value.trim() || '',
        vClass:     document.getElementById('inputClass')?.value.trim() || '',
        vPH:        document.getElementById('inputPH')?.value.trim() || '',
        vSEC:       document.getElementById('inputSEC')?.value.trim() || '',
        vPlot:      document.getElementById('inputPlot')?.value.trim() || '',
        vExt:       document.getElementById('inputExt')?.value.trim() || '',
        vFileName:  document.getElementById('inputFileName')?.value.trim() || '',
        vPageNo:    document.getElementById('inputPageNo')?.value.trim() || '',
        vPageCount: document.getElementById('inputPageCount')?.value.trim() || '',
        vDate:      document.getElementById('inputDate')?.value.trim() || ''
      };
      formData.append('rename_meta', JSON.stringify(renameMeta));

      const res  = await fetch('rename.php', { method: 'POST', body: formData });
      const data = await res.json();

      if (data.success) {
        if (typeof showToast === 'function') {
          showToast(data.message, 'success');
        }
        if (data.doc_id) {
          sessionRenamedDocIds.add(data.doc_id); // Track DB-renamed doc for Verification Page filter
        }
        // Increment Document Number after each successful DB rename
        sessionDocNumber++;
        const docNoEl = document.getElementById('inputDocNo');
        if (docNoEl) docNoEl.textContent = sessionDocNumber;

        // DB-queue items have no browsed folder to scope a token to, so they
        // share one running token instead (reset alongside sessionDocNumber
        // in finishRename()).
        dbQueueTokenCounter++;

        // Determine which DB item to load next BEFORE refreshing the DOM
        const currentItem = document.querySelector(`.queue-item[data-id="${docId}"]`);
        let nextItem = null;
        if (currentItem) {
          // Look for the next sibling queue-item (DB items only, no folder items)
          let sib = currentItem.nextElementSibling;
          while (sib) {
            if (sib.classList.contains('queue-item') && !sib.hasAttribute('data-folder')) {
              nextItem = sib;
              break;
            }
            sib = sib.nextElementSibling;
          }
          // If no next, try the previous one
          if (!nextItem) {
            sib = currentItem.previousElementSibling;
            while (sib) {
              if (sib.classList.contains('queue-item') && !sib.hasAttribute('data-folder')) {
                nextItem = sib;
                break;
              }
              sib = sib.previousElementSibling;
            }
          }
        }
        const nextDocId = nextItem ? nextItem.dataset.id : null;

        // Refresh the DB queue from server (removes stale entry, reflects DB state)
        await refreshDBQueue();

        const remainingDB     = document.querySelectorAll('#dbQueueSection .queue-item:not([data-folder])').length;
        const remainingBrowsed = Object.values(folderMap).reduce((n, arr) => n + arr.length, 0);

        if (remainingBrowsed > 0) {
          // Browsed files take priority — load the first pending one
          autoLoadNextPending();
        } else if (remainingDB > 0) {
          // Try to select the next DB item by its id (now in fresh DOM)
          const freshNext = nextDocId
            ? document.querySelector(`#dbQueueSection .queue-item[data-id="${nextDocId}"]`)
            : null;
          const fallback  = document.querySelector('#dbQueueSection .queue-item:not([data-folder])');
          const toLoad    = freshNext || fallback;
          if (toLoad) {
            selectDocument(toLoad);
            toLoad.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        } else {
          document.getElementById('docPreviewArea').innerHTML = `
            <div class="text-center py-5">
              <i class="fas fa-check-circle fa-4x mb-3" style="color:#22c55e;"></i>
              <p style="font-weight:700;color:#15803d;">All files have been renamed!</p>
            </div>`;
          const zc = document.querySelector('.zoom-controls-overlay');
          if (zc) zc.style.display = 'none';
          document.getElementById('currentNameBadge').textContent = 'None Selected';
          document.getElementById('previewTitle').textContent = 'Document Preview';
          document.getElementById('newNameInput').value = ''; // nothing left to generate a name for
        }
      } else {
        alert(data.message || 'Rename failed.');
      }
    } catch (err) {
      console.error('AJAX Rename error:', err);
      alert('Error connecting to server while renaming.');
    } finally {
      btn.innerHTML = origText;
      btn.disabled = false;
      // Loop keyboard focus back after Rename runs, so a keyboard user
      // pressing Enter repeatedly cycles straight back through the editable
      // form fields for the next document instead of staying parked down on
      // the button — no longer routes back through the BRANCH dropdown first.
      focusFirstEditableField();
    }
  });

  const dbSec = document.getElementById('dbQueueSection');
  const first = dbSec ? dbSec.querySelector('.queue-item') : null;
  if (first) {
    selectDocument(first); // also focuses CLASS NAME, since a document is now loaded
  } else {
    // Nothing auto-loaded (empty queue) — without this, the page loads with
    // nothing focused at all, so the very first Tab press falls through to
    // the top navbar/logo instead of landing inside the Rename Workflow
    // panel. Put keyboard focus on BRANCH, the panel's own starting field.
    const branchSelect = document.getElementById('branchSelect');
    if (branchSelect) branchSelect.focus();
  }

  // ── Drag & Drop Event Listeners ──
  const dropOverlay = document.getElementById('dragDropOverlay');
  let dragCounter = 0;

  window.addEventListener('dragenter', (e) => {
    e.preventDefault();
    dragCounter++;
    if (dropOverlay) {
      dropOverlay.style.display = 'flex';
      dropOverlay.classList.add('active');
    }
  });

  window.addEventListener('dragover', (e) => {
    e.preventDefault();
  });

  window.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dragCounter--;
    if (dragCounter === 0 && dropOverlay) {
      dropOverlay.style.display = 'none';
      dropOverlay.classList.remove('active');
    }
  });

  window.addEventListener('drop', async (e) => {
    e.preventDefault();
    dragCounter = 0;
    if (dropOverlay) {
      dropOverlay.style.display = 'none';
      dropOverlay.classList.remove('active');
    }

    const items = e.dataTransfer.items;
    if (items) {
      // Synchronously collect file handles
      const promises = Array.from(items).map(item => {
        if (item.kind === 'file' && typeof item.getAsFileSystemHandle === 'function') {
          return item.getAsFileSystemHandle();
        }
        return null;
      });

      let count = 0;
      for (const promise of promises) {
        if (!promise) continue;
        try {
          const handle = await promise;
          if (handle && handle.kind === 'directory') {
            // Check if this exact directory is already watched (isSameEntry)
            let existingEntry = null;
            for (const h of watchedHandles) {
              try {
                if (await h.dirHandle.isSameEntry(handle)) {
                  existingEntry = h;
                  break;
                }
              } catch (_) { /* isSameEntry not available */ }
            }
            let folderName;
            if (existingEntry) {
              folderName = existingEntry.folderName; // refresh existing
            } else {
              folderName = getUniqueFolderName(handle.name);
              if (!watchedHandles.some(h => h.folderName === folderName)) {
                watchedHandles.push({ dirHandle: handle, folderName });
                await saveFolderHandle(folderName, handle);
              }
            }
            await processDirectoryHandle(handle, folderName);
            count++;
          }
        } catch (err) {
          console.warn('Error processing dropped item:', err);
        }
      }

      if (count > 0) {
        startAutoSyncWatcher();
        if (typeof showToast === 'function') {
          showToast(`Loaded ${count} folders successfully!`, 'success');
        } else {
          alert(`Loaded ${count} folders successfully!`);
        }
      }
    }
  });

  <?php if ($message && $msgType === 'success'): ?>
    showToast('<?= addslashes($message) ?>', 'success');
  <?php endif; ?>
});

// ── 7. ZOOM ─────────────────────────────────────────────────────
function zoom(dir) {
  const img = document.getElementById('previewImage'); if (!img) return;
  if (dir === 'in') currentZoom += 10;
  else if (dir === 'out') currentZoom = Math.max(10, currentZoom - 10);
  else currentZoom = 100;
  img.style.transform = `scale(${currentZoom/100})`;
  document.getElementById('zoomPercent').textContent = currentZoom + '%';
}

// ── 8. TABS ──────────────────────────────────────────────────────
function getActiveCategory() {
  if (document.getElementById('tabTransfer')   && document.getElementById('tabTransfer').classList.contains('active'))   return 'Transfer';
  if (document.getElementById('tabBuilding')   && document.getElementById('tabBuilding').classList.contains('active'))   return 'Building_Control';
  if (document.getElementById('tabLand')       && document.getElementById('tabLand').classList.contains('active'))       return 'Land_Acquisition';
  return null;
}

// Show only items matching the active tab (or marked data-tab="all"); hide the rest
function applyTabFiltering() {
  const cat = getActiveCategory();
  document.querySelectorAll('#classDropdown .class-combo-item').forEach(el => {
    const itemTab = el.getAttribute('data-tab');
    if (!cat || itemTab === 'all' || itemTab === cat) {
      el.classList.remove('tab-filtered-out');
    } else {
      el.classList.add('tab-filtered-out');
    }
  });
}

// After a Rename runs, keyboard focus used to loop back to the BRANCH
// dropdown at the very top of the form — so the next Tab press walked back
// through BRANCH, Browse, and the read-only fields before reaching anything
// editable again. This instead jumps straight to the first editable,
// visible text field in the form (in on-page order), so Tab from here on
// cycles purely between the editable text boxes for the next document.
// Falls back to BRANCH only if nothing editable is currently visible.
function focusFirstEditableField() {
  const form = document.getElementById('renameForm');
  const editableTypes = ['text', 'date', 'number'];
  const candidate = form
    ? Array.from(form.querySelectorAll('input')).find(el =>
        editableTypes.includes(el.type) && !el.readOnly && !el.disabled && el.offsetParent !== null)
    : null;
  if (candidate) {
    candidate.focus();
  } else {
    const formTop = document.getElementById('branchSelect');
    if (formTop) formTop.focus();
  }
}

// Keeps the Browse & Add Folders button disabled (with an explanatory
// tooltip) until a BRANCH is selected. Called on load and whenever BRANCH
// changes.
function updateRenameBrowseBtnState() {
  const btn    = document.getElementById('browseRenameBtn');
  const branch = document.getElementById('branchSelect')?.value || '';
  if (!btn) return;
  btn.disabled = !branch;
  btn.title = branch ? '' : 'Select a BRANCH first';
}

function setTab(cat, applyDefaultClass = true) {
  document.querySelectorAll('.workflow-tab').forEach(t => t.classList.remove('active'));
  const m = { Transfer:{tab:'tabTransfer',cls:'TFR_Acceptance'}, Building_Control:{tab:'tabBuilding',cls:'BC_Application'}, Land_Acquisition:{tab:'tabLand',cls:'ACQN_Advertisement'} };

  // Land Acquisition uses its own set of fields (Mouaza, File No, Land Owner
  // Name, Kanal, Marla, Saledeed, Scan No) — only show them for that branch.
  const landFields = document.getElementById('landExtraFields');
  if (landFields) landFields.style.display = (cat === 'Land_Acquisition') ? '' : 'none';

  // Land Acquisition keeps only PH from the standard fields — SEC, PLOT NO,
  // CLASS NAME, FILE NAME, PAGE NO, PAGE COUNT and DATE are all hidden for it.
  const isLand = (cat === 'Land_Acquisition');
  ['secField', 'plotField', 'classNameField', 'fileNameField', 'pageDateFields'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = isLand ? 'none' : '';
  });

  // PH is normally auto-filled from a PHASE_SECTOR_PLOTNO folder name, but
  // Land Acquisition folders don't reliably follow that pattern — clear any
  // stale value from a previously loaded file and let the user type it in.
  if (isLand) {
    const phInput = document.getElementById('inputPH');
    if (phInput && !phInput.value) phInput.placeholder = 'Enter Phase manually';
  }

  // Sync the branch dropdown
  const sel = document.getElementById('branchSelect');
  if (sel && sel.value !== cat) sel.value = cat;
  if (m[cat]) {
    document.getElementById(m[cat].tab).classList.add('active');
    applyTabFiltering();

    // Always record the active branch, independent of CLASS NAME — Land
    // Acquisition hides Class Name entirely, so this hidden field is what
    // the Verify page now uses to sort documents into the right tab.
    const catField = document.getElementById('inputWorkflowCategory');
    if (catField) catField.value = cat;

    // applyDefaultClass=false keeps CLASS NAME empty (e.g. when loading a
    // newly selected/browsed document) instead of pre-filling it with this
    // tab's placeholder class — the validator must consciously pick one.
    if (applyDefaultClass) {
      document.getElementById('inputClass').value = m[cat].cls;
    }
    onClassChange();
  } else {
    applyTabFiltering();
    autoGenerateName();
  }
}

// ── 8.5. CLASS NAME CHANGE — Strip prefix before underscore & Validate allowed list ──
// ALLOWED_CLASSES itself now lives in assets/js/allowed_classes.js (loaded
// above), shared with pages/validate.php's Escape-to-autocomplete.

function validateClassName() {
  const clsInput = document.getElementById('inputClass');
  const clsVal   = clsInput.value.trim();
  const btn      = document.getElementById('renameSubmitBtn');
  const errSpan  = document.getElementById('classError');

  // If empty or in allowed list => valid
  const isValid = (clsVal === '') || ALLOWED_CLASSES.includes(clsVal);

  if (!isValid) {
    if (btn) {
      btn.disabled = true;
      btn.style.opacity = '0.5';
      btn.style.cursor = 'not-allowed';
    }
    if (errSpan) errSpan.style.display = 'block';
    clsInput.style.borderColor = '#dc2626';
    return false;
  } else {
    if (btn) {
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.style.cursor = 'pointer';
    }
    if (errSpan) errSpan.style.display = 'none';
    clsInput.style.borderColor = '';
    return true;
  }
}

function onClassChange() {
  const clsVal = document.getElementById('inputClass').value.trim();
  
  validateClassName();

  if (clsVal && clsVal.includes('_')) {
    const afterUnderscore = clsVal.split('_').slice(1).join('_');
    if (afterUnderscore) {
      document.getElementById('inputFileName').value = afterUnderscore;
    }
  }
  autoGenerateName();
}

// ── Custom Combo-Box: toggle / filter / select / outside-click ──
function toggleClassDropdown() {
  const dd = document.getElementById('classDropdown');
  const icon = document.getElementById('classArrowIcon');
  const isOpen = dd.classList.contains('open');
  if (isOpen) {
    dd.classList.remove('open');
    icon.style.transform = 'rotate(0deg)';
  } else {
    // Reset search filter, then apply active-tab filter
    document.querySelectorAll('#classDropdown .class-combo-item').forEach(el => el.classList.remove('hidden'));
    applyTabFiltering();
    dd.classList.add('open');
    icon.style.transform = 'rotate(180deg)';
    document.getElementById('inputClass').focus();
  }
}

function filterClassOptions() {
  const query = document.getElementById('inputClass').value.toLowerCase();
  const dd = document.getElementById('classDropdown');
  const items = dd.querySelectorAll('.class-combo-item');
  let anyVisible = false;
  items.forEach(el => {
    // Respect tab filtering — never un-hide tab-filtered-out items during search
    if (el.classList.contains('tab-filtered-out')) return;
    if (el.textContent.toLowerCase().includes(query)) {
      el.classList.remove('hidden');
      anyVisible = true;
    } else {
      el.classList.add('hidden');
    }
  });
  
  validateClassName();

  // Open dropdown if there's something to show
  if (anyVisible && query.length > 0) {
    dd.classList.add('open');
    document.getElementById('classArrowIcon').style.transform = 'rotate(180deg)';
  }
}

function selectClassOption(value) {
  document.getElementById('inputClass').value = value;
  const dd = document.getElementById('classDropdown');
  dd.classList.remove('open');
  document.getElementById('classArrowIcon').style.transform = 'rotate(0deg)';
  onClassChange();
}

// ── Escape-to-autocomplete: pressing Escape while typing a Class Name
// snaps whatever's typed to the closest valid option instead of leaving a
// typo/partial entry behind. Prefers whatever the combo-box's search/tab
// filter is currently showing, so the match stays scoped to the active
// branch; falls back to the full ALLOWED_CLASSES list if nothing is
// currently visible (e.g. the typed text didn't substring-match anything).
document.getElementById('inputClass').addEventListener('keydown', function(e) {
  if (e.key !== 'Escape') return;
  e.preventDefault();

  const typed = this.value.trim();
  const dd = document.getElementById('classDropdown');

  if (!typed) {
    dd.classList.remove('open');
    document.getElementById('classArrowIcon').style.transform = 'rotate(0deg)';
    this.blur();
    return;
  }

  let candidates = Array.from(dd.querySelectorAll('.class-combo-item'))
    .filter(el => !el.classList.contains('hidden') && !el.classList.contains('tab-filtered-out'))
    .map(el => el.textContent.trim());
  if (candidates.length === 0) {
    // Nothing visible under the current filter — widen to the active
    // branch's full set before falling back to every class.
    candidates = Array.from(dd.querySelectorAll('.class-combo-item'))
      .filter(el => !el.classList.contains('tab-filtered-out'))
      .map(el => el.textContent.trim());
  }

  const match = findClosestClassName(typed, candidates);
  if (match) selectClassOption(match);
  this.blur();
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
  const wrap = document.querySelector('.class-combo-wrap');
  const dd = document.getElementById('classDropdown');
  const btn = document.querySelector('.class-combo-arrow');
  if (!wrap || !dd) return;
  if (!wrap.contains(e.target) && !dd.contains(e.target) && !btn.contains(e.target)) {
    dd.classList.remove('open');
    const icon = document.getElementById('classArrowIcon');
    if (icon) icon.style.transform = 'rotate(0deg)';
  }
});

// ── Date mask (dd-mm-yyyy) — shared by inputDate here and vDate in validate.php ──
// Typed digits only; auto-inserts "-" separators. DD clamps to 01-31, MM
// clamps to 01-12 as soon as their 2 digits are entered. YYYY always shows
// the most recently typed 4 digits — typing a 5th digit drops the oldest
// one instead of growing past 4 (a "rolling window").
function attachDateMask(input) {
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
  input.addEventListener('input', () => { reformat(); autoGenerateName(); });
  input.addEventListener('blur', reformat);
}

// Builds a dd-mm-yyyy string from a Date object (defaults to today).
function todayDDMMYYYY() {
  const d = new Date();
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}-${mm}-${d.getFullYear()}`;
}

// ── 9. AUTO-FILENAME ──
// Default:            Classname_Phase_Sector_PlotNo_Filename_DocID_Day_Month_Year_PageNo_PageCount_.ext
// Land Acquisition:    Phase_Mouaza_FileNo_LandOwnerName_Kanal_Marla_Saledeed_ScanNo_.PDF
function autoGenerateName() {
  // ── Land Acquisition branch: its own token format ──
  if (getActiveCategory() === 'Land_Acquisition') {
    const ph              = document.getElementById('inputPH').value.trim();
    const mouaza           = document.getElementById('inputMouaza')?.value.trim() || '';
    const landFileNo       = document.getElementById('inputLandFileNo')?.value.trim() || '';
    const landOwnerName    = document.getElementById('inputLandOwnerName')?.value.trim() || '';
    const kanal             = document.getElementById('inputKanal')?.value.trim() || '';
    const marla             = document.getElementById('inputMarla')?.value.trim() || '';
    const squareFoot        = document.getElementById('inputSquareFoot')?.value.trim() || '';
    const saledeed          = document.getElementById('inputSaledeed')?.value.trim() || '';
    const litigationNo      = document.getElementById('inputLitigationNo')?.value.trim() || '';
    const scanNo            = document.getElementById('inputScanNo')?.value.trim() || '';

    // Token order: FileNo_Mouaza_OwnerName_Phase_Kanal_Marla_SquareFoot_SaleDeedNo_LiticationNo_ScanNo_
    const landParts = [];
    if (landFileNo)     landParts.push(landFileNo);
    if (mouaza)         landParts.push(mouaza);
    if (landOwnerName)  landParts.push(landOwnerName);
    if (ph)             landParts.push(ph);
    if (kanal)          landParts.push(kanal);
    if (marla)          landParts.push(marla);
    if (squareFoot)     landParts.push(squareFoot);
    if (saledeed)       landParts.push(saledeed);
    if (litigationNo)   landParts.push(litigationNo);
    if (scanNo)         landParts.push(scanNo);

    if (landParts.length > 0) {
      // Trailing underscore before extension, uppercase .PDF as required
      document.getElementById('newNameInput').value = landParts.join('_') + '_.PDF';
    }
    return;
  }

  // ── Transfer / Building Control: unchanged default format ──
  const cls       = document.getElementById('inputClass').value.trim();
  const ph        = document.getElementById('inputPH').value.trim();
  const sec       = document.getElementById('inputSEC').value.trim();
  const plt       = document.getElementById('inputPlot').value.trim();
  const extVal    = document.getElementById('inputExt')?.value.trim() || '';
  const fn        = document.getElementById('inputFileName').value.trim();
  const pageNo    = document.getElementById('inputPageNo').value.trim();
  const pageCount = document.getElementById('inputPageCount').value.trim();
  const rawDt     = document.getElementById('inputDate').value.trim();

  // Extract DD-MM-YYYY date format string
  let dateToken = '';
  if (rawDt) {
    const dParts = rawDt.split(/[\/\-]/);
    if (dParts.length === 3) {
      dateToken = `${dParts[0]}-${dParts[1]}-${dParts[2]}`;
    }
  }

  let ext = 'pdf';
  const aq = document.querySelector('.queue-item.active');
  if (aq && aq.dataset.ext) ext = aq.dataset.ext;
  else if (currentFolder && currentBrowsedIdx >= 0 && folderMap[currentFolder])
    ext = folderMap[currentFolder][currentBrowsedIdx]?.ext || 'pdf';

  // 5-digit rename token — NOT the document's real database ID. For a
  // browsed folder file this is that folder's own counter (see
  // folderTokenCounters, initialized in processDirectoryHandle()): it starts
  // at 1 the moment the folder is opened and goes up by 1 each time a file
  // from that same folder gets renamed, then starts over at 1 again the next
  // time a (new or reopened) folder is opened. A DB queue item isn't tied to
  // a browsed folder, so it uses the shared dbQueueTokenCounter instead.
  const tokenNum = (currentFolder && folderTokenCounters[currentFolder] !== undefined)
    ? folderTokenCounters[currentFolder]
    : dbQueueTokenCounter;
  const docIdStr = String(tokenNum);

  // Update DOC NO display field with current session counter
  const docNoEl = document.getElementById('inputDocNo');
  if (docNoEl) docNoEl.value = sessionDocNumber;

  let fullPlot = plt.replace(/[\/\\]/g, '-');

  // Build filename parts in exact required order:
  // Classname_Phase_Sector_PlotNo_Filename_DocID_DD-MM-YYYY_PageNo_PageCount_.ext
  const parts = [];
  if (cls)       parts.push(cls);
  if (ph)        parts.push(ph);
  if (sec)       parts.push(sec);
  if (fullPlot)  parts.push(fullPlot);
  if (fn)        parts.push(fn);
  if (docIdStr)  parts.push(docIdStr); // Per-folder (or DB-queue) rename token, 5-digit zero-padded
  if (dateToken) parts.push(dateToken); // DD-MM-YYYY date format e.g. 22-03-2026
  if (pageNo)    parts.push(pageNo);
  if (pageCount) parts.push(pageCount);

  if (parts.length > 0) {
    // Trailing underscore before extension: ...PageCount_.ext
    document.getElementById('newNameInput').value = parts.join('_') + '_.' + ext;
  }
}

// ── 10. FINISH RENAME BUTTON VISIBILITY ────────────────────────────
function updateFinishRenameVisibility() {
  const row = document.getElementById('finishRenameRow');
  if (!row) return;
  // Count all files still waiting to be renamed across every browsed folder
  const totalBrowsed = Object.values(folderMap).reduce((n, arr) => n + arr.length, 0);
  // Hide the button while browsed files are pending; show when all done (or none loaded)
  row.style.display = totalBrowsed > 0 ? 'none' : '';
}

// ── 10. LOAD NEXT / FINISH ───────────────────────────────────────
function loadNextFile() {
  if (currentFolder && folderMap[currentFolder]) {
    const files = folderMap[currentFolder];
    const ni = currentBrowsedIdx + 1;
    if (ni < files.length) { selectBrowsedFile(currentFolder, ni); return; }
    const fols = Object.keys(folderMap);
    const fi = fols.indexOf(currentFolder);
    if (fi >= 0 && fi+1 < fols.length) { selectBrowsedFile(fols[fi+1], 0); return; }
    if (files.length > 0) { selectBrowsedFile(currentFolder, 0); return; }
  }
  const dbSection = document.getElementById('dbQueueSection');
  const a = dbSection ? dbSection.querySelector('.queue-item.active') : null;
  const n = a ? a.nextElementSibling : null;
  const t = (n && n.classList.contains('queue-item'))
              ? n : (dbSection ? dbSection.querySelector('.queue-item') : null);
  if (t) { selectDocument(t); t.scrollIntoView({ behavior:'smooth', block:'nearest' }); }
}

function finishRename() {
  // Show success feedback — stay on Rename page (no redirect)
  if (typeof showToast === 'function') {
    showToast('Rename session complete! Ready for next batch.', 'success');
  } else {
    alert('Rename session complete! Ready for next batch.');
  }

  // Reset the auto-incrementing Document Number for the next session
  sessionDocNumber = 1;
  const docNoEl = document.getElementById('inputDocNo');
  if (docNoEl) docNoEl.textContent = sessionDocNumber;

  // Reset the DB-queue rename token too (per-folder tokens reset on their
  // own, the moment each folder is reopened — see processDirectoryHandle()).
  dbQueueTokenCounter = 1;

  // Clear the preview area
  const previewArea = document.getElementById('docPreviewArea');
  if (previewArea) {
    previewArea.innerHTML = `
      <div class="text-center text-secondary py-5">
        <i class="fas fa-check-circle fa-4x mb-3" style="color:#22c55e;"></i>
        <p style="font-weight:700;color:#15803d;">All done! Start a new batch or browse more folders.</p>
      </div>`;
  }
  const zc = document.querySelector('.zoom-controls-overlay');
  if (zc) zc.style.display = 'none';

  // Unlike the loop-back after each individual Rename (which jumps to CLASS
  // NAME because a document is still loaded), Finish Rename means the batch
  // is over and nothing is loaded anymore — so keyboard focus goes all the
  // way back to BRANCH, the Rename Workflow panel's own starting field, the
  // same place a fresh page load focuses. It must never fall through to the
  // page's top navbar/logo outside the panel.
  const branchSelect = document.getElementById('branchSelect');
  if (branchSelect) branchSelect.focus();
}
</script>

<?php require_once '../includes/footer.php'; ?>