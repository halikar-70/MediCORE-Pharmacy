<?php
// modules/patients/search.php - Live Hospital & Pharmacy Patient Directory Search (STEP 5)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/PatientService.php';

use Pharmacy\Database\Database;
use Pharmacy\Services\PatientService;

require_permission('pharmacy.patients.view');

// AJAX Live Search Endpoint
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $term = trim($_GET['q'] ?? '');
    $filter = trim($_GET['filter'] ?? 'all'); // 'all', 'hospital', 'pharmacy', 'ipd'
    $results = [];

    $hospitalPdo = Database::getHospitalConnection();

    // 1. Search Hospital Patients
    if ($hospitalPdo && in_array($filter, ['all', 'hospital', 'ipd'])) {
        try {
            $like = '%' . $term . '%';
            $prefixLike = $term . '%';
            $sql = "
                SELECT 
                    p.patient_id as hospital_patient_id,
                    COALESCE(p.patient_code, CONCAT('VH', p.patient_id)) as uhid,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) as raw_name,
                    COALESCE(p.phone, '') as mobile,
                    COALESCE(p.gender, 'Other') as gender,
                    p.dob,
                    COALESCE(p.city, '') as city,
                    COALESCE(p.address, '') as address,
                    COALESCE(
                        (SELECT d.name FROM opd_visits v JOIN doctors d ON d.doctor_id = v.doctor_id WHERE v.patient_id = p.patient_id ORDER BY v.visit_id DESC LIMIT 1),
                        (SELECT d.name FROM prescriptions pr JOIN doctors d ON d.doctor_id = pr.doctor_id WHERE pr.patient_id = p.patient_id ORDER BY pr.prescription_id DESC LIMIT 1),
                        (SELECT d.name FROM appointments a JOIN doctors d ON d.doctor_id = a.doctor_id WHERE a.patient_id = p.patient_id ORDER BY a.appointment_id DESC LIMIT 1),
                        (SELECT d.name FROM admissions adm JOIN doctors d ON d.doctor_id = adm.doctor_id WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1)
                    ) as doctor_name,
                    (SELECT adm.ipd_number FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_ipd_no,
                    (SELECT w.ward_name FROM admissions adm LEFT JOIN wards w ON adm.ward_id = w.ward_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_ward,
                    (SELECT b.bed_number FROM admissions adm LEFT JOIN beds b ON adm.bed_id = b.bed_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_bed
                FROM patients p
                WHERE (p.status IS NULL OR p.status = 'Active' OR p.status = 1)
            ";

            $params = [];
            if ($term !== '') {
                $sql .= " AND (
                    p.patient_code LIKE ? 
                    OR p.phone LIKE ? 
                    OR CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) LIKE ?
                    OR p.first_name LIKE ? 
                    OR p.last_name LIKE ? 
                    OR p.middle_name LIKE ?
                    OR EXISTS (SELECT 1 FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.ipd_number LIKE ?)
                    OR EXISTS (SELECT 1 FROM opd_visits v JOIN doctors d ON d.doctor_id = v.doctor_id WHERE v.patient_id = p.patient_id AND d.name LIKE ?)
                    OR EXISTS (SELECT 1 FROM admissions adm JOIN doctors d ON d.doctor_id = adm.doctor_id WHERE adm.patient_id = p.patient_id AND d.name LIKE ?)
                )";
                $params = [$like, $like, $like, $like, $like, $like, $like, $like, $like];
            }

            if ($filter === 'ipd') {
                $sql .= " AND EXISTS (SELECT 1 FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted')";
            }

            if ($term !== '') {
                $sql .= " ORDER BY 
                    CASE 
                        WHEN CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) LIKE ? THEN 1
                        WHEN p.first_name LIKE ? THEN 2
                        WHEN p.last_name LIKE ? THEN 3
                        WHEN p.patient_code LIKE ? THEN 4
                        WHEN p.phone LIKE ? THEN 5
                        ELSE 6 
                    END,
                    p.first_name ASC, p.last_name ASC LIMIT 100";
                $params[] = $prefixLike;
                $params[] = $prefixLike;
                $params[] = $prefixLike;
                $params[] = $prefixLike;
                $params[] = $prefixLike;
            } else {
                $sql .= " ORDER BY p.first_name ASC, p.last_name ASC LIMIT 100";
            }

            $stmt = $hospitalPdo->prepare($sql);
            $stmt->execute($params);

            $hRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hRows as $r) {
                $name = preg_replace('/\s+/', ' ', trim($r['raw_name']));
                $results[] = [
                    'type'                => !empty($r['active_ipd_no']) ? 'IPD' : 'HOSPITAL',
                    'id'                  => (int)$r['hospital_patient_id'],
                    'hospital_patient_id' => (int)$r['hospital_patient_id'],
                    'uhid'                => $r['uhid'],
                    'pharmacy_patient_no' => $r['uhid'],
                    'name'                => $name,
                    'mobile'              => $r['mobile'],
                    'gender'              => $r['gender'],
                    'dob'                 => $r['dob'] ?? '',
                    'city'                => $r['city'],
                    'address'             => $r['address'],
                    'doctor_name'         => $r['doctor_name'] ?? '',
                    'ipd_number'          => $r['active_ipd_no'] ?? null,
                    'ward'                => $r['active_ward'] ?? '',
                    'bed'                 => $r['active_bed'] ?? ''
                ];
            }
        } catch (Exception $ex) {}
    }

    // 2. Search Local Pharmacy Patients
    if (in_array($filter, ['all', 'pharmacy'])) {
        try {
            $like = '%' . $term . '%';
            $prefixLike = $term . '%';
            $sql = "
                SELECT 
                    pp.id,
                    pp.pharmacy_patient_no,
                    pp.hospital_patient_id,
                    pp.hospital_uhid,
                    pp.name,
                    pp.mobile,
                    pp.gender,
                    pp.date_of_birth as dob,
                    pp.city,
                    pp.address,
                    COALESCE(
                        (SELECT s.doctor_name FROM pharmacy_sales s WHERE (s.patient_id = pp.id OR s.customer_name = pp.name) AND s.doctor_name IS NOT NULL AND s.doctor_name != '' ORDER BY s.sale_id DESC LIMIT 1),
                        (SELECT pr.doctor_name FROM pharmacy_prescriptions pr WHERE (pr.patient_id = pp.id OR pr.patient_name = pp.name) AND pr.doctor_name IS NOT NULL AND pr.doctor_name != '' ORDER BY pr.prescription_id DESC LIMIT 1)
                    ) as doctor_name
                FROM pharmacy_patients pp
                WHERE pp.status = 'Active'
            ";

            $params = [];
            if ($term !== '') {
                $sql .= " AND (pp.pharmacy_patient_no LIKE ? OR pp.name LIKE ? OR pp.mobile LIKE ? OR pp.hospital_uhid LIKE ?)";
                $params = [$like, $like, $like, $like];
                $sql .= " ORDER BY 
                    CASE 
                        WHEN pp.name LIKE ? THEN 1
                        WHEN pp.hospital_uhid LIKE ? THEN 2
                        WHEN pp.pharmacy_patient_no LIKE ? THEN 3
                        ELSE 4 
                    END,
                    pp.name ASC LIMIT 100";
                $params[] = $prefixLike;
                $params[] = $prefixLike;
                $params[] = $prefixLike;
            } else {
                $sql .= " ORDER BY pp.name ASC LIMIT 100";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $pRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $existingUhids = array_flip(array_filter(array_column($results, 'uhid')));

            foreach ($pRows as $r) {
                if (empty($r['hospital_uhid']) || !isset($existingUhids[$r['hospital_uhid']])) {
                    $results[] = [
                        'type'                => 'PHARMACY',
                        'id'                  => (int)$r['id'],
                        'hospital_patient_id' => (int)($r['hospital_patient_id'] ?? 0),
                        'uhid'                => $r['hospital_uhid'] ?: $r['pharmacy_patient_no'],
                        'pharmacy_patient_no' => $r['pharmacy_patient_no'],
                        'name'                => $r['name'],
                        'mobile'              => $r['mobile'] ?: '',
                        'gender'              => $r['gender'] ?: 'Other',
                        'dob'                 => $r['dob'] ?? '',
                        'city'                => $r['city'] ?: '',
                        'address'             => $r['address'] ?: '',
                        'doctor_name'         => $r['doctor_name'] ?? '',
                        'ipd_number'          => null,
                        'ward'                => '',
                        'bed'                 => ''
                    ];
                }
            }
        } catch (Exception $pex) {}
    }

    echo json_encode(['success' => true, 'count' => count($results), 'data' => $results]);
    exit;
}

$page_title = 'Search Hospital & Pharmacy Patient Directory';
$initialQuery = trim($_GET['q'] ?? '');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<style>
.pat-filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    font-size: 0.76rem;
    font-weight: 600;
    border-radius: 9999px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.pat-filter-pill:hover {
    background: #f1f5f9;
    color: #0f172a;
    border-color: #cbd5e1;
}
.pat-filter-pill.active {
    background: #0d9488;
    color: #ffffff;
    border-color: #0d9488;
    box-shadow: 0 1px 3px rgba(13, 148, 136, 0.25);
}
.search-highlight {
    background: transparent !important;
    color: #059669 !important;
    font-weight: 800 !important;
    padding: 0 !important;
}
.badge-type-paid {
    background-color: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.badge-type-paidr {
    background-color: #f0fdf4;
    color: #15803d;
    border: 1px solid #86efac;
}
.badge-type-cashless {
    background-color: #fff1f2;
    color: #9f1239;
    border: 1px solid #fecdd3;
}
.badge-type-hospital {
    background-color: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.badge-type-pharmacy {
    background-color: #f5f3ff;
    color: #6d28d9;
    border: 1px solid #ddd6fe;
}
.patient-table-row {
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.patient-table-row:hover {
    background-color: #f8fafc !important;
}
</style>

<div class="container-fluid py-3 px-3 px-md-4">
    <!-- Main Unified Patient Directory Card (Matching IPD Admissions Style) -->
    <div class="card border shadow-sm rounded-4 mb-4 bg-white" style="border: 1px solid #e2e8f0 !important; border-radius: 14px !important;">
        <!-- Header matching reference layout -->
        <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; border-radius: 12px; background-color: #f0fdf4; color: #0d9488; border: 1px solid #99f6e4;">
                    <i class="bi bi-people-fill fs-4"></i>
                </div>
                <div>
                    <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.32rem; letter-spacing: -0.3px;">
                        Patient Directory
                    </h4>
                    <div class="text-muted small" style="font-size: 0.83rem;">
                        Manage and track hospital inpatients, OPD visitors, and registered pharmacy patients.
                    </div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3.5 py-1.5 fw-semibold d-inline-flex align-items-center gap-1.5 shadow-xs" onclick="window.print()" style="font-size: 0.82rem;">
                    <i class="bi bi-file-earmark-pdf text-danger"></i> Print Patient List (PDF)
                </button>
                <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-sm text-white rounded-pill px-3.5 py-1.5 fw-bold d-inline-flex align-items-center gap-1.5 shadow-sm" style="background-color: #0d9488; border-color: #0d9488; font-size: 0.82rem;">
                    <i class="bi bi-plus-lg"></i> New Patient
                </a>
            </div>
        </div>

        <div class="card-body p-4">
            <!-- Filter Toolbar matching reference toolbar -->
            <div class="row g-2 align-items-center mb-2.5">
                <div class="col-lg-4 col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="patientSearchInput" class="form-control bg-white border-start-0 ps-1" placeholder="Search patient name, code, phone, doctor..." value="<?= htmlspecialchars($initialQuery) ?>" oninput="onPatientLiveInput(this.value)" onkeydown="onPatientSearchKeydown(event)" autocomplete="off">
                        <button type="button" id="btnClearSearch" class="btn btn-outline-secondary border-start-0 d-none" onclick="clearLiveSearch()" title="Clear Search">
                            <i class="ti ti-x"></i>
                        </button>
                    </div>
                </div>
                <div class="col-lg-2 col-md-3">
                    <select id="patientTypeSelect" class="form-select" onchange="setFilter(this.value)">
                        <option value="all" selected>All Patients</option>
                        <option value="hospital">Hospital UHID</option>
                        <option value="ipd">Admitted IPD</option>
                        <option value="pharmacy">Pharmacy Registered</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <select id="patientSortSelect" class="form-select" onchange="setSort(this.value)">
                        <option value="az" selected>Sort: A - Z (Alphabetical)</option>
                        <option value="recent">Sort: Recent</option>
                        <option value="za">Sort: Z - A</option>
                    </select>
                </div>
                <div class="col-lg-4 col-md-12 d-flex align-items-center gap-2">
                    <button type="button" class="btn text-white fw-bold px-3.5 shadow-xs d-inline-flex align-items-center gap-1" style="background-color: #0d9488; border-color: #0d9488;" onclick="fetchPatients()">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                    <button type="button" class="btn btn-link text-muted fw-semibold text-decoration-none px-2" onclick="clearLiveSearch()">
                        Reset
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-auto rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1" onclick="window.print()" style="font-size: 0.80rem;">
                        <i class="bi bi-file-earmark-pdf text-danger"></i> Print Filtered List (PDF)
                    </button>
                </div>
            </div>

            <!-- Sleek Quick-Filter Bar -->
            <div class="d-flex justify-content-between align-items-center mb-3.5 flex-wrap gap-2 pt-2 border-top">
                <div class="d-flex align-items-center gap-1.5 flex-wrap">
                    <span class="text-muted small fw-semibold me-1" style="font-size: 0.74rem; letter-spacing: 0.3px;">QUICK FILTER:</span>
                    <button type="button" class="pat-filter-pill active" data-filter="all" onclick="setFilter('all')">
                        <i class="bi bi-grid-fill"></i> All Patients
                    </button>
                    <button type="button" class="pat-filter-pill" data-filter="hospital" onclick="setFilter('hospital')">
                        <i class="bi bi-hospital"></i> Hospital UHID
                    </button>
                    <button type="button" class="pat-filter-pill" data-filter="ipd" onclick="setFilter('ipd')">
                        <i class="bi bi-bed"></i> Admitted IPD
                    </button>
                    <button type="button" class="pat-filter-pill" data-filter="pharmacy" onclick="setFilter('pharmacy')">
                        <i class="bi bi-people-fill"></i> Pharmacy Registered
                    </button>
                </div>

                <div class="d-flex align-items-center gap-1.5 flex-wrap ms-auto">
                    <span class="text-muted small fw-semibold me-1" style="font-size: 0.74rem;">SORT:</span>
                    <button type="button" class="pat-filter-pill active" data-sort="az" onclick="setSort('az')">
                        <i class="bi bi-sort-alpha-down"></i> A - Z
                    </button>
                    <button type="button" class="pat-filter-pill" data-sort="recent" onclick="setSort('recent')">
                        <i class="bi bi-clock-history"></i> Recent
                    </button>
                    <button type="button" class="pat-filter-pill" data-sort="za" onclick="setSort('za')">
                        <i class="bi bi-sort-alpha-up-alt"></i> Z - A
                    </button>
                    <span class="badge bg-light text-secondary border font-monospace px-2.5 py-1.5 ms-2" id="resultsCountBadge" style="font-size: 0.78rem;">0 Records</span>
                </div>
            </div>

            <!-- Loading Spinner -->
            <div id="resultsLoading" class="text-center py-5 d-none">
                <div class="spinner-border text-emerald mb-2" role="status" style="color: #0d9488 !important;"></div>
                <div class="small text-muted">Searching patient records...</div>
            </div>

            <!-- Patient Table matching reference screenshot -->
            <div class="table-responsive border rounded-3 overflow-hidden shadow-xs" id="resultsTableWrap">
                <table class="table table-hover align-middle mb-0" id="patientDirectoryTable">
                    <thead class="table-light small text-muted text-uppercase" style="font-size: 0.74rem; letter-spacing: 0.5px; background-color: #f8fafc;">
                        <tr>
                            <th style="width: 50px;" class="text-center">SR NO.</th>
                            <th style="width: 140px;">IPD / UHID NO</th>
                            <th>PATIENT NAME</th>
                            <th style="width: 120px;">PATIENT TYPE</th>
                            <th>DOCTOR NAME</th>
                            <th>WARD WITH BED NO</th>
                            <th style="width: 140px;">CONTACT &amp; GENDER</th>
                            <th style="width: 120px;">LOCATION / REF</th>
                            <th class="text-center" style="width: 140px;">ADD MEDICINE</th>
                        </tr>
                    </thead>
                    <tbody id="patientResultsTableBody">
                        <!-- Populated dynamically by JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div id="noResultsState" class="text-center py-5 d-none">
                <i class="bi bi-people fs-1 text-secondary opacity-50 mb-2 d-block"></i>
                <h6 class="fw-bold text-dark mb-1">No Matching Patients Found</h6>
                <p class="text-muted small mb-3">No patient matched your search query in Hospital or Pharmacy records.</p>
                <a href="<?= BASE_URL ?>modules/patients/register.php" id="btnRegisterEmpty" class="btn btn-sm text-white rounded-pill px-3.5 py-1.5 fw-bold shadow-xs" style="background-color: #0d9488; border-color: #0d9488;">
                    <i class="bi bi-plus-lg me-1"></i> Register New Patient
                </a>
            </div>
        </div>
    </div>
</div>

<script>
let currentFilter = 'all';
let currentSort = 'az';
let debounceTimer = null;
let currentPatients = [];

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function getCleanSortName(name) {
    if (!name) return '';
    return name.replace(/^(mr\.|mrs\.|miss|master|baby|b\/o|s\/o|d\/o|w\/o|dr\.)\s+/i, '').trim();
}

function highlightMatch(text, query) {
    if (!text) return '';
    if (!query) return escapeHtml(text);
    const escaped = escapeHtml(text);
    const qEscaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`(${qEscaped})`, 'gi');
    return escaped.replace(regex, '<span class="search-highlight">$1</span>');
}

function setFilter(filter) {
    currentFilter = filter;
    const typeSelect = document.getElementById('patientTypeSelect');
    if (typeSelect && typeSelect.value !== filter) {
        typeSelect.value = filter;
    }
    document.querySelectorAll('[data-filter]').forEach(b => {
        if (b.getAttribute('data-filter') === filter) b.classList.add('active');
        else b.classList.remove('active');
    });
    fetchPatients();
}

function setSort(sort) {
    currentSort = sort;
    const sortSelect = document.getElementById('patientSortSelect');
    if (sortSelect && sortSelect.value !== sort) {
        sortSelect.value = sort;
    }
    document.querySelectorAll('[data-sort]').forEach(b => {
        if (b.getAttribute('data-sort') === sort) b.classList.add('active');
        else b.classList.remove('active');
    });
    renderPatients(currentPatients);
}

function onPatientLiveInput(val) {
    const btnClear = document.getElementById('btnClearSearch');
    if (btnClear) {
        if (val.trim()) btnClear.classList.remove('d-none');
        else btnClear.classList.add('d-none');
    }

    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        fetchPatients();
    }, 180);
}

function clearLiveSearch() {
    const inp = document.getElementById('patientSearchInput');
    if (inp) {
        inp.value = '';
        inp.focus();
    }
    const btnClear = document.getElementById('btnClearSearch');
    if (btnClear) btnClear.classList.add('d-none');
    fetchPatients();
}

function rankPatient(p, q) {
    if (!q) return 0;
    q = q.toLowerCase();
    const name = (p.name || '').toLowerCase();
    const cleanName = name.replace(/^(mr\.|mrs\.|miss|master|baby|b\/o|s\/o|d\/o|w\/o|dr\.)\s+/i, '').trim();
    const uhid = (p.uhid || '').toLowerCase();
    const doc = (p.doctor_name || '').toLowerCase();
    const cleanDoc = doc.replace(/^dr\.\s+/i, '').trim();
    const ipd = (p.ipd_number || '').toLowerCase();
    const mob = (p.mobile || '').toLowerCase();

    const nameWords = name.split(/[\s,./-]+/).filter(Boolean);
    const docWords = doc.split(/[\s,./-]+/).filter(Boolean);

    // Exact or prefix matches
    if (name.startsWith(q) || cleanName.startsWith(q)) return 100;
    if (nameWords.some(w => w.startsWith(q))) return 90;
    if (uhid.startsWith(q) || uhid === q) return 85;
    if (doc.startsWith(q) || cleanDoc.startsWith(q) || docWords.some(w => w.startsWith(q))) return 80;
    if (ipd.includes(q)) return 75;
    if (mob.startsWith(q)) return 70;
    if (name.includes(q)) return 50;
    if (doc.includes(q)) return 40;
    if (uhid.includes(q) || mob.includes(q)) return 30;
    return 10;
}

function fetchPatients() {
    const query = document.getElementById('patientSearchInput').value.trim();
    const loading = document.getElementById('resultsLoading');
    const tableWrap = document.getElementById('resultsTableWrap');
    const emptyState = document.getElementById('noResultsState');

    if (loading) loading.classList.remove('d-none');
    if (tableWrap) tableWrap.classList.add('opacity-50');

    fetch(`search.php?ajax_search=1&q=${encodeURIComponent(query)}&filter=${encodeURIComponent(currentFilter)}`)
        .then(r => r.json())
        .then(data => {
            if (loading) loading.classList.add('d-none');
            if (tableWrap) tableWrap.classList.remove('opacity-50');

            if (data.success && Array.isArray(data.data)) {
                currentPatients = data.data;
                renderPatients(currentPatients);
            } else {
                currentPatients = [];
                renderPatients([]);
            }
        })
        .catch(err => {
            if (loading) loading.classList.add('d-none');
            if (tableWrap) tableWrap.classList.remove('opacity-50');
            console.error(err);
        });
}

function renderPatients(list) {
    const query = document.getElementById('patientSearchInput').value.trim();
    const tbody = document.getElementById('patientResultsTableBody');
    const countBadge = document.getElementById('resultsCountBadge');
    const emptyState = document.getElementById('noResultsState');
    const tableWrap = document.getElementById('resultsTableWrap');
    const btnEmpty = document.getElementById('btnRegisterEmpty');

    if (btnEmpty && query) {
        btnEmpty.href = `<?= BASE_URL ?>modules/patients/register.php?name=${encodeURIComponent(query)}`;
    }

    // Apply sorting & relevance ranking
    let sortedList = [...list];
    if (query) {
        sortedList.sort((a, b) => {
            const rankA = rankPatient(a, query);
            const rankB = rankPatient(b, query);
            if (rankA !== rankB) return rankB - rankA;
            return getCleanSortName(a.name).localeCompare(getCleanSortName(b.name));
        });
    } else if (currentSort === 'az') {
        sortedList.sort((a, b) => getCleanSortName(a.name).localeCompare(getCleanSortName(b.name)));
    } else if (currentSort === 'za') {
        sortedList.sort((a, b) => getCleanSortName(b.name).localeCompare(getCleanSortName(a.name)));
    } else if (currentSort === 'recent') {
        sortedList.sort((a, b) => (b.id || 0) - (a.id || 0));
    }

    if (countBadge) {
        countBadge.textContent = `${sortedList.length} Inpatients / Records`;
    }

    if (sortedList.length === 0) {
        if (tbody) tbody.innerHTML = '';
        if (tableWrap) tableWrap.classList.add('d-none');
        if (emptyState) emptyState.classList.remove('d-none');
        return;
    }

    if (emptyState) emptyState.classList.add('d-none');
    if (tableWrap) tableWrap.classList.remove('d-none');

    let html = '';
    sortedList.forEach((p, idx) => {
        let typeClass = 'badge-type-hospital';
        let typeLabel = 'Hospital';
        if (p.type === 'IPD' || p.ipd_number) {
            typeClass = 'badge-type-paidr';
            typeLabel = 'Admitted IPD';
        } else if (p.type === 'PHARMACY') {
            typeClass = 'badge-type-pharmacy';
            typeLabel = 'Pharmacy';
        }

        const highlightedName = highlightMatch(p.name, query);
        const highlightedUhid = highlightMatch(p.uhid, query);
        const highlightedIpd = p.ipd_number ? highlightMatch(p.ipd_number, query) : '';
        const highlightedMobile = p.mobile ? highlightMatch(p.mobile, query) : '';
        const highlightedDoc = p.doctor_name ? highlightMatch(p.doctor_name, query) : 'Dr. Duty Doctor';
        const highlightedWard = p.ward ? highlightMatch(p.ward, query) : 'OPD / General';
        const highlightedBed = p.bed ? highlightMatch(p.bed, query) : '';

        const primaryIdCode = highlightedIpd || highlightedUhid;
        const historyUrl = `<?= BASE_URL ?>modules/patients/history.php?uhid=${encodeURIComponent(p.uhid || '')}&name=${encodeURIComponent(p.name || '')}&source=${encodeURIComponent(p.type || '')}&pharmacy_id=${p.type === 'PHARMACY' ? p.id : ''}&hospital_id=${p.hospital_patient_id || (p.type !== 'PHARMACY' ? p.id : '')}`;
        const targetUrl = p.type === 'IPD' || p.ipd_number 
            ? `<?= BASE_URL ?>modules/sales/regular.php?patient_id=${p.id}&patient_name=${encodeURIComponent(p.name)}`
            : `<?= BASE_URL ?>modules/sales/counter.php?patient_id=${p.id}&patient_name=${encodeURIComponent(p.name)}`;

        html += `
            <tr class="patient-table-row" onclick="window.location.href='${historyUrl}'">
                <td class="text-muted text-center font-monospace" style="font-size: 0.82rem;">${idx + 1}</td>
                <td>
                    <span class="fw-bold" style="color: #be123c; font-family: monospace; font-size: 0.84rem;">${primaryIdCode}</span>
                    ${highlightedIpd && highlightedUhid ? `<div class="small text-muted font-monospace" style="font-size: 0.70rem;">${highlightedUhid}</div>` : ''}
                </td>
                <td>
                    <div class="fw-bold text-dark" style="font-size: 0.88rem;">${highlightedName}</div>
                    ${p.city || p.address ? `<div class="small text-muted" style="font-size: 0.72rem;">${escapeHtml(p.city ? p.city + (p.address ? ', ' + p.address : '') : p.address)}</div>` : ''}
                </td>
                <td>
                    <span class="badge ${typeClass}" style="font-size: 0.72rem; padding: 4px 8px;">${escapeHtml(typeLabel)}</span>
                </td>
                <td class="text-dark fw-semibold" style="font-size: 0.84rem;">
                    ${highlightedDoc}
                </td>
                <td class="text-dark" style="font-size: 0.84rem;">
                    <strong>${highlightedWard}</strong> ${highlightedBed ? `<span class="badge bg-light text-secondary border ms-1 font-monospace" style="font-size: 0.72rem;">Bed ${highlightedBed}</span>` : ''}
                </td>
                <td class="text-muted small font-monospace" style="font-size: 0.78rem;">
                    <div>${highlightedMobile || '—'}</div>
                    <div class="text-muted" style="font-size: 0.70rem;">${escapeHtml(p.gender || 'Other')}${p.dob ? ' • ' + escapeHtml(p.dob) : ''}</div>
                </td>
                <td class="text-muted small" style="font-size: 0.80rem;">
                    ${escapeHtml(p.city || 'Self')}
                </td>
                <td class="text-center" onclick="event.stopPropagation()">
                    <div class="d-flex align-items-center justify-content-center gap-1">
                        <a href="${targetUrl}" 
                           class="btn btn-sm btn-outline-info text-dark fw-bold px-3 py-1 shadow-xs d-inline-flex align-items-center gap-1 rounded-pill" 
                           style="border-color: #38bdf8; font-size: 0.78rem;">
                            Add Medicine
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border rounded-circle p-1 shadow-2xs" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="bi bi-three-dots-vertical" style="font-size: 0.75rem;"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border" style="font-size: 0.82rem;">
                                <li>
                                    <a class="dropdown-item py-1.5 text-primary fw-semibold" href="${historyUrl}">
                                        <i class="bi bi-clock-history me-1.5"></i> Patient Billing History
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item py-1.5 text-success fw-semibold" href="<?= BASE_URL ?>modules/sales/counter.php?patient_id=${p.id}&patient_name=${encodeURIComponent(p.name)}">
                                        <i class="bi bi-receipt me-1.5"></i> Create OPD Bill
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item py-1.5 text-primary fw-semibold" href="<?= BASE_URL ?>modules/sales/regular.php?patient_id=${p.id}&patient_name=${encodeURIComponent(p.name)}">
                                        <i class="bi bi-hospital me-1.5"></i> Regular / IPD Sale
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <a class="dropdown-item py-1.5 text-dark" href="<?= BASE_URL ?>modules/patients/register.php?hospital_patient_id=${p.hospital_patient_id}&hospital_uhid=${encodeURIComponent(p.uhid)}&name=${encodeURIComponent(p.name)}&mobile=${encodeURIComponent(p.mobile)}&gender=${encodeURIComponent(p.gender)}">
                                        <i class="bi bi-pencil me-1.5"></i> Edit Details
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

function onPatientSearchKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (currentPatients && currentPatients.length > 0) {
            const p = currentPatients[0];
            const targetUrl = p.type === 'IPD' || p.ipd_number 
                ? `<?= BASE_URL ?>modules/sales/regular.php?patient_id=${p.id}&patient_name=${encodeURIComponent(p.name)}`
                : `<?= BASE_URL ?>modules/sales/counter.php?patient_id=${p.id}&patient_name=${encodeURIComponent(p.name)}`;
            window.location.href = targetUrl;
        }
    } else if (e.key === 'Escape') {
        clearLiveSearch();
    }
}

// Initial fetch on page load
document.addEventListener('DOMContentLoaded', () => {
    fetchPatients();
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
