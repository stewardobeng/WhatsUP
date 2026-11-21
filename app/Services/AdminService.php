<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use WhatsUP\Core\Support;

/** Administrative user, access-code, and audit-log operations. */
final class AdminService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{items:list<array>,total:int,page:int,per_page:int} */
    public function users(int $actorId, int $page = 1, int $perPage = 25, string $search = ''): array
    {
        $this->assertAdmin($actorId);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $search = trim($search);
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE name LIKE ? OR email LIKE ?';
            $needle = '%' . self::likeEscape($search) . '%';
            $params = [$needle, $needle];
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM users' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT id,name,email,role,status,daily_limit,timezone,created_at,updated_at FROM users' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?');
        foreach ($params as $index => $value) $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
        $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$user) {
            $user['id'] = (int)$user['id'];
            $user['daily_limit'] = (int)$user['daily_limit'];
        }
        unset($user);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** Update only explicitly supported account fields; returns the fresh safe user record. */
    public function updateUser(int $actorId, int $userId, array $changes): array
    {
        if ($userId < 1) throw new RuntimeException('Invalid user id.');
        $allowed = ['name', 'email', 'role', 'status', 'daily_limit', 'timezone'];
        $unknown = array_diff(array_keys($changes), $allowed);
        if ($unknown) throw new RuntimeException('Unsupported user field: ' . (string)reset($unknown));
        if ($changes === []) throw new RuntimeException('No user changes were supplied.');

        $ownTransaction = !$this->pdo->inTransaction();
        $savepoint = 'admin_operation';
        if ($ownTransaction) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $this->assertAdmin($actorId, true);
            // Lock the account row, then prevent an administrator from demoting/suspending themself.
            $stmt = $this->pdo->prepare('SELECT id,name,email,role,status,daily_limit,timezone FROM users WHERE id=? FOR UPDATE');
            $stmt->execute([$userId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new RuntimeException('User not found.');

            $normalized = $this->normalizeChanges($changes);
            if ($actorId === $userId && ((isset($normalized['role']) && $normalized['role'] !== 'admin') || (isset($normalized['status']) && $normalized['status'] !== 'active'))) {
                throw new RuntimeException('You cannot remove your own administrator access or suspend your own account.');
            }
            $newRole = $normalized['role'] ?? $before['role'];
            $newStatus = $normalized['status'] ?? $before['status'];
            if (($before['role'] === 'admin' && $before['status'] === 'active') && ($newRole !== 'admin' || $newStatus !== 'active')) {
                $admins = $this->pdo->query("SELECT id FROM users WHERE role='admin' AND status='active' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
                if (count($admins) <= 1) throw new RuntimeException('The last active administrator cannot be demoted or suspended.');
            }

            $fields = array_keys($normalized);
            if ($fields !== []) {
                $sets = array_map(static fn(string $field): string => $field . '=?', $fields);
                $sql = 'UPDATE users SET ' . implode(',', $sets) . ',updated_at=UTC_TIMESTAMP() WHERE id=?';
                $update = $this->pdo->prepare($sql);
                $update->execute([...array_values($normalized), $userId]);
            }
            Support::audit($this->pdo, $actorId, 'admin.user_updated', 'user', (string)$userId, ['fields' => array_values($fields)]);
            $fresh = $this->pdo->prepare('SELECT id,name,email,role,status,daily_limit,timezone,created_at,updated_at FROM users WHERE id=?');
            $fresh->execute([$userId]);
            $user = $fresh->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new RuntimeException('User not found.');
            $user['id'] = (int)$user['id'];
            $user['daily_limit'] = (int)$user['daily_limit'];
            if ($ownTransaction) $this->pdo->commit();
            else $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $user;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($ownTransaction) $this->pdo->rollBack();
                else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            throw $e;
        }
    }

    /** Set an account password without returning or logging the credential. */
    public function setUserPassword(int $actorId, int $userId, string $password): void
    {
        if ($userId < 1 || strlen($password) < 12 || strlen($password) > 200) {
            throw new RuntimeException('A valid user id and password of 12–200 bytes are required.');
        }
        $ownTransaction = !$this->pdo->inTransaction();
        $savepoint = 'admin_operation';
        if ($ownTransaction) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $this->assertAdmin($actorId, true);
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([$userId]);
            if (!$lock->fetchColumn()) throw new RuntimeException('User not found.');
            $this->pdo->prepare('UPDATE users SET password_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            $this->pdo->prepare('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')
                ->execute([$userId]);
            Support::audit($this->pdo, $actorId, 'admin.user_password_reset', 'user', (string)$userId);
            if ($ownTransaction) $this->pdo->commit();
            else $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($ownTransaction) $this->pdo->rollBack();
                else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            throw $e;
        }
    }

    /** Issue one random access code assigned to the listed active user accounts. */
    public function issueAccessCode(int $actorId, string $label, array $accountIds, ?DateTimeImmutable $expiresAt = null): array
    {
        $label = trim($label);
        $labelLength = function_exists('mb_strlen') ? mb_strlen($label, 'UTF-8') : strlen($label);
        if ($labelLength < 1 || $labelLength > 120) throw new RuntimeException('Access-code label must be between 1 and 120 characters.');
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn(int $id): bool => $id > 0)));
        if ($accountIds === []) throw new RuntimeException('At least one target account is required.');
        if (count($accountIds) > 500) throw new RuntimeException('An access code may be assigned to at most 500 accounts.');
        $expiry = ($expiresAt ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 day'))->setTimezone(new DateTimeZone('UTC'));
        if ($expiry->getTimestamp() <= time()) throw new RuntimeException('Expiry must be in the future.');

        $code = strtoupper(bin2hex(random_bytes(20)));
        $ownTransaction = !$this->pdo->inTransaction();
        $savepoint = 'admin_operation';
        if ($ownTransaction) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $this->assertAdmin($actorId, true);
            $check = $this->pdo->prepare("SELECT id FROM users WHERE id=? AND status='active'");
            foreach ($accountIds as $accountId) {
                $check->execute([$accountId]);
                if (!$check->fetchColumn()) throw new RuntimeException('Target account is not active: ' . $accountId);
            }
            $insert = $this->pdo->prepare('INSERT INTO access_codes(code_hash,label,expires_at,created_by,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())');
            $insert->execute([hash('sha256', $code), $label, $expiry->format('Y-m-d H:i:s'), $actorId]);
            $codeId = (int)$this->pdo->lastInsertId();
            $assign = $this->pdo->prepare('INSERT INTO access_code_accounts(code_id,account_id) VALUES(?,?)');
            foreach ($accountIds as $accountId) $assign->execute([$codeId, $accountId]);
            Support::audit($this->pdo, $actorId, 'admin.access_code_issued', 'access_code', (string)$codeId, ['label' => $label, 'account_count' => count($accountIds), 'expires_at' => $expiry->format('Y-m-d H:i:s')]);
            if ($ownTransaction) $this->pdo->commit();
            else $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            // This is the only time plaintext is returned; persist only its hash.
            return ['code_id' => $codeId, 'code' => $code, 'expires_at' => $expiry->format(DATE_ATOM)];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($ownTransaction) $this->pdo->rollBack();
                else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            throw $e;
        }
    }

    public function revokeAccessCode(int $actorId, int $codeId): bool
    {
        if ($codeId < 1) throw new RuntimeException('Invalid access-code id.');
        $ownTransaction = !$this->pdo->inTransaction();
        $savepoint = 'admin_operation';
        if ($ownTransaction) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $this->assertAdmin($actorId, true);
            $stmt = $this->pdo->prepare('UPDATE access_codes SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND revoked_at IS NULL');
            $stmt->execute([$codeId]);
            $revoked = $stmt->rowCount() === 1;
            if ($revoked) Support::audit($this->pdo, $actorId, 'admin.access_code_revoked', 'access_code', (string)$codeId);
            if ($ownTransaction) $this->pdo->commit();
            else $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $revoked;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($ownTransaction) $this->pdo->rollBack();
                else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            throw $e;
        }
    }

    /** List code metadata and usage counts; hashes and plaintext are never exposed. */
    public function accessCodes(int $actorId, int $page = 1, int $perPage = 25): array
    {
        $this->assertAdmin($actorId);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM access_codes')->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT c.id,c.label,c.expires_at,c.revoked_at,c.created_by,c.created_at,u.name AS created_by_name,COUNT(a.account_id) AS assigned_count,SUM(CASE WHEN a.used_at IS NOT NULL THEN 1 ELSE 0 END) AS redeemed_count FROM access_codes c LEFT JOIN users u ON u.id=c.created_by LEFT JOIN access_code_accounts a ON a.code_id=c.id GROUP BY c.id,c.label,c.expires_at,c.revoked_at,c.created_by,c.created_at,u.name ORDER BY c.id DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['created_by'] = $item['created_by'] === null ? null : (int)$item['created_by'];
            $item['assigned_count'] = (int)$item['assigned_count'];
            $item['redeemed_count'] = (int)$item['redeemed_count'];
        }
        unset($item);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @return array{items:list<array>,total:int,page:int,per_page:int} */
    public function auditLogs(int $actorId, int $page = 1, int $perPage = 50, ?string $targetType = null): array
    {
        $this->assertAdmin($actorId);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = '';
        $params = [];
        if ($targetType !== null && trim($targetType) !== '') {
            $where = ' WHERE a.target_type=?';
            $params[] = trim($targetType);
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs a' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $sql = 'SELECT a.id,a.actor_id,u.name AS actor_name,a.action,a.target_type,a.target_id,a.metadata_json,a.ip_hash,a.created_at FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id' . $where . ' ORDER BY a.id DESC LIMIT ? OFFSET ?';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $param) $stmt->bindValue($i + 1, $param, PDO::PARAM_STR);
        $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['actor_id'] = $item['actor_id'] === null ? null : (int)$item['actor_id'];
            $metadata = json_decode((string)($item['metadata_json'] ?? ''), true);
            $item['metadata'] = is_array($metadata) ? $metadata : null;
            unset($item['metadata_json']);
        }
        unset($item);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    private function assertAdmin(int $actorId, bool $forUpdate = false): void
    {
        if ($actorId < 1) throw new RuntimeException('Administrator authentication required.');
        $stmt = $this->pdo->prepare("SELECT role,status FROM users WHERE id=?" . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$actorId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$actor || $actor['role'] !== 'admin' || $actor['status'] !== 'active') {
            throw new RuntimeException('Administrator access required.');
        }
    }

    private function normalizeChanges(array $changes): array
    {
        $normalized = [];
        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'name':
                    $value = trim((string)$value);
                    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
                    if ($length < 2 || $length > 120) throw new RuntimeException('Name must be between 2 and 120 characters.');
                    break;
                case 'email':
                    $value = strtolower(trim((string)$value));
                    if (strlen($value) > 190 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('Enter a valid email address.');
                    break;
                case 'role':
                    $value = (string)$value;
                    if (!in_array($value, ['admin', 'operator'], true)) throw new RuntimeException('Role must be admin or operator.');
                    break;
                case 'status':
                    $value = (string)$value;
                    if (!in_array($value, ['active', 'suspended'], true)) throw new RuntimeException('Status must be active or suspended.');
                    break;
                case 'daily_limit':
                    $value = filter_var($value, FILTER_VALIDATE_INT);
                    if ($value === false || $value < 0 || $value > 2147483647) throw new RuntimeException('Daily limit must be a non-negative integer.');
                    break;
                case 'timezone':
                    $value = trim((string)$value);
                    if (strlen($value) > 64 || !in_array($value, DateTimeZone::listIdentifiers(), true)) throw new RuntimeException('Enter a valid IANA timezone.');
                    break;
                default:
                    throw new RuntimeException('Unsupported user field.');
            }
            $normalized[$field] = $value;
        }
        return $normalized;
    }

    private static function likeEscape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
