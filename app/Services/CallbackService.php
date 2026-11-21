<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use PDO;
use RuntimeException;
use Throwable;

/** Validates signed, time-bounded webhook callbacks and deduplicates event IDs transactionally. */
final class CallbackService
{
    public function __construct(private readonly PDO $pdo, private readonly string $secret, private readonly int $maxSkewSeconds = 300) {}

    /**
     * Signature contract: X-Webhook-Timestamp + raw body, signature hex HMAC-SHA256,
     * prefixed optionally with "sha256=". event_id is the provider's unique event ID.
     */
    public function handle(string $rawBody, string $timestamp, string $signature): bool
    {
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > $this->maxSkewSeconds) {
            throw new RuntimeException('Callback timestamp outside replay window.');
        }
        $signature = preg_replace('/^sha256=/i', '', trim($signature)) ?? '';
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
        if (!hash_equals($expected, $signature)) throw new RuntimeException('Invalid callback signature.');
        try { $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new RuntimeException('Malformed callback JSON.', 0, $e); }
        if (!is_array($event) || empty($event['event_id']) || empty($event['job_id']) || empty($event['status'])) {
            throw new RuntimeException('Callback requires event_id, job_id and status.');
        }
        $statusMap = ['accepted' => 'accepted', 'sent' => 'accepted', 'delivered' => 'delivered', 'failed' => 'failed'];
        $eventType = strtolower((string)$event['status']);
        if (!isset($statusMap[$eventType])) throw new RuntimeException('Unsupported callback status.');
        $payloadHash = hash('sha256', $rawBody);

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT INTO webhook_events(event_id,event_type,job_id,received_at,payload_hash) VALUES(?,?,?,UTC_TIMESTAMP(),?)');
            try {
                $insert->execute([(string)$event['event_id'], $eventType, (int)$event['job_id'], $payloadHash]);
            } catch (\PDOException $e) {
                if ((string)$e->getCode() === '23000') { $this->pdo->rollBack(); return false; }
                throw $e;
            }
            $job = $this->pdo->prepare('SELECT status,reservation_id,recipient_id FROM delivery_jobs WHERE id=? FOR UPDATE');
            $job->execute([(int)$event['job_id']]);
            $row = $job->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Callback job not found.');
            $next = $statusMap[$eventType];
            if (!in_array($row['status'], ['delivered','failed','cancelled'], true)
                && !($row['status'] === 'accepted' && $next === 'accepted')) {
                if ($next === 'failed') {
                    $this->pdo->prepare("UPDATE delivery_jobs SET status='failed',provider_message_id=COALESCE(?,provider_message_id),last_error='Provider reported failure',updated_at=UTC_TIMESTAMP() WHERE id=?")
                        ->execute([$event['provider_message_id'] ?? null, (int)$event['job_id']]);
                    (new QuotaService($this->pdo))->release((int)$row['reservation_id']);
                    $this->pdo->prepare("UPDATE campaign_recipients SET status='failed',failure_reason='Provider reported failure',updated_at=UTC_TIMESTAMP() WHERE id=?")
                        ->execute([(int)$row['recipient_id']]);
                } else {
                    $this->pdo->prepare("UPDATE delivery_jobs SET status=?,provider_message_id=COALESCE(?,provider_message_id),accepted_at=COALESCE(accepted_at,UTC_TIMESTAMP()),delivered_at=IF(?='delivered',UTC_TIMESTAMP(),delivered_at),updated_at=UTC_TIMESTAMP() WHERE id=?")
                        ->execute([$next, $event['provider_message_id'] ?? null, $next, (int)$event['job_id']]);
                    (new QuotaService($this->pdo))->consume((int)$row['reservation_id']);
                    $recipientStatus = $next === 'delivered' ? 'delivered' : 'accepted';
                    $this->pdo->prepare('UPDATE campaign_recipients SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status NOT IN (\'delivered\',\'failed\',\'cancelled\')')
                        ->execute([$recipientStatus, (int)$row['recipient_id']]);
                }
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
