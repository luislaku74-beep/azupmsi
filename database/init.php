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
