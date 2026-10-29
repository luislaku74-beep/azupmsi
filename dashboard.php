<?php
session_start();
require_once __DIR__ . '/db.php';

// Protect the dashboard.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$roleLabel = $role === 'admin' ? 'Administrator' : ucfirst($role);
$name = $_SESSION['full_name'] ?? 'Staff Member';

if (!in_array($role, ['admin', 'clinician', 'pharmacy', 'receptionist'], true)) {
    http_response_code(403);
    exit('Access denied. Your account role is not recognized.');
}

// Dashboard statistics.
$userCount = 0;
$patientCount = 0;
$appointmentCount = 0;

try {
    if ($role === 'admin') {
        $userCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM users'
        )->fetchColumn();
    }

    $patientCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM patients'
    )->fetchColumn();

    $appointmentCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM appointments'
    )->fetchColumn();

} catch (PDOException $e) {
    // A missing optional table should not crash the dashboard.
    error_log('Dashboard statistics: ' . $e->getMessage());
}

// Modules use the role names defined in the users table.
$modules = [
    [
        'title' => 'Patients',
        'description' => 'Register patients, search records and manage patient information.',
        'file' => 'patients.php',
        'roles' => ['admin', 'receptionist', 'clinician'],
        'icon' => '🩺',
        'category' => 'Patient Services'
    ],
    [
        'title' => 'Appointments',
        'description' => 'Schedule appointments and manage appointment status.',
        'file' => 'appointments.php',
        'roles' => ['admin', 'receptionist', 'clinician'],
        'icon' => '📅',
        'category' => 'Patient Services'
    ],
    [
        'title' => 'Medical Records',
        'description' => 'Document symptoms, diagnosis, treatment and follow-up.',
        'file' => 'medical_records.php',
        'roles' => ['admin', 'clinician'],
        'icon' => '📋',
        'category' => 'Clinical Care'
    ],
    [
        'title' => 'Medicine Inventory',
        'description' => 'Review and maintain the clinic medicine stock.',
        'file' => 'medicine.php',
        'roles' => ['admin', 'pharmacy'],
        'icon' => '💊',
        'category' => 'Pharmacy'
    ],
    [
    'title' => 'Medicine Alerts',
    'description' => 'Monitor low stock, out-of-stock medicines and medicines nearing or past expiry.',
    'file' => 'medicine_alerts.php',
    'roles' => ['admin', 'pharmacy'],
    'icon' => '⚠️',
    'category' => 'Pharmacy'
    ],
    [
        'title' => 'Medicine Dispensing',
        'description' => 'Record medicine dispensing and review dispensing activity.',
        'file' => 'dispense.php',
        'roles' => ['admin', 'pharmacy'],
        'icon' => '🧴',
        'category' => 'Pharmacy'
    ],
    [
        'title' => 'Reports',
        'description' => 'View available clinic reports and operational summaries.',
        'file' => 'reports.php',
        'roles' => ['admin'],
        'icon' => '📊',
        'category' => 'Administration'
    ],
    
    [
        'title' => 'Audit & User Activity Log',
        'description' => 'Review staff logins, logouts and recorded system activities.',
        'file' => 'audit_logs.php',
        'roles' => ['admin'],
        'icon' => '📋',
        'category' => 'Administration'
    ],

    [
        'title' => 'User Management',
        'description' => 'Manage staff accounts and their access to the system.',
        'file' => 'manage_users.php',
        'roles' => ['admin'],
        'icon' => '👥',
        'category' => 'Administration'
    ]
];

$visibleModules = [];

foreach ($modules as $module) {
    if (in_array($role, $module['roles'], true)) {
        $visibleModules[] = $module;
    }
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Dashboard | AZU Clinic PIMS</title>

    <style>
        :root {
            --navy: #202b83;
            --navy-dark: #17205f;
            --orange: #ed791c;
            --orange-light: #fff1e5;
            --background: #f4f6fb;
            --white: #ffffff;
            --text: #263247;
            --muted: #738096;
            --border: #e5e9f2;
            --shadow: 0 5px 22px rgba(32, 43, 131, 0.07);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background: var(--background);
        }

        a {
            text-decoration: none;
        }

        .topbar {
            background: var(--white);
            border-bottom: 4px solid var(--orange);
            padding: 14px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
        }

        .brand img {
            width: 245px;
            max-width: 100%;
            height: auto;
            object-fit: contain;
        }

        .brand-text {
            border-left: 1px solid var(--border);
            padding-left: 15px;
        }

        .brand-text strong {
            display: block;
            color: var(--navy);
            font-size: 16px;
        }

        .brand-text span {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .user-info {
            text-align: right;
        }

        .user-info strong {
            display: block;
            color: var(--navy);
            font-size: 14px;
        }

        .role-label {
            display: inline-block;
            margin-top: 5px;
            padding: 5px 10px;
            background: var(--orange-light);
            color: #a84e0c;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .logout {
            display: inline-block;
            padding: 10px 15px;
            border: 1px solid #f0c5c1;
            border-radius: 8px;
            color: #a52820;
            font-size: 13px;
            font-weight: bold;
            transition: 0.2s;
        }

        .logout:hover {
            background: #fff0ef;
        }

        .main {
            max-width: 1250px;
            margin: 0 auto;
            padding: 32px 24px 50px;
        }

        .welcome-banner {
            position: relative;
            overflow: hidden;
            background: linear-gradient(120deg, var(--navy-dark), var(--navy));
            color: var(--white);
            border-radius: 16px;
            padding: 32px;
            box-shadow: var(--shadow);
        }

        .welcome-banner::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border: 35px solid rgba(255, 255, 255, 0.06);
            border-radius: 50%;
            right: -55px;
            top: -95px;
        }

        .welcome-banner .eyebrow {
            color: #ffbd83;
            text-transform: uppercase;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .welcome-banner h1 {
            position: relative;
            z-index: 1;
            margin: 12px 0 8px;
            font-size: clamp(25px, 4vw, 34px);
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            max-width: 650px;
            margin: 0;
            color: #e0e5ff;
            line-height: 1.7;
            font-size: 14px;
        }

        .welcome-accent {
            width: 65px;
            height: 5px;
            margin-top: 22px;
            background: var(--orange);
            border-radius: 5px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin: 32px 0 18px;
        }

        .section-heading h2 {
            margin: 0;
            color: var(--navy);
            font-size: 21px;
        }

        .section-heading p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-top: 24px;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 22px;
            box-shadow: var(--shadow);
        }

        .stat-icon {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 50px;
            height: 50px;
            flex-shrink: 0;
            border-radius: 12px;
            background: #edf0ff;
            font-size: 23px;
        }

        .stat-card:nth-child(2) .stat-icon {
            background: var(--orange-light);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: #e7f7ef;
        }

        .stat-card span {
            display: block;
            color: var(--muted);
            font-size: 12px;
        }

        .stat-card strong {
            display: block;
            margin-top: 7px;
            color: var(--navy);
            font-size: 27px;
        }

        .module-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .module-card {
            display: flex;
            flex-direction: column;
            min-height: 235px;
            padding: 23px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow);
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        }

        .module-card:hover {
            transform: translateY(-4px);
            border-color: #c8cdef;
            box-shadow: 0 12px 28px rgba(32, 43, 131, 0.12);
        }

        .module-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .module-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 54px;
            height: 54px;
            border-radius: 13px;
            background: #edf0ff;
            font-size: 27px;
        }

        .module-card:nth-child(even) .module-icon {
            background: var(--orange-light);
        }

        .category {
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: right;
        }

        .module-card h3 {
            margin: 20px 0 9px;
            color: var(--navy);
            font-size: 17px;
        }

        .module-card p {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.65;
        }

        .module-link {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid var(--border);
            color: var(--navy);
            font-size: 13px;
            font-weight: bold;
        }

        .module-link span {
            color: var(--orange);
            font-size: 20px;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            color: var(--muted);
            text-align: center;
            font-size: 12px;
            line-height: 1.8;
        }

        .footer strong {
            color: var(--navy);
        }

        @media (max-width: 900px) {
            .module-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .topbar {
                padding: 15px 18px;
            }

            .brand {
                align-items: flex-start;
                flex-direction: column;
                gap: 8px;
            }

            .brand img {
                width: 230px;
            }

            .brand-text {
                border-left: 0;
                padding-left: 0;
            }

            .user-info {
                text-align: left;
            }

            .main {
                padding: 22px 15px 35px;
            }

            .welcome-banner {
                padding: 25px 22px;
            }

            .module-grid,
            .stats {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">
        <img src="azu-logo.png"
             alt="Africa Zeal University logo"
             onerror="this.style.display='none'">

        <div class="brand-text">
            <strong>AZU Clinic PIMS</strong>
            <span>Patient Information Management System</span>
        </div>
    </div>

    <div class="top-actions">
        <div class="user-info">
            <strong><?= h($name) ?></strong>
            <span class="role-label"><?= h($role) ?></span>
        </div>

        <a class="logout" href="logout.php">Sign Out ↗</a>
    </div>
</header>

<main class="main">

    <section class="welcome-banner">
        <div class="eyebrow">Africa Zeal University Clinic</div>

        <h1>Welcome back, <?= h($roleLabel) ?>!</h1>

        <p>
            Manage clinic operations, patient information, appointments,
            medical records and other authorized services from one place.
        </p>

        <div class="welcome-accent"></div>
    </section>

    <?php if ($role === 'admin'): ?>
        <section class="stats">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div>
                    <span>Staff Accounts</span>
                    <strong><?= $userCount ?></strong>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🩺</div>
                <div>
                    <span>Registered Patients</span>
                    <strong><?= $patientCount ?></strong>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div>
                    <span>Total Appointments</span>
                    <strong><?= $appointmentCount ?></strong>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section>
        <div class="section-heading">
            <div>
                <h2>Clinic Modules</h2>
                <p>Choose a module to access its available functions.</p>
            </div>
        </div>

        <div class="module-grid">
            <?php foreach ($visibleModules as $module): ?>
                <article class="module-card">
                    <div class="module-top">
                        <div class="module-icon">
                            <?= h($module['icon']) ?>
                        </div>

                        <span class="category">
                            <?= h($module['category']) ?>
                        </span>
                    </div>

                    <h3><?= h($module['title']) ?></h3>

                    <p><?= h($module['description']) ?></p>

                    <a class="module-link"
                       href="<?= h($module['file']) ?>">
                        <div>Open Module</div>
                        <span>→</span>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <footer class="footer">
        <strong>Africa Zeal University Clinic</strong><br>
        Patient Information Management System<br>
        Authorized staff access only
    </footer>

</main>

</body>
</html>
