<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use PDO;
use RuntimeException;

/**
 * Quota-backed campaign retry/cancel facade. Exposes operations by numeric schema job ID.
 * The worker retains its constructor's network sender requirement; cancel is DB-only.
 */
final class JobService
{
    public function __construct(private readonly PDO $pdo) {}

    public function cancel(int $userId, int $jobId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT j.status,j.reservation_id,j.recipient_id FROM delivery_jobs j JOIN campaign_recipients r ON r.id=j.recipient_id WHERE j.id=? AND r.user_id=? FOR UPDATE');
            $q->execute([$jobId, $userId]);
            $job = $q->fetch(PDO::FETCH_ASSOC);
            if (!$job || !in_array($job['status'], ['queued','retry_wait'], true)) {
                $this->pdo->commit();
                return false;
            }
            $this->pdo->prepare("UPDATE delivery_jobs SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$jobId]);
            (new QuotaService($this->pdo))->release((int)$job['reservation_id']);
            $remaining = $this->pdo->prepare("SELECT COUNT(*) FROM delivery_jobs WHERE recipient_id=? AND status NOT IN ('failed','cancelled','delivered')");
            $remaining->execute([(int)$job['recipient_id']]);
            if ((int)$remaining->fetchColumn() === 0) {
                $this->pdo->prepare("UPDATE campaign_recipients SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$job['recipient_id']]);
            }
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function retry(int $userId, int $jobId, string $webhookUrl, string $webhookSecret, int $maxAttempts = 6): bool
    {
        $owner = $this->pdo->prepare('SELECT 1 FROM delivery_jobs j JOIN campaign_recipients r ON r.id=j.recipient_id WHERE j.id=? AND r.user_id=?');
        $owner->execute([$jobId, $userId]);
        if (!$owner->fetchColumn()) throw new RuntimeException('Job not found.');
        $sender = new WebhookClient($webhookUrl, $webhookSecret);
        return (new \WhatsUP\Worker\DeliveryWorker($this->pdo, $sender, $maxAttempts))->retry($jobId);
    }
}
