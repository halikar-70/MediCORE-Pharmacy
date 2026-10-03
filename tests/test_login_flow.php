<?php
// tests/test_login_flow.php - Simulate Full HTTP Web Login & Redirect

$baseUrl = rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8000', '/');

$ch = curl_init($baseUrl . '/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, __DIR__ . '/cookie.txt');
$response = curl_exec($ch);
curl_close($ch);

// Extract CSRF token
preg_match('/name="csrf_token" value="([^"]+)"/', $response, $matches);
if (empty($matches[1])) {
    die("Failed to extract CSRF token from login page.\n");
}
$csrfToken = $matches[1];
echo "Extracted CSRF: {$csrfToken}\n";

// POST Login
$ch = curl_init($baseUrl . '/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_token' => $csrfToken,
    'username'   => 'admin',
    'password'   => 'Admin@12345'
]));
curl_setopt($ch, CURLOPT_COOKIEFILE, __DIR__ . '/cookie.txt');
curl_setopt($ch, CURLOPT_COOKIEJAR, __DIR__ . '/cookie.txt');
$postResp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "POST Login HTTP Status: {$httpCode}\n";
if (preg_match('/Location:\s*([^\r\n]+)/i', $postResp, $locMatch)) {
    echo "Redirect Location: " . trim($locMatch[1]) . "\n";
} else {
    echo "No redirect location found. Response excerpt:\n" . substr($postResp, 0, 300) . "\n";
}

// Access Dashboard using authenticated cookie
$ch = curl_init($baseUrl . '/modules/dashboard/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, __DIR__ . '/cookie.txt');
$dashResp = curl_exec($ch);
$dashCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Dashboard Access HTTP Status: {$dashCode}\n";
if (strpos($dashResp, 'Pharmacy Dashboard') !== false) {
    echo "[SUCCESS] Authenticated dashboard access verified successfully!\n";
} else {
    echo "[FAIL] Dashboard did not contain expected content.\n";
}

@unlink(__DIR__ . '/cookie.txt');
