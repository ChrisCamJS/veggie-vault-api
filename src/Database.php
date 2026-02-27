<?php
namespace App;

class Database {
    private static $pdo;

    public static function connect() {
        if (!self::$pdo) {
            // In reality, parse your .env file here
            $host = 'localhost'; 
            $db   = 'pantry_ce_show_live';
            $user = 'root';
            $pass = 'mysql';
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $options = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            try {
                 self::$pdo = new \PDO($dsn, $user, $pass, $options);
            } catch (\PDOException $e) {
                 http_response_code(500);
                 echo json_encode(["error" => "Database connection failed"]);
                 exit;
            }
        }
        return self::$pdo;
    }
}