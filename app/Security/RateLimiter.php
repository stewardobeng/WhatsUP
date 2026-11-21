<?php
declare(strict_types=1);
namespace WhatsUP\Security;
use PDO;
final class RateLimiter {
    public function __construct(private readonly PDO $pdo) {}
    public function attempt(string $key,int $maxAttempts=5,int $windowSeconds=900): bool {
        $bucket=hash('sha256',$key); $maxAttempts=max(1,$maxAttempts); $windowSeconds=max(1,$windowSeconds);
        $own=!$this->pdo->inTransaction(); if($own)$this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO rate_limits(bucket_key,attempts,window_started) VALUES(?,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)')->execute([$bucket]);
            $q=$this->pdo->prepare('SELECT attempts,window_started,UNIX_TIMESTAMP(window_started) AS started,blocked_until FROM rate_limits WHERE bucket_key=? FOR UPDATE'); $q->execute([$bucket]); $row=$q->fetch(); $now=time();
            $this->pdo->prepare('DELETE FROM rate_limits WHERE window_started < UTC_TIMESTAMP() - INTERVAL 1 DAY')->execute();
            $allowed=true;
            if($row['blocked_until'] && strtotime((string)$row['blocked_until'])>$now) $allowed=false;
            elseif($now-(int)$row['started']>$windowSeconds){$this->pdo->prepare('UPDATE rate_limits SET attempts=1,window_started=UTC_TIMESTAMP(),blocked_until=NULL WHERE bucket_key=?')->execute([$bucket]);}
            else{$attempts=(int)$row['attempts']+1;if($attempts>$maxAttempts){$this->pdo->prepare('UPDATE rate_limits SET attempts=?,blocked_until=UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE bucket_key=?')->execute([$attempts,$windowSeconds,$bucket]);$allowed=false;}else{$this->pdo->prepare('UPDATE rate_limits SET attempts=? WHERE bucket_key=?')->execute([$attempts,$bucket]);}}
            if($own)$this->pdo->commit(); return $allowed;
        }catch(\Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function clear(string $key): void { $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket_key=?')->execute([hash('sha256',$key)]); }
    public function retryAfter(string $key): int { $q=$this->pdo->prepare('SELECT GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),blocked_until)) FROM rate_limits WHERE bucket_key=?'); $q->execute([hash('sha256',$key)]); return (int)$q->fetchColumn(); }
}
