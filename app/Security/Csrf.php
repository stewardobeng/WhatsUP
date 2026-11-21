<?php
declare(strict_types=1);
namespace WhatsUP\Security;
final class Csrf {
    public static function token(): string { if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) $_SESSION['_csrf']=bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
    public static function verify(mixed $token): bool { $expected=$_SESSION['_csrf']??''; return is_string($token) && is_string($expected) && $expected!=='' && hash_equals($expected,$token); }
    public static function rotate(): void { unset($_SESSION['_csrf']); self::token(); }
}
