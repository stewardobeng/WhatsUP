<?php
declare(strict_types=1);

/**
 * Schema-backed smoke tests for campaign snapshots, quota reservation and
 * idempotent cancellation. Run against a disposable database:
 *   $env:DB_DSN='mysql:host=127.0.0.1;dbname=whatsup_test;charset=utf8mb4'; php tests/campaign-services.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use WhatsUP\Core\Database;
use WhatsUP\Services\CampaignService;
use WhatsUP\Services\CallbackService;
use WhatsUP\Services\DeliverySender;
use WhatsUP\Services\PermanentDeliveryException;
use WhatsUP\Services\QuotaService;
use WhatsUP\Worker\DeliveryWorker;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok - {$message}\n";
}

$db = Database::connection();
$email = 'campaign-test-' . bin2hex(random_bytes(4)) . '@example.test';
$userId = 0;

try {
    $db->beginTransaction();
    $db->prepare("INSERT INTO users(name,email,password_hash,role,status,daily_limit,timezone,created_at,updated_at) VALUES('test',?,'x','operator','active',20,'UTC',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$email]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO templates(user_id,name,language,created_at,updated_at) VALUES(?,'service test','en',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$userId]);
    $templateId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO template_parts(user_id,template_id,sequence_no,part_type,text_content,created_at) VALUES(?,?,1,'text','hello',UTC_TIMESTAMP()),(?,?,2,'text','world',UTC_TIMESTAMP())")
        ->execute([$userId, $templateId, $userId, $templateId]);
    $db->prepare("INSERT INTO contacts(user_id,phone_e164,name,consent_status,created_at,updated_at) VALUES(?, '+233240000001','one','opted_in',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(?, '+233240000002','two','unknown',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(?, '+233240000003','three','unsubscribed',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$userId, $userId, $userId]);
    $db->commit();

    $ids = $db->prepare('SELECT id FROM contacts WHERE user_id=? ORDER BY id');
    $ids->execute([$userId]);
    $contactIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));

    // Fixed future morning schedule keeps the messaging-window check deterministic.
    $scheduled = new DateTimeImmutable('tomorrow 10:00', new DateTimeZone('UTC'));
    $campaign = (new CampaignService($db))->create([
        'user_id' => $userId,
        'template_id' => $templateId,
        'name' => 'service test',
        'contact_ids' => [$contactIds[0], $contactIds[0], $contactIds[1], $contactIds[2]],
        'scheduled_for' => $scheduled,
    ]);
    check($campaign['recipient_count'] === 1, 'duplicate contact collapses to one recipient');
    check($campaign['excluded_count'] === 2, 'unknown consent and unsubscribed are excluded');
    check($campaign['part_count'] === 2, 'both template parts are snapshotted');

    $jobs = $db->prepare('SELECT id,status,idempotency_key,reservation_id FROM delivery_jobs WHERE campaign_id=? ORDER BY id');
    $jobs->execute([$campaign['campaign_id']]);
    $rows = $jobs->fetchAll(PDO::FETCH_ASSOC);
    check(count($rows) === 2 && $rows[0]['status'] === 'queued', 'one queued job per recipient x part');
    check($rows[0]['idempotency_key'] !== $rows[1]['idempotency_key'], 'each job has a stable distinct idempotency key');

    $day = $scheduled->format('Y-m-d');
    $bucket = $db->prepare('SELECT reserved_count FROM quota_days WHERE user_id=? AND quota_day=?');
    $bucket->execute([$userId, $day]);
    check((int)$bucket->fetchColumn() === 2, 'two quota reservations counted for the day');

    $worker = new DeliveryWorker($db, new class implements DeliverySender {
        public function send(array $payload, string $idempotencyKey): array
        {
            return ['message_id' => 'test'];
        }
    });
    check($worker->cancel((int)$rows[0]['id']) === true, 'first cancel succeeds');
    check($worker->cancel((int)$rows[0]['id']) === false, 'second cancel is a no-op');
    $bucket->execute([$userId, $day]);
    check((int)$bucket->fetchColumn() === 1, 'quota released exactly once');
    check((new QuotaService($db))->release((int)$rows[0]['reservation_id']) === false, 'released reservation cannot release again');

    // Callback: valid signature accepted once, replay rejected, bad signature/time rejected.
    $secret = 'test-callback-secret';
    $callbacks = new CallbackService($db, $secret, 300);
    $body = json_encode(['event_id' => 'evt-' . bin2hex(random_bytes(4)), 'job_id' => (int)$rows[1]['id'], 'status' => 'accepted', 'provider_message_id' => 'p-1'], JSON_THROW_ON_ERROR);
    $ts = (string)time();
    $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
    check($callbacks->handle($body, $ts, 'sha256=' . $sig) === true, 'first signed callback accepted');
    check($callbacks->handle($body, $ts, $sig) === false, 'replayed event id is deduplicated');
    $bad = json_encode(['event_id' => 'evt-bad', 'job_id' => (int)$rows[1]['id'], 'status' => 'accepted'], JSON_THROW_ON_ERROR);
    $badSig = hash_hmac('sha256', $ts . '.' . $bad, 'wrong');
    try { $callbacks->handle($bad, $ts, $badSig); check(false, 'bad signature must throw'); }
    catch (RuntimeException $e) { check(true, 'bad signature rejected'); }
    $oldTs = (string)(time() - 301);
    $oldSig = hash_hmac('sha256', $oldTs . '.' . $body, $secret);
    try { $callbacks->handle($body, $oldTs, $oldSig); check(false, 'stale timestamp must throw'); }
    catch (RuntimeException $e) { check(true, 'stale timestamp rejected'); }
    $jobStatus = $db->prepare('SELECT status FROM delivery_jobs WHERE id=?');
    $jobStatus->execute([(int)$rows[1]['id']]);
    check($jobStatus->fetchColumn() === 'accepted', 'callback applied accepted state');
    $resState = $db->prepare('SELECT state FROM quota_reservations WHERE id=?');
    $resState->execute([(int)$rows[1]['reservation_id']]);
    check($resState->fetchColumn() === 'consumed', 'accepted callback consumes quota');

    // Permanent failure releases quota once and worker retry re-reserves exactly one slot.
    $failWork = new DeliveryWorker($db, new class implements DeliverySender {
        public function send(array $payload, string $idempotencyKey): array
        {
            throw new PermanentDeliveryException('provider rejected payload');
        }
    });
    // Restore the cancelled fixture to a queued/reserved state, as an allowed manual retry would.
    $db->prepare('UPDATE quota_days SET reserved_count=reserved_count+1,limit_snapshot=20 WHERE user_id=? AND quota_day=?')->execute([$userId, $day]);
    $db->prepare("UPDATE quota_reservations SET state='reserved',released_at=NULL WHERE id=?")->execute([(int)$rows[0]['reservation_id']]);
    $db->prepare("UPDATE campaign_recipients SET status='queued',failure_reason=NULL WHERE id=(SELECT recipient_id FROM delivery_jobs WHERE id=?)")->execute([(int)$rows[0]['id']]);
    $db->prepare("UPDATE delivery_jobs SET status='queued',available_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$rows[0]['id']]);
    check($failWork->runOne() === true, 'worker dispatches seeded job');
    $failedStatus = $db->prepare('SELECT status FROM delivery_jobs WHERE id=?');
    $failedStatus->execute([(int)$rows[0]['id']]);
    check($failedStatus->fetchColumn() === 'failed', 'permanent failure has no retry');
    $bucket->execute([$userId, $day]);
    check((int)$bucket->fetchColumn() === 1, 'failure release decremented once while accepted usage remains');
    $db->prepare("UPDATE quota_days SET limit_snapshot=0 WHERE user_id=? AND quota_day=?")->execute([$userId, $day]);
    try { $failWork->retry((int)$rows[0]['id']); check(false, 'retry without quota must throw'); }
    catch (RuntimeException $e) { check(true, 'retry refuses when quota exhausted'); }
    $db->prepare("UPDATE quota_days SET limit_snapshot=20 WHERE user_id=? AND quota_day=?")->execute([$userId, $day]);
    check($failWork->retry((int)$rows[0]['id']) === true, 'manual retry re-reserves failed job');
    $bucket->execute([$userId, $day]);
    check((int)$bucket->fetchColumn() === 2, 'retry reserved exactly once in addition to accepted usage');

    echo "All campaign service tests passed\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($userId > 0) {
        $campaigns = $db->prepare('SELECT id FROM campaigns WHERE user_id=?');
        $campaigns->execute([$userId]);
        $campaignIds = array_map('intval', $campaigns->fetchAll(PDO::FETCH_COLUMN));
        foreach ($campaignIds as $campaignId) {
            $db->prepare('DELETE FROM webhook_events WHERE job_id IN (SELECT id FROM delivery_jobs WHERE campaign_id=?)')->execute([$campaignId]);
            $db->prepare('DELETE FROM delivery_jobs WHERE campaign_id=?')->execute([$campaignId]);
            $db->prepare('DELETE FROM quota_reservations WHERE campaign_id=?')->execute([$campaignId]);
            $db->prepare('DELETE FROM campaign_parts WHERE campaign_id=?')->execute([$campaignId]);
            $db->prepare('DELETE FROM campaign_recipients WHERE campaign_id=?')->execute([$campaignId]);
            $db->prepare('DELETE FROM campaign_targets WHERE campaign_id=?')->execute([$campaignId]);
            $db->prepare('DELETE FROM campaigns WHERE id=?')->execute([$campaignId]);
        }
        $templates = $db->prepare('SELECT id FROM templates WHERE user_id=?');
        $templates->execute([$userId]);
        foreach ($templates->fetchAll(PDO::FETCH_COLUMN) as $templateId) $db->prepare('DELETE FROM template_parts WHERE template_id=?')->execute([(int)$templateId]);
        $db->prepare('DELETE FROM templates WHERE user_id=?')->execute([$userId]);
        $db->prepare('DELETE FROM contacts WHERE user_id=?')->execute([$userId]);
        $db->prepare('DELETE FROM quota_days WHERE user_id=?')->execute([$userId]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    }
}
