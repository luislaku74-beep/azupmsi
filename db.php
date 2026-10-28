
<?php

require_once __DIR__ . '/db_connection.php';

try {
    $pdo = createPdoConnection();
} catch (PDOException $e) {
    error_log($e->getMessage());
    exit('Database connection failed. Check your database settings.');
}
