<?php
declare(strict_types=1);

namespace WhatsUP\Worker;

use PDO;
use RuntimeException;
use Throwable;
use WhatsUP\Services\QuotaService;
use WhatsUP\Services\DeliverySender;
use WhatsUP\Services\PermanentDeliveryException;

/** MySQL/InnoDB queue worker; each recipient's campaign parts are sent in sequence order. */
final class DeliveryWorker
{
    public function __construct(private readonly PDO $pdo, private readonly DeliverySender $webhook, private readonly int $maxAttempts = 6) {}

    /** Dispatch at most one eligible job. */
    public function runOne(): bool
    {
        $this->suppressNewOptOuts();
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->query("SELECT j.id,j.campaign_id,j.recipient_id,j.campaign_part_id,j.reservation_id,j.idempotency_key,j.attempts,
                                           r.user_id,r.phone_e164,r.display_name,u.status user_status,c.consent_status,cp.sequence_no,cp.part_type,cp.text_content,cp.caption,cp.media_id,m.storage_name,m.mime_type
                                      FROM delivery_jobs j
                                      JOIN campaign_recipients r ON r.id=j.recipient_id
                                      JOIN users u ON u.id=r.user_id AND u.status='active'
                                      JOIN contacts c ON c.id=r.contact_id AND c.user_id=r.user_id AND c.consent_status='opted_in'
                                      JOIN campaign_parts cp ON cp.id=j.campaign_part_id
                                 LEFT JOIN media m ON m.id=cp.media_id
                                     WHERE j.status IN ('queued','retry_wait') AND j.available_at<=UTC_TIMESTAMP()
                                       AND NOT EXISTS (SELECT 1 FROM delivery_jobs prev JOIN campaign_parts pp ON pp.id=prev.campaign_part_id
                                                        WHERE prev.recipient_id=j.recipient_id AND pp.sequence_no<cp.sequence_no
                                                          AND prev.status NOT IN ('accepted','delivered','failed','cancelled'))
                                  ORDER BY j.available_at,j.id LIMIT 1 FOR UPDATE");
            $job = $q->fetch(PDO::FETCH_ASSOC);
            if (!$job) { $this->pdo->commit(); return false; }
            $this->pdo->prepare("UPDATE delivery_jobs SET status='sending',attempts=attempts+1,locked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('queued','retry_wait')")
                ->execute([(int)$job['id']]);
            $job['attempts'] = (int)$job['attempts'] + 1;
            $this->pdo->prepare("UPDATE campaign_recipients SET status='sending',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='queued'")->execute([(int)$job['recipient_id']]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        try {
            $response = $this->webhook->send([
                'job_id' => (int)$job['id'], 'idempotency_key' => $job['idempotency_key'],
                'campaign_id' => (int)$job['campaign_id'], 'recipient_id' => (int)$job['recipient_id'],
                'user_id' => (int)$job['user_id'], 'to' => $job['phone_e164'],
                'sequence' => (int)$job['sequence_no'], 'part' => [
                    'type' => $job['part_type'], 'text' => $job['text_content'], 'caption' => $job['caption'],
                    'media' => $job['storage_name'] ? ['ref' => $job['storage_name'], 'mime_type' => $job['mime_type']] : null,
                ],
            ], $job['idempotency_key']);
            $this->accept((int)$job['id'], (int)$job['reservation_id'], (int)$job['recipient_id'], (string)($response['message_id'] ?? ''));
        } catch (Throwable $e) {
            if (!$e instanceof PermanentDeliveryException && (int)$job['attempts'] < $this->maxAttempts) {
                $this->retryLater($job, $e->getMessage());
            } else {
                $this->fail((int)$job['id'], (int)$job['reservation_id'], (int)$job['recipient_id'], $e->getMessage());
            }
        }
        return true;
    }

    /** Suppress remaining jobs immediately when consent or account status was revoked. */
    private function suppressNewOptOuts(): void
    {
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->query("SELECT j.id,j.reservation_id,j.recipient_id FROM delivery_jobs j JOIN campaign_recipients r ON r.id=j.recipient_id LEFT JOIN contacts c ON c.id=r.contact_id AND c.user_id=r.user_id LEFT JOIN users u ON u.id=r.user_id WHERE j.status IN ('queued','retry_wait') AND (u.status<>'active' OR c.id IS NULL OR c.consent_status<>'opted_in') FOR UPDATE");
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);$quota=new QuotaService($this->pdo);
            foreach($rows as $row){$this->pdo->prepare("UPDATE delivery_jobs SET status='cancelled',last_error='Suppressed: account inactive or consent withdrawn',updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('queued','retry_wait')")->execute([(int)$row['id']]);$quota->release((int)$row['reservation_id']);$this->pdo->prepare("UPDATE campaign_recipients SET status='suppressed',failure_reason='Consent withdrawn or account inactive',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['recipient_id']]);}
            if($this->pdo->inTransaction())$this->pdo->commit();
        } catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /**
     * Recover claims left by a crashed worker. The provider request always uses the
     * stable idempotency key, so a resend after a process crash is safe.
     */
    public function recoverStaleClaims(int $ageSeconds = 120): bool
    {
        $ageSeconds = max(30, min(3600, $ageSeconds));
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare("SELECT id,recipient_id FROM delivery_jobs WHERE status='sending' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND) ORDER BY locked_at LIMIT 50 FOR UPDATE");
            $q->execute([$ageSeconds]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) { $this->pdo->commit(); return false; }
            $jobUpdate = $this->pdo->prepare("UPDATE delivery_jobs SET status='retry_wait',available_at=UTC_TIMESTAMP(),locked_at=NULL,last_error='Recovered stale worker claim',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'");
            $recipientUpdate = $this->pdo->prepare("UPDATE campaign_recipients SET status='queued',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'");
            foreach ($rows as $row) {
                $jobUpdate->execute([(int)$row['id']]);
                $recipientUpdate->execute([(int)$row['recipient_id']]);
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Manually retry a permanently failed job. The same numeric job id and idempotency
     * key are retained; a previously released reservation is re-reserved under the
     * original user/day lock so a retry cannot bypass quota.
     */
    public function retry(int $jobId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare("SELECT j.status,j.reservation_id,j.recipient_id,r.user_id,qr.quota_day,qr.state
                                      FROM delivery_jobs j JOIN campaign_recipients r ON r.id=j.recipient_id
                                      JOIN quota_reservations qr ON qr.id=j.reservation_id WHERE j.id=? FOR UPDATE");
            $q->execute([$jobId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] !== 'failed') { $this->pdo->commit(); return false; }
            if ($row['state'] === 'released') {
                $bucket = $this->pdo->prepare('SELECT reserved_count,limit_snapshot FROM quota_days WHERE user_id=? AND quota_day=? FOR UPDATE');
                $bucket->execute([(int)$row['user_id'], $row['quota_day']]);
                $day = $bucket->fetch(PDO::FETCH_ASSOC);
                if (!$day || (int)$day['reserved_count'] >= (int)$day['limit_snapshot']) throw new RuntimeException('Daily quota exhausted; failed job cannot be retried.');
                $this->pdo->prepare('UPDATE quota_days SET reserved_count=reserved_count+1 WHERE user_id=? AND quota_day=?')
                    ->execute([(int)$row['user_id'], $row['quota_day']]);
                $this->pdo->prepare("UPDATE quota_reservations SET state='reserved',released_at=NULL WHERE id=? AND state='released'")
                    ->execute([(int)$row['reservation_id']]);
            } elseif ($row['state'] !== 'reserved') {
                throw new RuntimeException('Consumed job cannot be retried.');
            }
            $this->pdo->prepare("UPDATE delivery_jobs SET status='retry_wait',available_at=UTC_TIMESTAMP(),last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='failed'")
                ->execute([$jobId]);
            $this->pdo->prepare("UPDATE campaign_recipients SET status='queued',failure_reason=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='failed'")
                ->execute([(int)$row['recipient_id']]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Cancellation succeeds only before dispatch has claimed the queued/retry job. */
    public function cancel(int $jobId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT status,reservation_id,recipient_id FROM delivery_jobs WHERE id=? FOR UPDATE');
            $q->execute([$jobId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || !in_array($row['status'], ['queued','retry_wait'], true)) { $this->pdo->commit(); return false; }
            $this->pdo->prepare("UPDATE delivery_jobs SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$jobId]);
            (new QuotaService($this->pdo))->release((int)$row['reservation_id']);
            $left = $this->pdo->prepare("SELECT COUNT(*) FROM delivery_jobs WHERE recipient_id=? AND status NOT IN ('failed','cancelled','delivered')");
            $left->execute([(int)$row['recipient_id']]);
            if ((int)$left->fetchColumn() === 0) $this->pdo->prepare("UPDATE campaign_recipients SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['recipient_id']]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function accept(int $jobId, int $reservationId, int $recipientId, string $providerId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE delivery_jobs SET status='accepted',provider_message_id=?,accepted_at=UTC_TIMESTAMP(),locked_at=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'")
                ->execute([$providerId ?: null, $jobId]);
            (new QuotaService($this->pdo))->consume($reservationId);
            $this->pdo->prepare("UPDATE campaign_recipients SET status='accepted',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'")->execute([$recipientId]);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function retryLater(array $job, string $error): void
    {
        $seconds = min(3600, 2 ** min((int)$job['attempts'], 10));
        $this->pdo->prepare("UPDATE delivery_jobs SET status='retry_wait',available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),locked_at=NULL,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'")
            ->execute([$seconds, mb_substr($error, 0, 255), (int)$job['id']]);
    }

    private function fail(int $jobId, int $reservationId, int $recipientId, string $error): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE delivery_jobs SET status='failed',locked_at=NULL,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'")
                ->execute([mb_substr($error, 0, 255), $jobId]);
            (new QuotaService($this->pdo))->release($reservationId);
            $this->pdo->prepare("UPDATE campaign_recipients SET status='failed',failure_reason=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([mb_substr($error, 0, 255), $recipientId]);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}
