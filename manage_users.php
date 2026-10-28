
<?php
session_start();
require_once __DIR__ . '/db.php';

// Only administrators may manage user accounts.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can manage staff accounts.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$allowedRoles = ['admin', 'clinician', 'pharmacy', 'receptionist'];
$message = '';
$messageType = 'success';

// Process form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $message = 'Your session expired. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            // Create a new staff account.
            if ($action === 'create') {
                $fullName = trim($_POST['full_name'] ?? '');
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? '';

                if ($fullName === '' || $username === '' || $password === '') {
                    throw new Exception('Please complete all required fields.');
                }

                if (strlen($username) > 50) {
                    throw new Exception('Username cannot exceed 50 characters.');
                }

                if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                    throw new Exception(
                        'Username may contain letters, numbers, dots, underscores and hyphens only.'
                    );
                }

                if (strlen($password) < 12) {
                    throw new Exception('Password must contain at least 12 characters.');
                }

                if (!in_array($role, $allowedRoles, true)) {
                    throw new Exception('Please select a valid staff role.');
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    'INSERT INTO users
                        (full_name, username, password_hash, role, is_active)
                     VALUES
                        (:full_name, :username, :password_hash, :role, 1)'
                );

                $stmt->execute([
                    'full_name' => $fullName,
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'role' => $role
                ]);

                $message = 'Staff account created successfully.';
            }

            // Change an existing user's role.
            elseif ($action === 'change_role') {
                $targetId = filter_var(
                    $_POST['user_id'] ?? null,
                    FILTER_VALIDATE_INT
                );
                $newRole = $_POST['role'] ?? '';

                if (!$targetId || !in_array($newRole, $allowedRoles, true)) {
                    throw new Exception('Invalid user or role.');
                }

                if ($targetId === (int)$_SESSION['user_id']) {
                    throw new Exception('You cannot change your own role.');
                }

                $stmt = $pdo->prepare(
                    'UPDATE users SET role = :role WHERE id = :id'
                );
                $stmt->execute([
                    'role' => $newRole,
                    'id' => $targetId
                ]);

                $message = 'Staff role updated successfully.';
            }

            // Activate or deactivate a user.
            elseif ($action === 'toggle_status') {
                $targetId = filter_var(
                    $_POST['user_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $newStatus = (string)($_POST['new_status'] ?? '');

                if (!$targetId || !in_array($newStatus, ['0', '1'], true)) {
                    throw new Exception('Invalid user or account status.');
                }

                if ($targetId === (int)$_SESSION['user_id']) {
                    throw new Exception('You cannot deactivate your own account here.');
                }

                $stmt = $pdo->prepare(
                    'UPDATE users SET is_active = :status WHERE id = :id'
                );
                $stmt->execute([
                    'status' => (int)$newStatus,
                    'id' => $targetId
                ]);

                $message = $newStatus === '1'
                    ? 'Staff account activated.'
                    : 'Staff account deactivated.';
            }

            // Reset a user's password.
            elseif ($action === 'reset_password') {
                $targetId = filter_var(
                    $_POST['user_id'] ?? null,
                    FILTER_VALIDATE_INT
                );
                $newPassword = $_POST['new_password'] ?? '';

                if (!$targetId) {
                    throw new Exception('Invalid user.');
                }

                if (strlen($newPassword) < 12) {
                    throw new Exception('New password must contain at least 12 characters.');
                }

                if ($targetId === (int)$_SESSION['user_id']) {
                    throw new Exception(
                        'Use a dedicated account password-change process to change your own password.'
                    );
                }

                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    'UPDATE users SET password_hash = :hash WHERE id = :id'
                );
                $stmt->execute([
                    'hash' => $passwordHash,
                    'id' => $targetId
                ]);

                $message = 'Password reset successfully. Give the new password securely to the staff member.';
            } else {
                throw new Exception('Unknown action.');
            }
        } catch (PDOException $e) {
            error_log('User management error: ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $message = 'That username already exists. Choose a different username.';
            } else {
                $message = 'A database error occurred. Please check the server error log.';
            }

            $messageType = 'error';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Load accounts for display.
$stmt = $pdo->query(
    'SELECT id, full_name, username, role, is_active, created_at
     FROM users
     ORDER BY id DESC'
);
$users = $stmt->fetchAll();

$totalUsers = count($users);
$activeUsers = 0;
$inactiveUsers = 0;

foreach ($users as $user) {
    if ((int)$user['is_active'] === 1) {
        $activeUsers++;
    } else {
        $inactiveUsers++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>User Management | AZU Clinic PIMS</title>

<style>
:root {
    --navy: #202b83;
    --navy-dark: #17205f;
    --orange: #ed791c;
    --background: #f4f6fb;
    --white: #fff;
    --text: #263247;
    --muted: #738096;
    --border: #e5e9f2;
    --green: #167647;
    --red: #b42318;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family: Arial, Helvetica, sans-serif;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
    padding: 18px 5%;
    background: var(--white);
    border-bottom: 4px solid var(--orange);
}

.brand {
    color: var(--navy);
    font-size: 21px;
    font-weight: 800;
}

.brand span {
    display: block;
    margin-top: 5px;
    color: var(--muted);
    font-size: 12px;
    font-weight: normal;
}

.top-links {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.top-links a {
    color: var(--navy);
    text-decoration: none;
    font-weight: bold;
    font-size: 13px;
}

.container {
    max-width: 1250px;
    margin: 30px auto;
    padding: 0 22px 40px;
}

.page-heading h1 {
    margin: 0;
    color: var(--navy);
    font-size: 29px;
}

.page-heading p {
    color: var(--muted);
    line-height: 1.6;
}

.summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin: 25px 0;
}

.summary-card, .panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 4px 18px #202b830a;
}

.summary-card {
    padding: 20px;
}

.summary-card span {
    color: var(--muted);
    font-size: 13px;
}

.summary-card strong {
    display: block;
    margin-top: 8px;
    color: var(--navy);
    font-size: 27px;
}

.panel {
    margin-top: 22px;
    padding: 24px;
}

.panel h2 {
    margin: 0 0 20px;
    color: var(--navy);
    font-size: 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.field label {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
    font-weight: bold;
    color: var(--navy);
}

.field input, .field select {
    width: 100%;
    padding: 12px;
    border: 1px solid #d7deeb;
    border-radius: 7px;
    background: white;
    font-size: 14px;
}

.field input:focus, .field select:focus {
    outline: 2px solid #dce1ff;
    border-color: var(--navy);
}

.hint {
    display: block;
    margin-top: 6px;
    color: var(--muted);
    font-size: 11px;
}

.button {
    border: 0;
    border-radius: 7px;
    padding: 11px 15px;
    background: var(--navy);
    color: white;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
}

.button:hover { background: var(--orange); }

.button-orange { background: var(--orange); }
.button-danger { background: #fff0ef; color: var(--red); }
.button-danger:hover { background: #fbdad7; }
.button-green { background: #e7f7ef; color: var(--green); }
.button-green:hover { background: #c9efda; }

.form-actions {
    margin-top: 18px;
}

.message {
    margin: 18px 0;
    padding: 14px;
    border-radius: 8px;
    line-height: 1.5;
}

.message.success {
    background: #e7f7ef;
    color: #17633c;
}

.message.error {
    background: #fff0ef;
    color: #a52820;
}

.table-wrap { overflow-x: auto; }

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th, td {
    padding: 13px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
    font-size: 13px;
}

th {
    color: var(--navy);
    background: #f7f8fc;
    font-size: 12px;
}

td strong { color: var(--text); }

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.status.active { background: #e7f7ef; color: var(--green); }
.status.inactive { background: #fff0ef; color: var(--red); }

.role-form, .status-form {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.role-form select {
    padding: 7px;
    border: 1px solid var(--border);
    border-radius: 6px;
}

.small-button {
    padding: 7px 9px;
    font-size: 11px;
}

.action-details {
    margin-top: 10px;
}

.action-details summary {
    color: var(--navy);
    font-size: 12px;
    font-weight: bold;
    cursor: pointer;
    margin: 8px 0;
}

.action-details input {
    width: 190px;
    max-width: 100%;
    padding: 8px;
    margin: 4px 0;
    border: 1px solid var(--border);
    border-radius: 6px;
}

.inline-form { margin: 0; }

.footer {
    margin-top: 35px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
    color: var(--muted);
    text-align: center;
    font-size: 12px;
}

@media (max-width: 700px) {
    .summary, .form-grid { grid-template-columns: 1fr; }
    .container { padding: 0 14px 30px; }
    .panel { padding: 17px; }
    .page-heading h1 { font-size: 25px; }
}
</style>
</head>

<body>

<header class="topbar">
    <div class="brand">
        AZU Clinic PIMS
        <span>Africa Zeal University Clinic · Staff Administration</span>
    </div>

    <nav class="top-links">
        <a href="dashboard.php">← Dashboard</a>
        <a href="logout.php">Sign Out</a>
    </nav>
</header>

<main class="container">

    <div class="page-heading">
        <h1>User Management</h1>
        <p>Create staff accounts, assign access roles and manage account status.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message <?= h($messageType) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <section class="summary">
        <div class="summary-card">
            <span>Total staff accounts</span>
            <strong><?= $totalUsers ?></strong>
        </div>
        <div class="summary-card">
            <span>Active accounts</span>
            <strong><?= $activeUsers ?></strong>
        </div>
        <div class="summary-card">
            <span>Inactive accounts</span>
            <strong><?= $inactiveUsers ?></strong>
        </div>
    </section>

    <section class="panel">
        <h2>Create Staff Account</h2>

        <form method="POST" action="manage_users.php">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div class="field">
                    <label for="full_name">Staff full name</label>
                    <input id="full_name" name="full_name" maxlength="150" required>
                </div>

                <div class="field">
                    <label for="username">Username</label>
                    <input id="username" name="username" maxlength="50"
                           pattern="[a-zA-Z0-9_.-]+" autocomplete="off" required>
                    <span class="hint">Letters, numbers, dots, underscores and hyphens.</span>
                </div>

                <div class="field">
                    <label for="password">Temporary password</label>
                    <input type="password" id="password" name="password"
                           minlength="12" autocomplete="new-password" required>
                    <span class="hint">Use at least 12 characters.</span>
                </div>

                <div class="field">
                    <label for="role">Staff role</label>
                    <select id="role" name="role" required>
                        <option value="receptionist">Receptionist</option>
                        <option value="clinician">Clinician</option>
                        <option value="pharmacy">Pharmacy</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">+ Create Staff Account</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Staff Accounts</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Staff Member</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Manage</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= (int)$user['id'] ?></td>
                        <td><strong><?= h($user['full_name']) ?></strong></td>
                        <td><?= h($user['username']) ?></td>
                        <td>
                            <?php if ((int)$user['id'] === (int)$_SESSION['user_id']): ?>
                                <span class="status active"><?= h($user['role']) ?> · You</span>
                            <?php else: ?>
                                <form class="role-form" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="change_role">
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                                    <select name="role" aria-label="Role for <?= h($user['username']) ?>">
                                        <?php foreach ($allowedRoles as $option): ?>
                                            <option value="<?= h($option) ?>"
                                                <?= $user['role'] === $option ? 'selected' : '' ?>>
                                                <?= h(ucfirst($option)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button class="button small-button" type="submit">Save</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status <?= (int)$user['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                <?= (int)$user['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td><?= h($user['created_at']) ?></td>
                        <td>
                            <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                                <form class="status-form" method="POST"
                                      onsubmit="return confirm('Change this account status?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                    <input type="hidden" name="new_status"
                                           value="<?= (int)$user['is_active'] === 1 ? '0' : '1' ?>">

                                    <button class="button small-button <?= (int)$user['is_active'] === 1 ? 'button-danger' : 'button-green' ?>"
                                            type="submit">
                                        <?= (int)$user['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>

                                <details class="action-details">
                                    <summary>Reset password</summary>
                                    <form method="POST"
                                          onsubmit="return confirm('Reset this staff member password?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                                        <input type="password" name="new_password"
                                               minlength="12" placeholder="New password"
                                               autocomplete="new-password" required>

                                        <button class="button button-orange small-button" type="submit">
                                            Reset
                                        </button>
                                    </form>
                                </details>
                            <?php else: ?>
                                <span class="hint">Current account</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$users): ?>
                    <tr>
                        <td colspan="7">No staff accounts found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="footer">
        Africa Zeal University Clinic · Patient Information Management System
    </footer>

</main>
</body>
</html>
