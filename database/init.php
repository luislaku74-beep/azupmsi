<?php

require_once dirname(__DIR__) . '/db_connection.php';

$pdo = null;

for ($attempt = 0; $attempt < 30; $attempt++) {
    try {
        $pdo = createPdoConnection();
        break;
    } catch (PDOException $e) {
        if ($attempt === 29) {
            throw $e;
        }

        sleep(1);
    }
}

if (!$pdo instanceof PDO) {
    throw new RuntimeException('Could not establish a database connection.');
}

$schema = file_get_contents(__DIR__ . '/schema.sql');

if ($schema === false) {
    throw new RuntimeException('Could not read the database schema.');
}

$statements = preg_split('/;[ \t]*(?:\r?\n|$)/', trim($schema));

if ($statements === false) {
    throw new RuntimeException('Could not parse the database schema.');
}

foreach ($statements as $statement) {
    $statement = trim($statement);

    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$adminUsername = getenv('ADMIN_USERNAME');
$adminPassword = getenv('ADMIN_PASSWORD');

if ($adminUsername === false && $adminPassword === false) {
    fwrite(STDOUT, "Admin bootstrap skipped: ADMIN_USERNAME and ADMIN_PASSWORD are not configured.\n");
} else {
    $adminUsername = is_string($adminUsername) ? trim($adminUsername) : '';

    if ($adminUsername === '' || strlen($adminUsername) > 50
        || !is_string($adminPassword) || strlen($adminPassword) < 12) {
        throw new RuntimeException(
            'Admin bootstrap requires ADMIN_USERNAME (1-50 characters) and ADMIN_PASSWORD (at least 12 characters).'
        );
    }

    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    if ($userCount === 0) {
        $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new RuntimeException('Could not hash the bootstrap administrator password.');
        }

        $statement = $pdo->prepare(
            "INSERT INTO users (full_name, username, password_hash, role, is_active)
             VALUES ('Administrator', :username, :password_hash, 'admin', 1)"
        );
        $statement->execute([
            'username' => $adminUsername,
            'password_hash' => $passwordHash,
        ]);

        fwrite(STDOUT, "Bootstrap administrator created.\n");
    } else {
        fwrite(STDOUT, "Admin bootstrap skipped: the users table is not empty.\n");
    }
}
