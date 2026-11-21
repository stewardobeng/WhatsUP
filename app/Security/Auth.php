<?php
declare(strict_types=1);

namespace WhatsUP\Security;

use PDO;
use RuntimeException;
use Throwable;

/** Authentication, registration, and one-time password-reset operations. */
final class Auth
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$VAUOpB4NJLWmnrYEgzhQaOV5jq/fz9IEJVfVjuuHnYbErT5vpqmBu';

    public function __construct(private readonly PDO $pdo) {}

    /** Create an operator account. Returns its database id. */
    public function register(string $name, string $email, string $password): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        self::validateName($name);
        self::validateEmail($email);
        self::validatePassword($password);

        try {
            $stmt = $this->pdo->prepare("INSERT INTO users(name,email,password_hash,role,status,daily_limit,timezone,created_at,updated_at) VALUES(?,?,?,'operator','active',?,'UTC',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), self::defaultDailyLimit()]);
        } catch (\PDOException $e) {
            // Do not reveal whether an email is already registered.
            if ($e->getCode() === '23000') {
                throw new RuntimeException('An account could not be created with those details.');
            }
            throw $e;
        }
        return (int)$this->pdo->lastInsertId();
    }

    /** Verify credentials and return a session-safe user record. */
    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if(strlen($password)>200)throw new RuntimeException('Invalid email or password.');
        $stmt = $this->pdo->prepare('SELECT id,name,email,password_hash,role,status,daily_limit,timezone FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $hash = is_array($user) ? (string)$user['password_hash'] : self::DUMMY_PASSWORD_HASH;
        $valid = password_verify($password, $hash);
        if (!$user || !$valid || $user['status'] !== 'active') {
            throw new RuntimeException('Invalid email or password.');
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $update = $this->pdo->prepare('UPDATE users SET password_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
        }
        unset($user['password_hash']);
        $user['id'] = (int)$user['id'];
        $user['daily_limit'] = (int)$user['daily_limit'];
        return $user;
    }

    /** Regenerate the session id before establishing an authenticated session. */
    public function startSession(array $user): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('A session must be active before authenticating.');
        }
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['user'] = self::sessionUser($user);
        $_SESSION['authenticated_at'] = time();
        Csrf::rotate();
    }

    public function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    /**
     * Create a reset token for an active account; null means no eligible account.
     * Deliver the returned token through the configured email channel; never log it.
     */
    public function requestReset(string $email): ?string
    {
        $email = strtolower(trim($email));
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email=? AND status='active' LIMIT 1");
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) return null;

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([(int)$userId]);
            if (!$lock->fetchColumn()) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([(int)$userId]);
            $this->pdo->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at,created_at) VALUES(?,?,UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP())')
                ->execute([(int)$userId, $hash]);
            $this->pdo->commit();
            return $token;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Consume one valid reset token and invalidate all other outstanding tokens. */
    public function resetPassword(string $token, string $password): void
    {
        self::validatePassword($password);
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            throw new RuntimeException('This reset link is invalid or has expired.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id,user_id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $stmt->execute([hash('sha256', $token)]);
            $reset = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$reset) throw new RuntimeException('This reset link is invalid or has expired.');

            $this->pdo->prepare('UPDATE users SET password_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['user_id']]);
            $this->pdo->prepare('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')
                ->execute([(int)$reset['user_id']]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private static function validateName(string $name): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length < 2 || $length > 120) throw new RuntimeException('Name must be between 2 and 120 characters.');
    }

    private static function validateEmail(string $email): void
    {
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Enter a valid email address.');
        }
    }

    private static function validatePassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 200) {
            throw new RuntimeException('Password must be between 12 and 200 bytes.');
        }
    }

    private static function defaultDailyLimit(): int
    {
        $value = getenv('DAILY_QUOTA');
        $limit = $value === false ? 1000 : filter_var($value, FILTER_VALIDATE_INT);
        return is_int($limit) && $limit >= 0 ? $limit : 1000;
    }

    private static function sessionUser(array $user): array
    {
        $safe = [];
        foreach (['id', 'name', 'email', 'role', 'status', 'daily_limit', 'timezone'] as $key) {
            if (array_key_exists($key, $user)) $safe[$key] = $user[$key];
        }
        if (!isset($safe['id']) || !isset($safe['role'])) throw new RuntimeException('Invalid user data for session.');
        $safe['id'] = (int)$safe['id'];
        if (isset($safe['daily_limit'])) $safe['daily_limit'] = (int)$safe['daily_limit'];
        return $safe;
    }
}
