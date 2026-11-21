<?php
declare(strict_types=1);

use WhatsUP\Core\Config;
use WhatsUP\Core\Database;

require_once __DIR__ . '/Core/Config.php';
require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Core/Support.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'WhatsUP\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});

Config::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', Config::bool('SESSION_SECURE', false) ? '1' : '0');
    session_name('whatsup_session');
    session_start();
}
Database::configure(Config::get('DB_DSN', 'mysql:host=127.0.0.1;dbname=whatsup;charset=utf8mb4'), Config::get('DB_USER', 'root'), Config::get('DB_PASSWORD', ''));

function env(string $key, ?string $default = null): ?string { return Config::get($key, $default); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = ''): string { return rtrim((string)env('APP_URL', '/WhatsUP'), '/') . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path), true, 303); exit; }
function flash(string $type, string $message): void { $_SESSION['_flash'][] = ['type' => $type, 'message' => $message]; }
function take_flashes(): array { $f = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $f; }
function view(string $name, array $data = []): void { extract($data, EXTR_SKIP); require dirname(__DIR__) . '/app/Views/' . $name . '.php'; }
function render_public(string $contentView, array $data = []): void { $data['flashes'] = take_flashes(); extract($data, EXTR_SKIP); require dirname(__DIR__) . '/app/Views/layouts/auth.php'; }
function json_response(array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function request_method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_auth(): array { $user = current_user(); if (!$user) redirect('login'); if (($user['status'] ?? '') !== 'active') { $_SESSION = []; session_destroy(); redirect('login'); } return $user; }
function require_admin(): array { $u = require_auth(); if (($u['role'] ?? '') !== 'admin') { http_response_code(403); view('errors/403', ['title'=>'Access denied']); exit; } return $u; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(WhatsUP\Security\Csrf::token()) . '">'; }
function verify_csrf(): void { if (!WhatsUP\Security\Csrf::verify($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(419); view('errors/error', ['title'=>'Session expired','message'=>'Refresh the page and try again.']); exit; } }
function response_error(Throwable $error): void { $id = bin2hex(random_bytes(6)); error_log("request={$id} " . preg_replace('/(password|token|secret|phone|email|code)=?[^ ]*/i', '$1=[redacted]', $error->getMessage())); http_response_code(500); view('errors/error', ['title'=>'Something went wrong','message'=>'The request could not be completed. Reference: ' . $id]); }
function render(string $name, array $data = []): void { $data['flashes'] = take_flashes(); $data['user'] = current_user(); view('layouts/app', ['contentView'=>$name, 'data'=>$data, 'title'=>$data['title'] ?? 'WhatsUP']); }
