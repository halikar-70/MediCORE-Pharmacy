<?php
// config/constants.php - Pharmacy Management System

define('APP_NAME', 'Pharmacy Management System');
define('APP_SHORT_NAME', 'PMS');
define('BASE_URL', '');

define('UPLOAD_REPORTS', __DIR__ . '/../uploads/reports/');
define('UPLOAD_PATIENT_DOCS', __DIR__ . '/../uploads/patient_documents/');
define('UPLOAD_DISCHARGE', __DIR__ . '/../uploads/discharge_summaries/');

define('ROLES', [
    'admin',
    'pharmacist',
    'pharmacy'
]);

date_default_timezone_set('Asia/Kolkata');
