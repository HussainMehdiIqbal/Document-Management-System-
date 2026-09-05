// ============================================================
// assets/js/allowed_classes.js
// Shared CLASS NAME reference list + "closest match" helper.
//
// Used by:
//   - pages/rename.php   (CLASS NAME combo-box)
//   - pages/validate.php (CLASS NAME correction field)
// so pressing Escape in either place snaps a typed value to the nearest
// valid class name instead of leaving a typo/partial entry in the field.
// Kept in one place so the two pages can never drift out of sync.
// ============================================================

const ALLOWED_CLASSES = [
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

// Classic Levenshtein (edit-distance) — how many single-character
// insertions/deletions/substitutions turn `a` into `b`. Lower = closer match.
function levenshteinDistance(a, b) {
  a = (a || '').toLowerCase();
  b = (b || '').toLowerCase();
  const m = a.length, n = b.length;
  if (m === 0) return n;
  if (n === 0) return m;
  const dp = new Array(n + 1);
  for (let j = 0; j <= n; j++) dp[j] = j;
  for (let i = 1; i <= m; i++) {
    let prev = dp[0];
    dp[0] = i;
    for (let j = 1; j <= n; j++) {
      const tmp = dp[j];
      dp[j] = (a[i - 1] === b[j - 1]) ? prev : 1 + Math.min(prev, dp[j], dp[j - 1]);
      prev = tmp;
    }
  }
  return dp[n];
}

// Finds the ALLOWED_CLASSES entry closest to `query` by edit distance.
// `candidates` lets a caller narrow the search first (e.g. to whatever the
// combo-box's search/tab filter is currently showing) — falls back to the
// full ALLOWED_CLASSES list when omitted or empty. Returns null for an
// empty query or an empty candidate pool.
function findClosestClassName(query, candidates) {
  const q = (query || '').trim();
  if (!q) return null;
  const pool = (candidates && candidates.length) ? candidates : ALLOWED_CLASSES;
  if (!pool.length) return null;
  let best = null, bestScore = Infinity;
  pool.forEach(name => {
    const dist = levenshteinDistance(q, name);
    if (dist < bestScore) { bestScore = dist; best = name; }
  });
  return best;
}
