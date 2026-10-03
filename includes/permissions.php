<?php
// includes/permissions.php
// Centralized permission verification and access control helpers

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Check if the currently logged-in user has a specific permission.
 * Admins are automatically granted all permissions.
 *
 * @param string $permission_key
 * @return bool
 */
function has_permission($permission_key)
{
    $role = strtolower(trim($_SESSION['role'] ?? ''));

    // Admins and Pharmacy roles have full access to all pharmacy features
    if (in_array($role, ['admin', 'pharmacist', 'pharmacy'])) {
        return true;
    }

    // Billing roles have full access to all Laboratory module features
    if (in_array($role, ['billing', 'billing executive', 'accountant']) && (strpos($permission_key, 'lab') !== false || in_array($permission_key, ['view_lab_orders', 'add_lab_order', 'enter_results', 'view_lab_reports', 'edit_lab_items', 'view_patient_lab_history', 'view_pending_lab', 'view_lab_dashboard']))) {
        return true;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM role_permissions rp
            JOIN roles r ON rp.role_id = r.role_id
            JOIN permissions p ON rp.permission_id = p.permission_id
            WHERE r.role_name = ? AND r.status = 'Active' AND p.permission_key = ?
        ");
        $stmt->execute([$_SESSION['role'] ?? '', $permission_key]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Enforce a permission requirement. Renders Access Denied if user lacks permission.
 *
 * @param string $permission_key
 */
function require_permission($permission_key)
{
    require_login();

    if (!has_permission($permission_key)) {
        http_response_code(403);
        
        $role = $_SESSION['role'] ?? '';
        $dashboard_url = get_dashboard_redirect_url($role);
        
        // Render a premium Access Denied page
        $page_title = 'Access Denied';
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Denied</title>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
            <?php if ($role === 'Billing Executive'): ?>
                <meta http-equiv="refresh" content="5;url=<?= htmlspecialchars($dashboard_url) ?>">
            <?php endif; ?>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    background: radial-gradient(circle at center, #ffffff 0%, #f8fafc 100%);
                    font-family: 'Inter', sans-serif;
                    overflow-x: hidden;
                    overflow-y: auto;
                    padding: 3rem 1.5rem;
                    position: relative;
                }
                body::before, body::after {
                    content: '';
                    position: absolute;
                    width: 120px;
                    height: 120px;
                    opacity: 0.25;
                    background-image: radial-gradient(#94a3b8 2px, transparent 2px);
                    background-size: 16px 16px;
                }
                body::before { top: 10%; left: 5%; }
                body::after { bottom: 10%; right: 5%; }
                .container {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    text-align: center;
                    max-width: 600px;
                    padding: 2rem;
                    animation: fadeIn 0.6s ease-out;
                }
                .illustration-wrapper {
                    margin-bottom: 2rem;
                    animation: float 4s ease-in-out infinite;
                }
                .title {
                    font-size: 2.25rem;
                    font-weight: 800;
                    color: #1e293b;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin-bottom: 1rem;
                }
                .title span { color: #ef4444; }
                .subtitle {
                    font-size: 0.95rem;
                    color: #64748b;
                    line-height: 1.6;
                    max-width: 440px;
                    margin: 0 auto;
                }
                .divider {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    width: 160px;
                    height: 2px;
                    background: linear-gradient(90deg, transparent 0%, #ef4444 50%, transparent 100%);
                    position: relative;
                    margin: 1.5rem auto;
                }
                .divider::after {
                    content: '';
                    position: absolute;
                    width: 6px;
                    height: 6px;
                    background: #ef4444;
                    border-radius: 50%;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                }
                .info-card {
                    display: flex;
                    align-items: flex-start;
                    text-align: left;
                    background: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 12px;
                    padding: 1.25rem 1.5rem;
                    width: 100%;
                    max-width: 440px;
                    margin-bottom: 2rem;
                    gap: 1rem;
                    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
                }
                .info-icon { flex-shrink: 0; margin-top: 2px; }
                .info-content h4 {
                    font-size: 0.9rem;
                    font-weight: 600;
                    color: #1e293b;
                    margin-bottom: 0.25rem;
                }
                .info-content p {
                    font-size: 0.8rem;
                    color: #64748b;
                    line-height: 1.4;
                }
                .btn-goback {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 0.5rem;
                    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                    color: white;
                    font-size: 0.9rem;
                    font-weight: 600;
                    text-decoration: none;
                    padding: 0.75rem 2.5rem;
                    border-radius: 8px;
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2), 0 2px 4px -1px rgba(239, 68, 68, 0.1);
                    transition: all 0.2s ease-in-out;
                }
                .btn-goback:hover {
                    background: linear-gradient(135deg, #f87171 0%, #dc2626 100%);
                    transform: translateY(-1px);
                    box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.3), 0 4px 6px -2px rgba(239, 68, 68, 0.15);
                }
                @keyframes float {
                    0%, 100% { transform: translateY(0px) rotate(0deg); }
                    50% { transform: translateY(-10px) rotate(1deg); }
                }
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="illustration-wrapper">
                    <svg width="220" height="220" viewBox="0 0 280 280" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="140" cy="140" r="90" fill="#EF4444" filter="url(#shadow)"/>
                        <rect x="105" y="132" width="70" height="52" rx="12" fill="white" />
                        <path d="M117 132V110C117 97.2974 127.297 87 140 87C152.703 87 163 97.2974 163 110V132" stroke="white" stroke-width="9" stroke-linecap="round"/>
                        <circle cx="140" cy="152" r="7" fill="#EF4444"/>
                        <path d="M140 159V170" stroke="#EF4444" stroke-width="5" stroke-linecap="round"/>
                        <circle cx="198" cy="198" r="28" fill="white" filter="url(#shadow-small)"/>
                        <circle cx="198" cy="198" r="22" fill="white"/>
                        <path d="M188 188L208 208M208 188L188 208" stroke="#EF4444" stroke-width="5" stroke-linecap="round"/>
                        <defs>
                            <filter id="shadow" x="10" y="10" width="260" height="260" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                                <feDropShadow dx="0" dy="16" stdDeviation="20" flood-color="#EF4444" flood-opacity="0.3"/>
                            </filter>
                            <filter id="shadow-small" x="160" y="160" width="76" height="76" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                                <feDropShadow dx="0" dy="6" stdDeviation="8" flood-color="#000000" flood-opacity="0.12"/>
                            </filter>
                        </defs>
                    </svg>
                </div>
                <h1 class="title">Access <span>Denied</span></h1>
                <p class="subtitle">You do not have permission to access this page or the resource you are trying to view.</p>
                <div class="divider"></div>
                <div class="info-card">
                    <div class="info-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 22C12 22 20 18 20 12V5L12 2L4 5V12C4 18 12 22 12 22Z" stroke="#EF4444" stroke-width="2" stroke-linejoin="round"/>
                            <path d="M12 8V13" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="16" r="1.5" fill="#EF4444"/>
                        </svg>
                    </div>
                    <div class="info-content">
                        <h4>Required Permission</h4>
                        <p>This action requires the <code><?= htmlspecialchars($permission_key) ?></code> permission.
                        <?php if ($role === 'Billing Executive'): ?>
                            Redirecting to your dashboard in 5 seconds...
                        <?php else: ?>
                            Please contact the administrator if you believe this is an error.
                        <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($role === 'Billing Executive'): ?>
                    <a href="<?= htmlspecialchars($dashboard_url) ?>" class="btn-goback">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="9"></rect>
                            <rect x="14" y="3" width="7" height="5"></rect>
                            <rect x="14" y="12" width="7" height="9"></rect>
                            <rect x="3" y="16" width="7" height="5"></rect>
                        </svg>
                        GO TO BILLING DASHBOARD
                    </a>
                <?php else: ?>
                    <button onclick="history.back()" class="btn-goback">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                        GO BACK
                    </button>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

/**
 * Load the active user's permissions from the database into the session.
 */
function load_user_permissions()
{
    global $pdo;

    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        $_SESSION['permissions'] = [];
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.permission_key 
            FROM role_permissions rp
            JOIN roles r ON rp.role_id = r.role_id
            JOIN permissions p ON rp.permission_id = p.permission_id
            WHERE r.role_name = ? AND r.status = 'Active'
        ");
        $stmt->execute([$_SESSION['role']]);
        $_SESSION['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        $_SESSION['permissions'] = [];
    }
}
