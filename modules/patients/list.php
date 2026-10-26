<?php
// modules/patients/list.php - Unified OPD & IPD Hospital Patient Directory (MediPro Integration)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';

require_permission('pharmacy.patients.view');

$page_title = 'Patients Directory (OPD & IPD)';

// Parameters & Filters
$term          = trim($_GET['q'] ?? '');
$tab           = trim($_GET['tab'] ?? 'all'); // 'all', 'ipd_active', 'ipd_all', 'opd', 'rx'
$doctorFilter  = trim($_GET['doctor'] ?? '');
$genderFilter  = trim($_GET['gender'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 25;
$offset        = ($page - 1) * $perPage;

// Base SQL query fetching all patients from MediPro Hospital DB with IPD & OPD clinical metrics
$whereConditions = ["(p.status IS NULL OR p.status = 'Active' OR p.status = 1)", "p.deleted_at IS NULL"];
$params = [];

if ($term !== '') {
    $whereConditions[] = "(
        p.patient_code LIKE ? 
        OR p.phone LIKE ? 
        OR CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) LIKE ? 
        OR p.first_name LIKE ? 
        OR p.last_name LIKE ?
        OR p.city LIKE ?
        OR EXISTS (SELECT 1 FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.ipd_number LIKE ?)
    )";
    $like = '%' . $term . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
}

if ($genderFilter !== '') {
    $whereConditions[] = "p.gender = ?";
    $params[] = $genderFilter;
}

if ($doctorFilter !== '') {
    $whereConditions[] = "EXISTS (
        SELECT 1 FROM admissions adm JOIN doctors d ON d.doctor_id = adm.doctor_id WHERE adm.patient_id = p.patient_id AND d.name = ?
        UNION
        SELECT 1 FROM opd_visits v JOIN doctors d ON d.doctor_id = v.doctor_id WHERE v.patient_id = p.patient_id AND d.name = ?
        UNION
        SELECT 1 FROM prescriptions pr JOIN doctors d ON d.doctor_id = pr.doctor_id WHERE pr.patient_id = p.patient_id AND d.name = ?
    )";
    $params[] = $doctorFilter;
    $params[] = $doctorFilter;
    $params[] = $doctorFilter;
}

// Tab filtering
if ($tab === 'ipd_active') {
    $whereConditions[] = "EXISTS (SELECT 1 FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted')";
} elseif ($tab === 'ipd_all') {
    $whereConditions[] = "EXISTS (SELECT 1 FROM admissions adm WHERE adm.patient_id = p.patient_id)";
} elseif ($tab === 'opd') {
    $whereConditions[] = "(EXISTS (SELECT 1 FROM opd_visits v WHERE v.patient_id = p.patient_id) OR EXISTS (SELECT 1 FROM prescriptions pr WHERE pr.patient_id = p.patient_id))";
} elseif ($tab === 'rx') {
    $whereConditions[] = "EXISTS (SELECT 1 FROM prescriptions pr WHERE pr.patient_id = p.patient_id AND pr.status IN ('Active', 'Ready', 'Partially Dispensed'))";
}

$whereSql = implode(' AND ', $whereConditions);

// Hospital DB Connection for clinical registry queries
try {
    $hospPdo = \Pharmacy\Database\Database::getHospitalConnection() ?: $pdo;
} catch (Exception $e) {
    $hospPdo = $pdo;
}

// KPI Stats
$kpiTotal = (int)$hospPdo->query("SELECT COUNT(*) FROM patients WHERE (status IS NULL OR status = 'Active' OR status = 1) AND deleted_at IS NULL")->fetchColumn();
$kpiActiveIpd = (int)$hospPdo->query("SELECT COUNT(DISTINCT patient_id) FROM admissions WHERE status = 'Admitted'")->fetchColumn();
$kpiAllIpd = (int)$hospPdo->query("SELECT COUNT(DISTINCT patient_id) FROM admissions")->fetchColumn();
$kpiOpd = (int)$hospPdo->query("SELECT COUNT(DISTINCT patient_id) FROM opd_visits")->fetchColumn();
$kpiRx = (int)$hospPdo->query("SELECT COUNT(DISTINCT patient_id) FROM prescriptions WHERE status IN ('Active', 'Ready', 'Partially Dispensed')")->fetchColumn();

// Count total records for pagination
$countSql = "SELECT COUNT(*) FROM patients p WHERE {$whereSql}";
$countStmt = $hospPdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

// Main Query with comprehensive IPD, OPD and prescription subqueries
$querySql = "
    SELECT 
        p.patient_id as hospital_patient_id,
        COALESCE(p.patient_code, CONCAT('VH', p.patient_id)) as uhid,
        TRIM(CONCAT(COALESCE(p.patient_prefix, ''), ' ', COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) as name,
        COALESCE(p.phone, '') as mobile,
        COALESCE(p.gender, 'Other') as gender,
        p.dob as date_of_birth,
        p.city,
        p.blood_group,
        p.created_at,
        -- Local pharmacy patient id if linked
        (SELECT pp.id FROM pharmacy_db.pharmacy_patients pp WHERE (pp.hospital_patient_id = p.patient_id OR pp.hospital_uhid = p.patient_code COLLATE utf8mb4_unicode_ci) LIMIT 1) as pharmacy_patient_id,
        (SELECT pp.pharmacy_patient_no FROM pharmacy_db.pharmacy_patients pp WHERE (pp.hospital_patient_id = p.patient_id OR pp.hospital_uhid = p.patient_code COLLATE utf8mb4_unicode_ci) LIMIT 1) as pharmacy_patient_no,
        -- IPD details
        (SELECT COUNT(*) FROM admissions adm WHERE adm.patient_id = p.patient_id) as total_admissions,
        (SELECT adm.admission_id FROM admissions adm WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1) as latest_admission_id,
        (SELECT adm.ipd_number FROM admissions adm WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1) as latest_ipd_no,
        (SELECT adm.status FROM admissions adm WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1) as latest_ipd_status,
        (SELECT adm.admission_date FROM admissions adm WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1) as latest_admission_date,
        (SELECT adm.admission_type FROM admissions adm WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1) as latest_admission_type,
        (SELECT w.ward_name FROM admissions adm LEFT JOIN wards w ON adm.ward_id = w.ward_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as ipd_ward,
        (SELECT b.bed_number FROM admissions adm LEFT JOIN beds b ON adm.bed_id = b.bed_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as ipd_bed,
        -- OPD details
        (SELECT COUNT(*) FROM opd_visits v WHERE v.patient_id = p.patient_id) as total_opd_visits,
        (SELECT v.visit_date FROM opd_visits v WHERE v.patient_id = p.patient_id ORDER BY v.visit_id DESC LIMIT 1) as latest_opd_date,
        (SELECT v.visit_id FROM opd_visits v WHERE v.patient_id = p.patient_id ORDER BY v.visit_id DESC LIMIT 1) as latest_visit_id,
        -- Doctor Prescriptions
        (SELECT COUNT(*) FROM prescriptions pr WHERE pr.patient_id = p.patient_id) as total_prescriptions,
        (SELECT COUNT(*) FROM prescriptions pr WHERE pr.patient_id = p.patient_id AND pr.status IN ('Active', 'Ready', 'Partially Dispensed')) as pending_prescriptions,
        -- Doctor Name
        COALESCE(
            (SELECT d.name FROM admissions adm JOIN doctors d ON d.doctor_id = adm.doctor_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1),
            (SELECT d.name FROM opd_visits v JOIN doctors d ON d.doctor_id = v.doctor_id WHERE v.patient_id = p.patient_id ORDER BY v.visit_id DESC LIMIT 1),
            (SELECT d.name FROM prescriptions pr JOIN doctors d ON d.doctor_id = pr.doctor_id WHERE pr.patient_id = p.patient_id ORDER BY pr.prescription_id DESC LIMIT 1),
            (SELECT d.name FROM admissions adm JOIN doctors d ON d.doctor_id = adm.doctor_id WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1)
        ) as doctor_name
    FROM patients p
    WHERE {$whereSql}
    ORDER BY 
        CASE WHEN EXISTS (SELECT 1 FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted') THEN 1 ELSE 2 END ASC,
        p.patient_id DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $hospPdo->prepare($querySql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distinct Doctors for filter dropdown
$doctorsList = $hospPdo->query("SELECT DISTINCT name FROM doctors WHERE status = 'Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<!-- Header Section with Direct POS Quick Launch Buttons -->
<div class="d-flex justify-content-end align-items-center mb-3 flex-wrap gap-2">
    <a href="<?= BASE_URL ?>modules/sales/counter.php" class="btn btn-emerald shadow-xs d-inline-flex align-items-center gap-1.5 fw-semibold">
        <i class="ti ti-receipt fs-5"></i> Create OPD Bill
    </a>
    <a href="<?= BASE_URL ?>modules/sales/regular.php" class="btn btn-primary shadow-xs d-inline-flex align-items-center gap-1.5 fw-semibold">
        <i class="ti ti-bed fs-5"></i> Regular / IPD Sale
    </a>
    <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-outline-dark shadow-xs d-inline-flex align-items-center gap-1.5">
        <i class="ti ti-user-plus fs-5"></i> Register Patient
    </a>
</div>

<!-- 4 TOP KPI METRICS CARDS -->
<div class="row g-3 mb-3">
    <!-- Card 1: Total Patients -->
    <div class="col-xl-3 col-sm-6">
        <div class="card card-custom h-100 border-0 shadow-xs" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-left: 4px solid #0f172a !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Total Hospital Patients</span>
                    <span class="rounded-circle bg-dark bg-opacity-10 text-dark d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                        <i class="ti ti-users fs-6"></i>
                    </span>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?= number_format($kpiTotal) ?></h3>
                <small class="text-muted">Master MediPro &amp; Pharmacy directory</small>
            </div>
        </div>
    </div>

    <!-- Card 2: Active IPD Inpatients -->
    <div class="col-xl-3 col-sm-6">
        <div class="card card-custom h-100 border-0 shadow-xs" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); border-left: 4px solid #10b981 !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-emerald small fw-bold text-uppercase d-flex align-items-center gap-1">
                        <span class="spinner-grow spinner-grow-sm text-emerald" style="width: 8px; height: 8px;" role="status"></span>
                        Active IPD Inpatients
                    </span>
                    <span class="rounded-circle bg-emerald bg-opacity-10 text-emerald d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                        <i class="ti ti-bed fs-6"></i>
                    </span>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?= number_format($kpiActiveIpd) ?></h3>
                <small class="text-muted">Currently admitted in hospital beds (<?= $kpiAllIpd ?> total IPD)</small>
            </div>
        </div>
    </div>

    <!-- Card 3: OPD Outpatients -->
    <div class="col-xl-3 col-sm-6">
        <div class="card card-custom h-100 border-0 shadow-xs" style="background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 100%); border-left: 4px solid #0284c7 !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-primary small fw-bold text-uppercase">OPD Outpatients</span>
                    <span class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                        <i class="ti ti-stethoscope fs-6"></i>
                    </span>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?= number_format($kpiOpd) ?></h3>
                <small class="text-muted">Recorded OPD visits &amp; consultations</small>
            </div>
        </div>
    </div>

    <!-- Card 4: Doctor Prescriptions -->
    <div class="col-xl-3 col-sm-6">
        <div class="card card-custom h-100 border-0 shadow-xs" style="background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%); border-left: 4px solid #f59e0b !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-warning small fw-bold text-uppercase">Pending Prescriptions</span>
                    <span class="rounded-circle bg-warning bg-opacity-10 text-warning d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                        <i class="ti ti-prescription fs-6"></i>
                    </span>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?= number_format($kpiRx) ?></h3>
                <small class="text-muted">Ready for live pharmacy dispensing</small>
            </div>
        </div>
    </div>
</div>

<!-- CATEGORY TABS -->
<div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3 flex-wrap gap-2">
    <ul class="nav nav-pills gap-1">
        <li class="nav-item">
            <a class="nav-link px-3 py-1.5 fs-7 <?= $tab === 'all' ? 'active bg-dark text-white fw-semibold' : 'text-secondary' ?>" 
               href="?tab=all<?= $term ? '&q=' . urlencode($term) : '' ?>">
                <i class="ti ti-list me-1"></i> All Patients (<?= $kpiTotal ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link px-3 py-1.5 fs-7 <?= $tab === 'ipd_active' ? 'active bg-emerald text-white fw-semibold' : 'text-secondary' ?>" 
               href="?tab=ipd_active<?= $term ? '&q=' . urlencode($term) : '' ?>">
                <i class="ti ti-bed me-1"></i> Admitted Inpatients (<?= $kpiActiveIpd ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link px-3 py-1.5 fs-7 <?= $tab === 'ipd_all' ? 'active bg-primary text-white fw-semibold' : 'text-secondary' ?>" 
               href="?tab=ipd_all<?= $term ? '&q=' . urlencode($term) : '' ?>">
                <i class="ti ti-building-hospital me-1"></i> All IPD Encounters (<?= $kpiAllIpd ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link px-3 py-1.5 fs-7 <?= $tab === 'opd' ? 'active bg-info text-white fw-semibold' : 'text-secondary' ?>" 
               href="?tab=opd<?= $term ? '&q=' . urlencode($term) : '' ?>">
                <i class="ti ti-stethoscope me-1"></i> OPD Outpatients (<?= $kpiOpd ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link px-3 py-1.5 fs-7 <?= $tab === 'rx' ? 'active bg-warning text-dark fw-semibold' : 'text-secondary' ?>" 
               href="?tab=rx<?= $term ? '&q=' . urlencode($term) : '' ?>">
                <i class="ti ti-prescription me-1"></i> Prescriptions Pending (<?= $kpiRx ?>)
            </a>
        </li>
    </ul>
    <div class="text-muted small">
        Showing <?= min($totalRecords, $offset + 1) ?> - <?= min($totalRecords, $offset + count($patients)) ?> of <?= $totalRecords ?> patients
    </div>
</div>

<!-- SEARCH & FILTER CONTROLS -->
<div class="card card-custom mb-3 p-3 shadow-xs">
    <form method="GET" action="" class="row g-2 align-items-center">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="col-lg-5 col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0 text-muted"><i class="ti ti-search"></i></span>
                <input type="text" name="q" class="form-control border-start-0 ps-0" value="<?= htmlspecialchars($term) ?>" 
                       placeholder="Search by UHID, Name, Mobile, IPD No, or City...">
            </div>
        </div>
        <div class="col-lg-3 col-md-3">
            <select name="doctor" class="form-select">
                <option value="">All Attending Doctors</option>
                <?php foreach ($doctorsList as $doc): ?>
                    <option value="<?= htmlspecialchars($doc) ?>" <?= $doctorFilter === $doc ? 'selected' : '' ?>>
                        <?= htmlspecialchars($doc) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <select name="gender" class="form-select">
                <option value="">All Genders</option>
                <option value="Male" <?= $genderFilter === 'Male' ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $genderFilter === 'Female' ? 'selected' : '' ?>>Female</option>
                <option value="Other" <?= $genderFilter === 'Other' ? 'selected' : '' ?>>Other</option>
            </select>
        </div>
        <div class="col-lg-2 col-md-12 d-flex gap-2">
            <button type="submit" class="btn btn-emerald w-100 fw-semibold"><i class="ti ti-filter me-1"></i> Filter</button>
            <?php if ($term || $doctorFilter || $genderFilter || $tab !== 'all'): ?>
                <a href="list.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="ti ti-rotate-clockwise"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- PATIENTS TABLE -->
<div class="card card-custom border-0 shadow-xs">
    <div class="card-body p-0">
        <?php if (empty($patients)): ?>
            <div class="text-center py-5 text-muted">
                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                    <i class="ti ti-user-x fs-1 text-secondary"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">No patients found</h5>
                <p class="text-muted small mb-3">No patient records matched the specified search filters or clinical category.</p>
                <a href="list.php" class="btn btn-sm btn-outline-primary">
                    <i class="ti ti-refresh me-1"></i> Clear All Filters
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                    <thead class="table-light text-muted fw-semibold border-bottom" style="font-size: 0.80rem; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-3">PATIENT &amp; UHID</th>
                            <th>CARE TYPE / CLASSIFICATION</th>
                            <th>ATTENDING DOCTOR</th>
                            <th>CONTACT / DEMOGRAPHICS</th>
                            <th>CLINICAL SUMMARY</th>
                            <th class="text-end pe-3">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $p): 
                            $isAdmitted = ($p['latest_ipd_status'] === 'Admitted');
                            $hasIpd = ((int)$p['total_admissions'] > 0);
                            $hasOpd = ((int)$p['total_opd_visits'] > 0 || (int)$p['total_prescriptions'] > 0);
                            $pendingRx = (int)$p['pending_prescriptions'];
                            $avatarInitial = strtoupper(substr(trim($p['name'] ?: 'P'), 0, 1));
                            $avatarBg = $isAdmitted ? 'bg-emerald text-white' : ($hasOpd ? 'bg-primary text-white' : 'bg-secondary text-white');
                        ?>
                            <tr>
                                <!-- Col 1: Patient Name & UHID -->
                                <td class="ps-3 py-2.5">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div class="rounded-circle <?= $avatarBg ?> fw-bold d-flex align-items-center justify-content-center flex-shrink-0 shadow-2xs" 
                                             style="width: 36px; height: 36px; font-size: 0.90rem;">
                                            <?= $avatarInitial ?>
                                        </div>
                                        <div>
                                            <a href="history.php?uhid=<?= urlencode($p['uhid']) ?>" class="fw-bold text-dark text-decoration-none hover-primary d-block lh-sm">
                                                <?= htmlspecialchars($p['name'] ?: 'Unnamed Patient') ?>
                                            </a>
                                            <div class="d-flex align-items-center gap-1.5 mt-1 flex-wrap">
                                                <span class="badge bg-primary-subtle text-primary font-monospace fw-bold px-1.5 py-0.5" style="font-size: 0.72rem;">
                                                    <?= htmlspecialchars($p['uhid']) ?>
                                                </span>
                                                <?php if ($p['pharmacy_patient_no'] && $p['pharmacy_patient_no'] !== $p['uhid']): ?>
                                                    <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.68rem;" title="Pharmacy ID">
                                                        <?= htmlspecialchars($p['pharmacy_patient_no']) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($p['blood_group'])): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger px-1 py-0.5" style="font-size: 0.68rem;">
                                                        <?= htmlspecialchars($p['blood_group']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Col 2: Care Type / Classification -->
                                <td>
                                    <?php if ($isAdmitted): ?>
                                        <div class="d-inline-flex flex-column gap-1">
                                            <span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle d-inline-flex align-items-center gap-1 px-2 py-0.5" style="font-size: 0.75rem; font-weight: 700;">
                                                <span class="spinner-grow spinner-grow-sm text-emerald" style="width: 6px; height: 6px;"></span>
                                                IPD: ADMITTED
                                            </span>
                                            <div class="small text-dark fw-medium" style="font-size: 0.78rem;">
                                                <i class="ti ti-bed text-muted me-0.5"></i>
                                                <?= htmlspecialchars($p['ipd_ward'] ?: 'General Ward') ?> &bull; <?= htmlspecialchars($p['ipd_bed'] ?: 'Bed 01') ?>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.70rem;">
                                                IPD #: <?= htmlspecialchars($p['latest_ipd_no'] ?: 'IPD-' . $p['latest_admission_id']) ?>
                                                <?php if (!empty($p['latest_admission_type'])): ?>
                                                    &bull; <span class="badge bg-light text-secondary border"><?= htmlspecialchars($p['latest_admission_type']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php elseif ($hasIpd): ?>
                                        <div class="d-inline-flex flex-column gap-0.5">
                                            <span class="badge bg-secondary-subtle text-secondary border d-inline-flex align-items-center gap-1 px-2 py-0.5" style="font-size: 0.72rem;">
                                                <i class="ti ti-door-exit me-0.5"></i> IPD Discharged
                                            </span>
                                            <div class="text-muted" style="font-size: 0.72rem;">
                                                IPD #: <?= htmlspecialchars($p['latest_ipd_no'] ?: 'IPD-' . $p['latest_admission_id']) ?>
                                            </div>
                                        </div>
                                    <?php elseif ($hasOpd): ?>
                                        <div class="d-inline-flex flex-column gap-0.5">
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle d-inline-flex align-items-center gap-1 px-2 py-0.5" style="font-size: 0.72rem; font-weight: 600;">
                                                <i class="ti ti-stethoscope me-0.5"></i> OPD Outpatient
                                            </span>
                                            <div class="text-muted" style="font-size: 0.72rem;">
                                                <?= (int)$p['total_opd_visits'] ?> OPD Visit<?= (int)$p['total_opd_visits'] == 1 ? '' : 's' ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border px-2 py-0.5" style="font-size: 0.72rem;">
                                            Registered Patient
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Col 3: Attending Doctor -->
                                <td>
                                    <?php if (!empty($p['doctor_name'])): ?>
                                        <div class="fw-semibold text-dark d-flex align-items-center gap-1" style="font-size: 0.84rem;">
                                            <i class="ti ti-user-check text-emerald"></i>
                                            <?= htmlspecialchars($p['doctor_name']) ?>
                                        </div>
                                        <small class="text-muted" style="font-size: 0.72rem;">Consulting Physician</small>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Col 4: Contact / Demographics -->
                                <td>
                                    <div class="fw-medium text-dark">
                                        <?= htmlspecialchars($p['mobile'] ?: 'No Phone') ?>
                                    </div>
                                    <div class="text-muted small" style="font-size: 0.75rem;">
                                        <?= htmlspecialchars($p['gender'] ?: 'Other') ?>
                                        <?php if (!empty($p['city'])): ?>
                                            &bull; <?= htmlspecialchars($p['city']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Col 5: Clinical Summary (Prescriptions & Visits) -->
                                <td>
                                    <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                        <?php if ($pendingRx > 0): ?>
                                            <a href="../sales/counter.php?patient_id=<?= (int)$p['hospital_patient_id'] ?>&uhid=<?= urlencode($p['uhid']) ?>" 
                                               class="badge bg-warning-subtle text-warning border border-warning-subtle text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                               title="Doctor Prescription Pending Dispensing in Pharmacy">
                                                <i class="ti ti-prescription"></i> Rx Ready (<?= $pendingRx ?>)
                                            </a>
                                        <?php elseif ((int)$p['total_prescriptions'] > 0): ?>
                                            <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.70rem;">
                                                <i class="ti ti-check me-0.5"></i> <?= (int)$p['total_prescriptions'] ?> Prescriptions
                                            </span>
                                        <?php endif; ?>

                                        <?php if ((int)$p['total_opd_visits'] > 0): ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle px-1.5 py-0.5" style="font-size: 0.70rem;">
                                                <?= (int)$p['total_opd_visits'] ?> Visits
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Col 6: Contextual Quick-Action Buttons -->
                                <td class="text-end pe-3">
                                    <div class="btn-group btn-group-sm shadow-2xs">
                                        <?php if ($isAdmitted): ?>
                                            <!-- IPD Inpatient Dispensing Button -->
                                            <a href="<?= BASE_URL ?>modules/sales/regular.php?admission_id=<?= (int)$p['latest_admission_id'] ?>&uhid=<?= urlencode($p['uhid']) ?>" 
                                               class="btn btn-emerald text-white px-2.5 py-1 fw-semibold d-inline-flex align-items-center gap-1"
                                               title="Dispense Inpatient Medications directly to Bed">
                                                <i class="ti ti-pill"></i> Dispense IPD
                                            </a>
                                        <?php endif; ?>

                                        <!-- OPD Counter Bill Button -->
                                        <a href="<?= BASE_URL ?>modules/sales/counter.php?patient_id=<?= (int)$p['hospital_patient_id'] ?>&uhid=<?= urlencode($p['uhid']) ?>" 
                                           class="btn <?= $isAdmitted ? 'btn-outline-primary' : 'btn-primary' ?> px-2.5 py-1 fw-semibold d-inline-flex align-items-center gap-1"
                                           title="Create OPD Counter Sale for this Patient">
                                            <i class="ti ti-receipt"></i> OPD Bill
                                        </a>

                                        <!-- Medication & Billing History -->
                                        <a href="<?= BASE_URL ?>modules/patients/history.php?uhid=<?= urlencode($p['uhid']) ?>" 
                                           class="btn btn-outline-secondary px-2 py-1" 
                                           title="View Patient Medical &amp; Pharmacy History">
                                            <i class="ti ti-history"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION BAR -->
            <?php if ($totalPages > 1): ?>
                <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-muted small">
                        Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalRecords ?> total records)
                    </span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page - 1 ?>&tab=<?= urlencode($tab) ?><?= $term ? '&q=' . urlencode($term) : '' ?><?= $doctorFilter ? '&doctor=' . urlencode($doctorFilter) : '' ?>">
                                    &laquo; Prev
                                </a>
                            </li>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link <?= $i === $page ? 'bg-emerald border-emerald' : '' ?>" href="?page=<?= $i ?>&tab=<?= urlencode($tab) ?><?= $term ? '&q=' . urlencode($term) : '' ?><?= $doctorFilter ? '&doctor=' . urlencode($doctorFilter) : '' ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page + 1 ?>&tab=<?= urlencode($tab) ?><?= $term ? '&q=' . urlencode($term) : '' ?><?= $doctorFilter ? '&doctor=' . urlencode($doctorFilter) : '' ?>">
                                    Next &raquo;
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
