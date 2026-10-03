<?php
// modules/sales/print_ipd_patients.php - Professional Inpatient Admissions Census & Registry PDF / Print Document

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';

require_permission('pharmacy.sales.view');

// Hospital Master Info
$hospitalName = "VATSALYA HOSPITAL & RESEARCH CENTRE";
$hospitalTagline = "Multi-Speciality Healthcare & Inpatient Care Centre";
$hospitalAddress = "22, 2A, Mundhwa - Kharadi Rd, near Galaxy Pathare Plaza, Kharadi, Pune, Maharashtra 411014";
$hospitalPhone = "+91 9595854545 / 020-276335";
$hospitalEmail = "info@vatsalyahospital.com";
$hospitalCin = "U85110KA2003PTC033055";
$hospitalGstin = "27AAQFV6256M1Z8";

// Filters from Query
$searchQuery = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'Admitted');
$wardFilter = trim($_GET['ward'] ?? 'ALL');
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

// Fetch Active Admitted Patients from Hospital DB
$patients = [];
try {
    $hospitalPdo = \Pharmacy\Database\Database::getHospitalConnection();
    if ($hospitalPdo) {
        $statusClause = ($statusFilter !== 'ALL') ? "WHERE a.status = " . $hospitalPdo->quote($statusFilter) : "";
        
        $sql = "
            SELECT 
                a.admission_id,
                a.ipd_number,
                a.admission_date,
                a.admission_type,
                a.is_mediclaim,
                a.diagnosis,
                a.status as admission_status,
                a.patient_id as hospital_patient_id,
                p.patient_code as hospital_uhid,
                p.patient_prefix,
                p.first_name,
                p.last_name,
                p.phone as mobile,
                p.gender,
                p.dob,
                p.referred_by,
                COALESCE(w.ward_name, 'General Ward') as ipd_ward,
                COALESCE(b.bed_number, 'Bed-01') as ipd_bed,
                COALESCE(d.name, 'Dr. Duty Doctor') as doctor_name
            FROM admissions a
            JOIN patients p ON a.patient_id = p.patient_id
            LEFT JOIN wards w ON a.ward_id = w.ward_id
            LEFT JOIN beds b ON a.bed_id = b.bed_id
            LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
            {$statusClause}
            ORDER BY a.admission_id DESC
        ";
        $stmt = $hospitalPdo->query($sql);
        $rawPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawPatients as $hp) {
            $fullName = trim(($hp['patient_prefix'] ? $hp['patient_prefix'] . ' ' : '') . $hp['first_name'] . ' ' . $hp['last_name']);
            if ($fullName === '') $fullName = 'Inpatient #' . $hp['admission_id'];

            $wardName = $hp['ipd_ward'] ?: 'General Ward';

            // Filter by Ward
            if ($wardFilter !== 'ALL' && strcasecmp($wardName, $wardFilter) !== 0) {
                continue;
            }

            // Filter by Search Query
            if ($searchQuery !== '') {
                $q = strtolower($searchQuery);
                $haystack = strtolower($fullName . ' ' . $hp['hospital_uhid'] . ' ' . $hp['ipd_number'] . ' ' . $hp['mobile'] . ' ' . $hp['doctor_name'] . ' ' . $wardName . ' ' . $hp['ipd_bed']);
                if (strpos($haystack, $q) === false) {
                    continue;
                }
            }

            $isMed = strtolower($hp['is_mediclaim'] ?? '');
            $admType = !empty($hp['admission_type']) ? $hp['admission_type'] : ($isMed === 'yes' ? 'Cashless' : 'Paid');
            $ipdNo = $hp['ipd_number'] ?: ('IPD/' . date('Y') . '/' . str_pad($hp['admission_id'], 4, '0', STR_PAD_LEFT));
            $uhid = $hp['hospital_uhid'] ?: ('VH-' . $hp['hospital_patient_id']);
            $admDate = !empty($hp['admission_date']) ? date('d-M-Y h:i A', strtotime($hp['admission_date'])) : 'N/A';

            // Calculate age if dob available
            $ageStr = '';
            if (!empty($hp['dob']) && $hp['dob'] !== '0000-00-00') {
                try {
                    $birth = new DateTime($hp['dob']);
                    $today = new DateTime();
                    $ageStr = $today->diff($birth)->y . 'Y';
                } catch (Exception $dex) {}
            }

            $patients[] = [
                'admission_id'    => (int)$hp['admission_id'],
                'ipd_number'      => $ipdNo,
                'hospital_uhid'   => $uhid,
                'name'            => $fullName,
                'mobile'          => $hp['mobile'] ?? '',
                'gender'          => $hp['gender'] ?? '',
                'age'             => $ageStr,
                'admission_type'  => $admType,
                'is_mediclaim'    => $isMed,
                'doctor_name'     => $hp['doctor_name'] ?: 'Dr. Duty Doctor',
                'ipd_ward'        => $wardName,
                'ipd_bed'         => $hp['ipd_bed'] ?: 'Bed-01',
                'admission_date'  => $admDate,
                'referred_by'     => $hp['referred_by'] ?: 'Self',
                'status'          => $hp['admission_status'] ?: 'Admitted'
            ];
        }
    }
} catch (Exception $e) {
    // Graceful fallback
}

// Summary Metrics
$totalCount = count($patients);
$maleCount = 0;
$femaleCount = 0;
$cashlessCount = 0;
$paidCount = 0;

foreach ($patients as $p) {
    $g = strtolower($p['gender'] ?? '');
    if ($g === 'male' || $g === 'm') $maleCount++;
    elseif ($g === 'female' || $g === 'f') $femaleCount++;

    $admType = strtolower($p['admission_type'] ?? '');
    $isMed = strtolower($p['is_mediclaim'] ?? '');
    if (strpos($admType, 'cashless') !== false || strpos($admType, 'tpa') !== false || strpos($admType, 'insurance') !== false || $isMed === 'yes') {
        $cashlessCount++;
    } else {
        $paidCount++;
    }
}

$generatedAt = date('d-M-Y h:i A');
$reportNo = 'CENSUS-' . date('Ymd-His');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inpatient Admissions Registry - <?= htmlspecialchars($hospitalName) ?></title>
    
    <!-- Google Fonts & Bootstrap Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #cbd5e1;
            --border-light: #e2e8f0;
            --bg-light: #f8fafc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: var(--text-main);
            background-color: #f1f5f9;
            padding: 20px;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }

        /* Screen Action Toolbar */
        .no-print-bar {
            max-width: 1100px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.08);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            border: 1px solid transparent;
        }

        .btn-primary {
            background-color: #0284c7;
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(2, 132, 199, 0.25);
        }
        .btn-primary:hover {
            background-color: #0369a1;
        }

        .btn-secondary {
            background-color: #f8fafc;
            color: #475569;
            border-color: #cbd5e1;
        }
        .btn-secondary:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }

        /* Printable Document Sheet */
        .document-sheet {
            max-width: 1100px;
            margin: 0 auto;
            background: #ffffff;
            padding: 28px 32px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
        }

        /* Hospital Header Banner */
        .hospital-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 16px;
            border-bottom: 2px solid #0284c7;
            margin-bottom: 16px;
        }

        .hospital-brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .hospital-emblem {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .hospital-title {
            font-size: 17px;
            font-weight: 800;
            color: #0c4a6e;
            letter-spacing: -0.3px;
            text-transform: uppercase;
        }

        .hospital-sub {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
            line-height: 1.3;
        }

        .report-meta-box {
            text-align: right;
            font-size: 10.5px;
            color: #334155;
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            padding: 8px 12px;
            border-radius: 8px;
            line-height: 1.5;
        }

        /* Title & Stats Summary */
        .report-title-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 14px;
        }

        .report-title-heading {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stats-pills {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 600;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: #334155;
        }

        .stat-pill.primary {
            background: #e0f2fe;
            color: #0369a1;
            border-color: #7dd3fc;
            font-weight: 700;
        }

        /* Registry Table */
        .registry-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
            margin-bottom: 20px;
        }

        .registry-table th {
            background: #0f172a;
            color: #ffffff;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9.5px;
            letter-spacing: 0.4px;
            padding: 8px 8px;
            text-align: left;
            border: 1px solid #0f172a;
            white-space: nowrap;
        }

        .registry-table td {
            padding: 7px 8px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
            color: #1e293b;
        }

        .registry-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .ipd-code {
            color: #b91c1c;
            font-weight: 700;
            font-size: 10.5px;
            white-space: nowrap;
        }

        .uhid-badge {
            background: #e0e7ff;
            color: #3730a3;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 9.5px;
            font-weight: 600;
            display: inline-block;
        }

        .patient-name-cell {
            font-weight: 700;
            color: #0f172a;
            font-size: 11px;
        }

        .patient-meta-sub {
            font-size: 9.5px;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .type-badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9.5px;
            font-weight: 700;
            display: inline-block;
            white-space: nowrap;
        }
        .type-paid {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .type-cashless {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .ward-badge {
            background: #f1f5f9;
            color: #334155;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9.5px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            display: inline-block;
        }

        /* Signatures & Footer */
        .report-footer {
            margin-top: 30px;
            padding-top: 16px;
            border-top: 1px solid #cbd5e1;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .footer-note {
            font-size: 9.5px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .signature-block {
            display: flex;
            gap: 40px;
            text-align: center;
        }

        .sig-line {
            width: 140px;
            border-top: 1px dashed #64748b;
            padding-top: 5px;
            font-size: 9.5px;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
        }

        /* Print Specific Optimization */
        @media print {
            body {
                background-color: #ffffff !important;
                padding: 0 !important;
            }

            .no-print-bar {
                display: none !important;
            }

            .document-sheet {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                max-width: 100% !important;
            }

            .registry-table {
                page-break-inside: auto;
            }

            .registry-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            .registry-table th {
                background-color: #0f172a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
            }

            @page {
                size: A4 landscape;
                margin: 8mm 8mm 8mm 8mm;
            }
        }
    </style>
</head>
<body>

    <!-- Screen Toolbar (Hidden on Print) -->
    <div class="no-print-bar">
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="regular.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Pharmacy IPD
            </a>
            <span style="font-size: 13px; font-weight: 700; color: #0f172a;">
                <i class="bi bi-file-earmark-pdf-fill text-danger me-1"></i> Inpatient Admissions PDF Census Report
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer-fill"></i> Print / Save as PDF
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
    </div>

    <!-- Printable Document Sheet -->
    <div class="document-sheet">
        
        <!-- Header -->
        <div class="hospital-header">
            <div class="hospital-brand">
                <div class="hospital-emblem">
                    <i class="bi bi-hospital"></i>
                </div>
                <div>
                    <div class="hospital-title"><?= htmlspecialchars($hospitalName) ?></div>
                    <div class="hospital-sub">
                        <?= htmlspecialchars($hospitalAddress) ?><br>
                        <strong>Phone:</strong> <?= htmlspecialchars($hospitalPhone) ?> &bull; <strong>Email:</strong> <?= htmlspecialchars($hospitalEmail) ?>
                    </div>
                </div>
            </div>
            <div class="report-meta-box">
                <div><strong>GSTIN:</strong> <?= htmlspecialchars($hospitalGstin) ?></div>
                <div><strong>Report #:</strong> <span class="font-mono"><?= htmlspecialchars($reportNo) ?></span></div>
                <div><strong>Generated:</strong> <?= htmlspecialchars($generatedAt) ?></div>
            </div>
        </div>

        <!-- Title & Filter Stats Strip -->
        <div class="report-title-strip">
            <div class="report-title-heading">
                <i class="bi bi-journal-medical text-primary"></i>
                Inpatient Admissions &amp; Bed Occupancy Register
            </div>
            <div class="stats-pills">
                <span class="stat-pill primary">
                    <i class="bi bi-people-fill"></i> Total Admitted: <strong><?= $totalCount ?></strong>
                </span>
                <span class="stat-pill">
                    <i class="bi bi-gender-ambiguous"></i> Male: <?= $maleCount ?> &bull; Female: <?= $femaleCount ?>
                </span>
                <span class="stat-pill">
                    <i class="bi bi-cash-stack"></i> Paid: <?= $paidCount ?> &bull; Cashless/TPA: <?= $cashlessCount ?>
                </span>
                <span class="stat-pill">
                    <i class="bi bi-funnel"></i> Ward: <strong><?= htmlspecialchars($wardFilter) ?></strong>
                </span>
            </div>
        </div>

        <!-- Inpatient Table -->
        <table class="registry-table">
            <thead>
                <tr>
                    <th style="width: 35px; text-align: center;">#</th>
                    <th style="width: 125px;">IPD Number</th>
                    <th style="width: 90px;">UHID Code</th>
                    <th style="width: 180px;">Patient Name &amp; Demographics</th>
                    <th style="width: 100px;">Admission Type</th>
                    <th style="width: 140px;">Attending Doctor</th>
                    <th style="width: 150px;">Ward &amp; Bed Assignment</th>
                    <th style="width: 110px;">Admission Date</th>
                    <th style="width: 75px; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($patients)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 25px; color: var(--text-muted);">
                            No inpatient records found matching the specified filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($patients as $idx => $p): 
                        $isCashless = (stripos($p['admission_type'], 'cashless') !== false || stripos($p['admission_type'], 'tpa') !== false || stripos($p['admission_type'], 'insurance') !== false || $p['is_mediclaim'] === 'yes');
                        $typeClass = $isCashless ? 'type-cashless' : 'type-paid';
                        
                        $demog = [];
                        if (!empty($p['gender'])) $demog[] = substr($p['gender'], 0, 1);
                        if (!empty($p['age'])) $demog[] = $p['age'];
                        $demogStr = !empty($demog) ? ' (' . implode('/', $demog) . ')' : '';
                    ?>
                        <tr>
                            <td style="text-align: center; font-weight: 600; color: var(--text-muted);"><?= $idx + 1 ?></td>
                            <td class="font-mono ipd-code"><?= htmlspecialchars($p['ipd_number']) ?></td>
                            <td>
                                <span class="font-mono uhid-badge"><?= htmlspecialchars($p['hospital_uhid']) ?></span>
                            </td>
                            <td>
                                <div class="patient-name-cell"><?= htmlspecialchars($p['name']) ?><?= htmlspecialchars($demogStr) ?></div>
                                <?php if (!empty($p['mobile'])): ?>
                                    <div class="patient-meta-sub"><i class="bi bi-telephone"></i> <?= htmlspecialchars($p['mobile']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="type-badge <?= $typeClass ?>"><?= htmlspecialchars($p['admission_type']) ?></span>
                            </td>
                            <td style="font-weight: 600; color: #334155;">
                                <i class="bi bi-person-badge text-primary me-0.5"></i> <?= htmlspecialchars($p['doctor_name']) ?>
                            </td>
                            <td>
                                <div class="ward-badge">
                                    <i class="bi bi-door-open text-primary me-0.5"></i> <?= htmlspecialchars($p['ipd_ward']) ?>
                                </div>
                                <div style="font-size: 9.5px; font-weight: 700; color: #0284c7; margin-top: 2px;">
                                    Bed: <?= htmlspecialchars($p['ipd_bed']) ?>
                                </div>
                            </td>
                            <td class="font-mono" style="font-size: 9.5px;"><?= htmlspecialchars($p['admission_date']) ?></td>
                            <td style="text-align: center;">
                                <span style="font-size: 9px; font-weight: 700; color: #16a34a; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 2px 6px; border-radius: 4px;">
                                    Admitted
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Footer -->
        <div class="report-footer">
            <div class="footer-note">
                <strong>Vatsalya Hospital &bull; Medical Records &amp; Inpatient Billing Department</strong><br>
                This document is a confidential inpatient census report generated for administrative, billing, and clinical operations.
            </div>
            <div class="signature-block">
                <div class="sig-line">Prepared By (Pharmacist)</div>
                <div class="sig-line">Medical Records Officer</div>
            </div>
        </div>

    </div>

    <?php if ($autoPrint): ?>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                window.print();
            }, 400);
        });
    </script>
    <?php endif; ?>

</body>
</html>
