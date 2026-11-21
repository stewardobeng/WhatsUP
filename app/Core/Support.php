<?php
declare(strict_types=1);
namespace WhatsUP\Core;
use PDO;
final class Support {
    public static function db(): PDO { return Database::connection(); }
    public static function id(): string { return bin2hex(random_bytes(16)); }
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function paginate(int $page,int $perPage=25): array { $perPage=max(1,min(100,$perPage));$page=max(1,$page);return ['limit'=>$perPage,'offset'=>($page-1)*$perPage,'page'=>$page]; }
    public static function audit(PDO $db,?int $actor,string $action,string $targetType,?string $targetId,array $metadata=[]):void { $s=$db->prepare('INSERT INTO audit_logs(actor_id,action,target_type,target_id,metadata_json,ip_hash,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())');$s->execute([$actor,$action,$targetType,$targetId,json_encode($metadata,JSON_INVALID_UTF8_SUBSTITUTE),hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'cli'))]); }
}
