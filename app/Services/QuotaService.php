<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Locks a per-user/day bucket and records one reservation per recipient×campaign-part. */
final class QuotaService
{
    public function __construct(private readonly PDO $pdo) {}

    /** Must be called inside the campaign creation transaction. */
    public function reserve(int $userId, DateTimeImmutable $day, int $limit, int $campaignId, int $recipientId, int $partId): int
    {
        if ($limit < 0) throw new RuntimeException('Daily quota limit cannot be negative.');
        $date = $day->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $this->pdo->prepare('INSERT INTO quota_days(user_id,quota_day,reserved_count,limit_snapshot) VALUES(?,?,0,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)')
            ->execute([$userId, $date, $limit]);
        $stmt = $this->pdo->prepare('SELECT reserved_count,limit_snapshot FROM quota_days WHERE user_id=? AND quota_day=? FOR UPDATE');
        $stmt->execute([$userId, $date]);
        $bucket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bucket) throw new RuntimeException('Could not lock quota day.');

        $existing = $this->pdo->prepare('SELECT id FROM quota_reservations WHERE recipient_id=? AND campaign_part_id=?');
        $existing->execute([$recipientId, $partId]);
        $reservationId = $existing->fetchColumn();
        if ($reservationId !== false) return (int)$reservationId;

        if ((int)$bucket['reserved_count'] >= $limit) throw new RuntimeException('Daily quota exhausted.');
        $this->pdo->prepare('UPDATE quota_days SET reserved_count=reserved_count+1,limit_snapshot=? WHERE user_id=? AND quota_day=?')
            ->execute([$limit, $userId, $date]);
        $this->pdo->prepare("INSERT INTO quota_reservations(user_id,quota_day,campaign_id,recipient_id,campaign_part_id,state,created_at) VALUES(?,?,?,?,?,'reserved',UTC_TIMESTAMP())")
            ->execute([$userId, $date, $campaignId, $recipientId, $partId]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Terminally release exactly once (cancellation before dispatch or permanent failure). */
    public function release(int $reservationId): bool { return $this->transition($reservationId, 'released'); }

    /** Accepted delivery consumes the slot; reserved_count remains the day usage total. */
    public function consume(int $reservationId): bool { return $this->transition($reservationId, 'consumed'); }

    private function transition(int $reservationId, string $target): bool
    {
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT user_id,quota_day,state FROM quota_reservations WHERE id=? FOR UPDATE');
            $q->execute([$reservationId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['state'] !== 'reserved') {
                if ($own) $this->pdo->commit();
                return false;
            }
            if ($target === 'released') {
                $decrement = $this->pdo->prepare('UPDATE quota_days SET reserved_count=reserved_count-1 WHERE user_id=? AND quota_day=? AND reserved_count>0');
                $decrement->execute([(int)$row['user_id'], $row['quota_day']]);
                if ($decrement->rowCount() !== 1) throw new RuntimeException('Quota day counter inconsistent.');
            }
            $this->pdo->prepare('UPDATE quota_reservations SET state=?,released_at=IF(?=\'released\',UTC_TIMESTAMP(),released_at) WHERE id=? AND state=\'reserved\'')
                ->execute([$target, $target, $reservationId]);
            if ($own) $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
