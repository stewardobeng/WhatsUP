<?php
declare(strict_types=1);
namespace WhatsUP\Core;
use PDO;
final class Database {
    private static ?PDO $pdo=null; private static string $dsn=''; private static string $user=''; private static string $password='';
    public static function configure(string $dsn,string $user,string $password): void { self::$dsn=$dsn; self::$user=$user; self::$password=$password; }
    public static function connection(): PDO { if (!self::$pdo) { self::$pdo=new PDO(self::$dsn,self::$user,self::$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"]); self::$pdo->exec("SET time_zone = '+00:00'"); } return self::$pdo; }
    public static function transaction(callable $callback): mixed { $db=self::connection(); $db->beginTransaction(); try {$result=$callback($db);$db->commit();return $result;} catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;} }
}
