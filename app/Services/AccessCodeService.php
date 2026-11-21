<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Account-scoped access codes with a default one-day lifetime.
 * Codes are stored as SHA-256 hashes, never plaintext, and expire/revoke independently.
 */
final class AccessCodeService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param list<int> $accountIds
     * @return array{code_id:int,code:string,expires_at:string}
     */
    public function issue(string $label, array $accountIds, ?DateTimeImmutable $expiresAt = null, ?int $createdBy = null): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn(int $id): bool => $id > 0)));
        if ($accountIds === []) throw new RuntimeException('At least one target account is required.');
        $expiry = ($expiresAt ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 day'))
            ->setTimezone(new DateTimeZone('UTC'));
        if ($expiry->getTimestamp() <= time()) throw new RuntimeException('Expiry must be in the future.');

        $code = strtoupper(bin2hex(random_bytes(20)));
        $this->pdo->beginTransaction();
        try {
            foreach ($accountIds as $accountId) {
                $q = $this->pdo->prepare("SELECT id FROM users WHERE id=? AND status='active'");
                $q->execute([$accountId]);
                if (!$q->fetchColumn()) throw new RuntimeException('Target account is not active: ' . $accountId);
            }
            $this->pdo->prepare('INSERT INTO access_codes(code_hash,label,expires_at,created_by,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')
                ->execute([hash('sha256', $code), $label, $expiry->format('Y-m-d H:i:s'), $createdBy]);
            $codeId = (int)$this->pdo->lastInsertId();
            $link = $this->pdo->prepare('INSERT INTO access_code_accounts(code_id,account_id) VALUES(?,?)');
            foreach ($accountIds as $accountId) $link->execute([$codeId, $accountId]);
            $this->pdo->commit();
            return ['code_id' => $codeId, 'code' => $code, 'expires_at' => $expiry->format(DATE_ATOM)];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Validates exactly once for the presented account; expiry is enforced in SQL and is timezone-safe (UTC). */
    public function redeem(string $code, int $accountId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare("SELECT id FROM access_codes WHERE code_hash=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE");
            $q->execute([hash('sha256', strtoupper(trim($code)))]);
            $codeId = $q->fetchColumn();
            if (!$codeId) { $this->pdo->commit(); return false; }
            $link = $this->pdo->prepare('UPDATE access_code_accounts SET used_at=UTC_TIMESTAMP() WHERE code_id=? AND account_id=? AND used_at IS NULL');
            $link->execute([(int)$codeId, $accountId]);
            $ok = $link->rowCount() === 1;
            $this->pdo->commit();
            return $ok;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function revoke(int $codeId, ?int $actorId = null): bool
    {
        $q = $this->pdo->prepare('UPDATE access_codes SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND revoked_at IS NULL');
        $q->execute([$codeId]);
        return $q->rowCount() === 1;
    }
}
