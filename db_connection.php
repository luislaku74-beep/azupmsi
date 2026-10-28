<?php

function createPdoConnection(): PDO
{
    $driver = getenv('DB_CONNECTION') ?: 'mysql';
    $host = getenv('DB_HOST') ?: 'localhost';
    $dbname = getenv('DB_NAME') ?: 'azu_pimsi';
    $dbuser = getenv('DB_USER') ?: 'root';
    $dbpass = getenv('DB_PASSWORD') ?: '';
    $dbport = getenv('DB_PORT') ?: '3306';

    if ($driver === 'pgsql') {
        $sslmode = getenv('DB_SSLMODE') ?: 'require';
        $dsn = "pgsql:host=$host;port=$dbport;dbname=$dbname;sslmode=$sslmode";
    } elseif ($driver === 'mysql') {
        $dsn = "mysql:host=$host;port=$dbport;dbname=$dbname;charset=utf8mb4";
    } else {
        throw new RuntimeException('Unsupported database driver.');
    }

    return new PDO(
        $dsn,
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}
