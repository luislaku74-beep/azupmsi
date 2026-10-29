<?php
require_once 'db.php';
require_once __DIR__ . '/toast.php';

$message = '';

try {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (PDOException $e) {
    exit("Could not check users table.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($count > 0) {
        $message = 'A user already exists. Remove this setup file and use your login page.';
    } elseif ($full_name === '' || $username === '' || $password === '') {
        $message = 'Please fill in all fields.';
    } elseif (strlen($password) < 12) {
        $message = 'Password must be at least 12 characters.';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match.';
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO users
                    (full_name, username, password_hash, role, is_active)
                 VALUES
                    (:full_name, :username, :password_hash, 'admin', 1)"
            );

            $stmt->execute([
                'full_name' => $full_name,
                'username' => $username,
                'password_hash' => $password_hash
            ]);

            $message = 'Administrator account created. Delete create_admin.php now, then log in.';
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $message = 'Could not create account. Check whether the username already exists.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Administrator - AZU PIMS</title>
    <link rel="stylesheet" href="toast.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f0f4f8;
            margin: 0;
            padding: 30px 15px;
        }
        .container {
            max-width: 430px;
            margin: 40px auto;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px #0001;
        }
        h2 { color: #174a70; text-align: center; }
        label { display: block; margin-top: 15px; }
        input {
            box-sizing: border-box;
            width: 100%;
            padding: 11px;
            margin-top: 6px;
        }
        button {
            width: 100%;
            margin-top: 20px;
            padding: 12px;
            background: #174a70;
            color: white;
            border: 0;
            cursor: pointer;
        }
        .message {
            padding: 10px;
            background: #eef4f8;
            overflow-wrap: anywhere;
        }
    </style>
</head>
<body>
<?php
renderToastMessages(
    $message !== ''
        ? [[
            'message' => $message,
            'type' => strpos($message, 'Administrator account created') === 0
                ? 'success'
                : 'error'
        ]]
        : []
);
?>
<div class="container">
    <h2>AZU PIMS Administrator Setup</h2>

    <?php if ($count === 0 && strpos($message, 'Administrator account created') === false): ?>
        <form method="POST">
            <label>Full name</label>
            <input type="text" name="full_name" required>

            <label>Username</label>
            <input type="text" name="username" required autocomplete="username">

            <label>Password (at least 12 characters)</label>
            <input type="password" name="password" required minlength="12"
                   autocomplete="new-password">

            <label>Confirm password</label>
            <input type="password" name="confirm_password" required minlength="12"
                   autocomplete="new-password">

            <button type="submit">Create Administrator</button>
        </form>
    <?php endif; ?>
</div>
<script src="toast.js" defer></script>
</body>
</html>
