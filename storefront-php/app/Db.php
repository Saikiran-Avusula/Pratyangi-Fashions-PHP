<?php
declare(strict_types=1);

namespace Pratyangi;

/** The one place a database connection is made. */
final class Db
{
    public static function connect(Config $config): \PDO
    {
        return self::connectWith($config->dbHost, $config->dbPort, $config->dbName, $config->dbUser, $config->dbPassword());
    }

    public static function connectWith(string $host, int $port, string $name, string $user, string $password): \PDO
    {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            \PDO::ATTR_TIMEOUT => 5,
            // every DATETIME is UTC; UTC_TIMESTAMP(3) and NOW(3) agree
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
        ]);
    }
}
