<?php
declare(strict_types=1);

/**
 * Plain-PHP integration suite. It refuses non-test databases and sets DB_DSN
 * before app/bootstrap.php loads .env. Optional connection overrides:
 *   WHATSUP_TEST_DB_DSN, WHATSUP_TEST_DB_USER, WHATSUP_TEST_DB_PASSWORD
 * Run: php tests/run.php
 */
$testDsn = getenv('WHATSUP_TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=whatsup_test;charset=utf8mb4';
if (!preg_match('/(?:^|;)dbname=whatsup_test(?:;|$)/i', $testDsn)) {
    fwrite(STDERR, "Refusing to run tests: DSN must target the isolated whatsup_test database.\n");
    exit(2);
}
putenv('DB_DSN=' . $testDsn);
putenv('DB_USER=' . (getenv('WHATSUP_TEST_DB_USER') !== false ? getenv('WHATSUP_TEST_DB_USER') : 'root'));
putenv('DB_PASSWORD=' . (getenv('WHATSUP_TEST_DB_PASSWORD') !== false ? getenv('WHATSUP_TEST_DB_PASSWORD') : ''));

require dirname(__DIR__) . '/app/bootstrap.php';

use WhatsUP\Core\Database;
use WhatsUP\Security\Auth;
use WhatsUP\Services\AdminService;
use WhatsUP\Services\CallbackService;
use WhatsUP\Services\CampaignService;
use WhatsUP\Services\ContactService;
use WhatsUP\Services\DeliverySender;
use WhatsUP\Services\GroupService;
use WhatsUP\Services\MediaService;
use WhatsUP\Services\PhoneNormalizer;
use WhatsUP\Services\QuotaService;
use WhatsUP\Services\TemplateService;
use WhatsUP\Worker\DeliveryWorker;

$checks = 0;
$failures = [];
function verify(bool $condition, string $description): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok - {$description}\n";
    } else {
        $failures[] = $description;
        fwrite(STDERR, "FAIL - {$description}\n");
    }
}
function throws(callable $operation, string $description, string $class = Throwable::class): void
{
    try {
        $operation();
        verify(false, $description);
    } catch (Throwable $error) {
        verify($error instanceof $class, $description . ($error instanceof $class ? '' : ' (unexpected ' . get_class($error) . ')'));
    }
}
function scalar(PDO $db, string $sql, array $args = []): mixed
{
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchColumn();
}
function migrateTestSchema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        $version = basename($file);
        $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE version=?');
        $check->execute([$version]);
        if ($check->fetchColumn()) continue;
        $sql = file_get_contents($file);
        if (!is_string($sql)) throw new RuntimeException('Cannot read migration: ' . $version);
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $db->exec($statement);
        }
        $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP())')->execute([$version]);
    }
}
function testSender(): DeliverySender
{
    return new class implements DeliverySender {
        public function send(array $payload, string $idempotencyKey): array { return ['message_id' => 'php-test']; }
    };
}

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
migrateTestSchema($db);
$prefix = 'php-suite-' . bin2hex(random_bytes(6));
$userIds = [];
$foreignUserIds = [];
$childProcesses = [];

try {
    $auth = new Auth($db);
    $ownerEmail = $prefix . '@example.test';
    $ownerId = $auth->register('Owner Tester', $ownerEmail, 'Strong-test-password-42');
    $userIds[] = $ownerId;
    $otherId = $auth->register('Other Tester', 'other-' . $prefix . '@example.test', 'Other-test-password-42');
    $userIds[] = $otherId;
    $db->prepare("UPDATE users SET daily_limit=100,timezone='UTC' WHERE id IN (?,?)")->execute([$ownerId, $otherId]);

    // Authentication and role enforcement.
    $loggedIn = $auth->login('  ' . strtoupper($ownerEmail) . ' ', 'Strong-test-password-42');
    verify((int)$loggedIn['id'] === $ownerId && $loggedIn['role'] === 'operator' && !isset($loggedIn['password_hash']), 'authentication normalizes email and returns a safe session record');
    throws(fn() => $auth->login($ownerEmail, 'incorrect-password'), 'invalid password is rejected', RuntimeException::class);
    $adminId = $db->prepare("INSERT INTO users(name,email,password_hash,role,status,daily_limit,timezone,created_at,updated_at) VALUES(?,?,?,'admin','active',100,'UTC',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $adminId->execute(['Admin Tester', 'admin-' . $prefix . '@example.test', password_hash('Admin-test-password-42', PASSWORD_DEFAULT)]);
    $adminUserId = (int)$db->lastInsertId();
    $userIds[] = $adminUserId;
    $admin = new AdminService($db);
    verify($admin->users($adminUserId, 1, 1)['total'] >= 3, 'active administrator can use administrator service');
    throws(fn() => $admin->users($ownerId), 'operator cannot use administrator service', RuntimeException::class);
    throws(fn() => $admin->updateUser($adminUserId, $adminUserId, ['role' => 'operator']), 'administrator cannot demote own role', RuntimeException::class);
    $suspendedId = $auth->register('Suspended Tester', 'suspended-' . $prefix . '@example.test', 'Suspended-test-password-42');
    $userIds[] = $suspendedId;
    $db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$suspendedId]);
    throws(fn() => $auth->login('suspended-' . $prefix . '@example.test', 'Suspended-test-password-42'), 'suspended account cannot authenticate', RuntimeException::class);

    // Tenant-scoped contacts, groups, E.164 normalization and consent.
    $normalizer = new PhoneNormalizer('GH');
    $contacts = new ContactService($db, $normalizer);
    $groups = new GroupService($db);
    $contactId = $contacts->create($ownerId, ['phone' => '024 123 4567', 'name' => '  Alice  ', 'consent_status' => 'opted_in', 'consent_source' => 'web form']);
    $foreignContactId = $contacts->create($otherId, ['phone' => '+233241234568', 'name' => 'Bob', 'consent_status' => 'opted_in']);
    verify($contacts->find($ownerId, $contactId)['phone_e164'] === '+233241234567', 'local Ghana number normalizes to E.164 and trims contact name');
    verify($contacts->find($ownerId, $contactId)['consent_status'] === 'opted_in' && $contacts->find($ownerId, $contactId)['consented_at'] !== null, 'opt-in state records consent timestamp');
    verify($contacts->find($otherId, $contactId) === null && $contacts->delete($otherId, $contactId) === false, 'contact lookup and delete cannot cross tenant boundary');
    verify($contacts->search($ownerId, '')['total'] === 1 && $contacts->search($otherId, '')['total'] === 1, 'contact search is tenant-scoped');
    throws(fn() => $contacts->create($ownerId, ['phone' => '+233 24 123 4567', 'name' => 'Duplicate']), 'normalized duplicate contact is rejected', InvalidArgumentException::class);
    throws(fn() => $contacts->create($ownerId, ['phone' => '0241234567', 'consent_status' => 'yes']), 'invalid consent state is rejected', InvalidArgumentException::class);
    $unknownId = $contacts->create($ownerId, ['phone' => '0241234568', 'name' => 'No Consent']);
    verify($contacts->find($ownerId, $unknownId)['consent_status'] === 'unknown', 'contact without recorded opt-in defaults to unknown consent');
    verify($contacts->optOut($ownerId, $contactId) && $contacts->find($ownerId, $contactId)['unsubscribed_at'] !== null, 'opt-out marks contact unsubscribed');
    verify($contacts->update($ownerId, $contactId, ['consent_status' => 'opted_in', 'consent_source' => 'renewed consent']), 'contact can explicitly record renewed opt-in');

    $groupId = $groups->create($ownerId, 'Owner group');
    $foreignGroupId = $groups->create($otherId, 'Other group');
    verify($groups->setMembers($ownerId, $groupId, [$contactId, $foreignContactId, $contactId]) === 1, 'group membership deduplicates and ignores foreign contacts');
    verify(count($groups->members($ownerId, $groupId)) === 1 && $groups->members($otherId, $groupId) === [], 'group members are visible only to owning tenant');
    verify($groups->rename($otherId, $groupId, 'Stolen') === false && $groups->delete($otherId, $groupId) === false, 'group rename and delete cannot cross tenant boundary');
    throws(fn() => $groups->setMembers($otherId, $groupId, [$foreignContactId]), 'foreign group cannot be modified by another tenant', InvalidArgumentException::class);
    verify(count($groups->list($ownerId)) === 1 && count($groups->list($otherId)) === 1, 'group listing is tenant-scoped');

    // Ordered mixed-media template parts: rows represent valid safe stored metadata; no files are needed.
    $mediaIds = [];
    foreach ([['image', 'image/png'], ['audio', 'audio/ogg'], ['document', 'application/pdf']] as $i => [$type, $mime]) {
        $db->prepare('INSERT INTO media(user_id,storage_name,original_name,mime_type,size_bytes,media_type,created_at) VALUES(?,?,?,?,1,?,UTC_TIMESTAMP())')
            ->execute([$ownerId, bin2hex(random_bytes(24)) . '.' . ['png', 'ogg', 'pdf'][$i], 'safe-' . $i, $mime, $type]);
        $mediaIds[] = (int)$db->lastInsertId();
    }
    $templateMedia = new MediaService($db, dirname(__DIR__) . '/storage');
    $templateService = new TemplateService($db, $templateMedia);
    $db->prepare('INSERT INTO templates(user_id,name,language,created_at,updated_at) VALUES(?,?,\'en\',UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$ownerId, 'Mixed ordered template ' . $prefix]);
    $templateId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO template_parts(user_id,template_id,sequence_no,part_type,text_content,media_id,caption,created_at) VALUES(?,?,1,\'text\',\'First\',NULL,NULL,UTC_TIMESTAMP()),(?,?,2,\'image\',NULL,?,\'Picture\',UTC_TIMESTAMP()),(?,?,3,\'audio\',NULL,?,NULL,UTC_TIMESTAMP()),(?,?,4,\'document\',NULL,?,\'Report\',UTC_TIMESTAMP())')
        ->execute([$ownerId, $templateId, $ownerId, $templateId, $mediaIds[0], $ownerId, $templateId, $mediaIds[1], $ownerId, $templateId, $mediaIds[2]]);
    $template = $templateService->get($ownerId, $templateId);
    $partTypes = array_column($template['parts'], 'type');
    verify($partTypes === ['text', 'image', 'audio', 'document'] && array_column($template['parts'], 'sequence') === [1, 2, 3, 4], 'template service preserves ordered mixed-media parts');
    verify($templateService->get($otherId, $template['id']) === null, 'template lookup cannot expose another tenant template');
    throws(fn() => $templateService->validate($ownerId, ['name' => 'Invalid order', 'parts' => [['sequence' => 2, 'type' => 'text', 'text' => 'x']]]), 'template requires contiguous part order', InvalidArgumentException::class);

    // Two opted-in recipients produce one quota reservation/job for every part.
    $optedTwo = $contacts->create($ownerId, ['phone' => '0241234569', 'name' => 'Carol', 'consent_status' => 'opted_in']);
    $scheduled = new DateTimeImmutable('tomorrow 10:00:00', new DateTimeZone('UTC'));
    $campaign = (new CampaignService($db))->create(['user_id' => $ownerId, 'template_id' => $template['id'], 'name' => 'Quota test ' . $prefix, 'contact_ids' => [$contactId, $contactId, $unknownId, $optedTwo], 'scheduled_for' => $scheduled]);
    verify($campaign['recipient_count'] === 2 && $campaign['excluded_count'] === 1 && $campaign['part_count'] === 4, 'campaign snapshots opted-in unique recipients and excludes unknown consent');
    $jobsQ = $db->prepare('SELECT j.id,j.recipient_id,j.campaign_part_id,j.reservation_id,j.idempotency_key,j.status,cp.sequence_no FROM delivery_jobs j JOIN campaign_parts cp ON cp.id=j.campaign_part_id WHERE j.campaign_id=? ORDER BY j.id');
    $jobsQ->execute([$campaign['campaign_id']]);
    $jobs = $jobsQ->fetchAll(PDO::FETCH_ASSOC);
    verify(count($jobs) === 8 && count(array_unique(array_column($jobs, 'idempotency_key'))) === 8, 'campaign creates distinct idempotent jobs for each recipient × part');
    verify(count(array_unique(array_column($jobs, 'reservation_id'))) === 8 && (int)scalar($db, 'SELECT COUNT(*) FROM quota_reservations WHERE campaign_id=?', [$campaign['campaign_id']]) === 8, 'exactly one quota reservation exists for each recipient × part');
    $quotaDay = $scheduled->format('Y-m-d');
    verify((int)scalar($db, 'SELECT reserved_count FROM quota_days WHERE user_id=? AND quota_day=?', [$ownerId, $quotaDay]) === 8, 'quota bucket counts each recipient × part reservation once');
    $firstJob = $jobs[0];
    $db->beginTransaction();
    $sameReservation = (new QuotaService($db))->reserve($ownerId, new DateTimeImmutable($quotaDay, new DateTimeZone('UTC')), 100, (int)$campaign['campaign_id'], (int)$firstJob['recipient_id'], (int)$firstJob['campaign_part_id']);
    $db->commit();
    verify($sameReservation === (int)$firstJob['reservation_id'] && (int)scalar($db, 'SELECT reserved_count FROM quota_days WHERE user_id=? AND quota_day=?', [$ownerId, $quotaDay]) === 8, 're-reserving same recipient × part is idempotent and does not spend quota twice');

    // Cancellation releases once; retry preserves job identity and restores quota once.
    $worker = new DeliveryWorker($db, testSender());
    verify($worker->cancel((int)$firstJob['id']) === true && $worker->cancel((int)$firstJob['id']) === false, 'queued job cancellation is idempotent');
    verify((int)scalar($db, 'SELECT reserved_count FROM quota_days WHERE user_id=? AND quota_day=?', [$ownerId, $quotaDay]) === 7, 'cancellation releases quota exactly once');
    $retryJob = $jobs[1];
    $retryKey = $retryJob['idempotency_key'];
    $db->prepare("UPDATE delivery_jobs SET status='failed' WHERE id=?")->execute([(int)$retryJob['id']]);
    $db->prepare("UPDATE campaign_recipients SET status='failed' WHERE id=?")->execute([(int)$retryJob['recipient_id']]);
    $retryQuota = new QuotaService($db);
    verify($retryQuota->release((int)$retryJob['reservation_id']), 'failed attempt releases its quota reservation before manual retry');
    $beforeRetry = (int)scalar($db, 'SELECT reserved_count FROM quota_days WHERE user_id=? AND quota_day=?', [$ownerId, $quotaDay]);
    verify($worker->retry((int)$retryJob['id']) === true && $worker->retry((int)$retryJob['id']) === false, 'manual failed-job retry succeeds once and is idempotent');
    verify((string)scalar($db, 'SELECT idempotency_key FROM delivery_jobs WHERE id=?', [(int)$retryJob['id']]) === $retryKey && scalar($db, 'SELECT state FROM quota_reservations WHERE id=?', [(int)$retryJob['reservation_id']]) === 'reserved', 'retry retains stable job key and reactivates same reservation');
    verify((int)scalar($db, 'SELECT reserved_count FROM quota_days WHERE user_id=? AND quota_day=?', [$ownerId, $quotaDay]) === $beforeRetry + 1, 'retry restores quota only once');

    // Validly signed callbacks apply once; duplicate event IDs and invalid signatures reject.
    $callbackJob = $jobs[2];
    $callback = new CallbackService($db, 'php-suite-callback-secret', 300);
    $body = json_encode(['event_id' => $prefix . '-event', 'job_id' => (int)$callbackJob['id'], 'status' => 'accepted', 'provider_message_id' => 'provider-test'], JSON_THROW_ON_ERROR);
    $timestamp = (string)time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'php-suite-callback-secret');
    verify($callback->handle($body, $timestamp, 'sha256=' . $signature) === true, 'valid HMAC callback applies');
    verify($callback->handle($body, $timestamp, $signature) === false, 'callback event replay is deduplicated');
    $invalidBody = json_encode(['event_id' => $prefix . '-bad', 'job_id' => (int)$callbackJob['id'], 'status' => 'accepted'], JSON_THROW_ON_ERROR);
    throws(fn() => $callback->handle($invalidBody, $timestamp, hash_hmac('sha256', $timestamp . '.' . $invalidBody, 'wrong-secret')), 'callback with invalid signature is rejected', RuntimeException::class);
    $oldTimestamp = (string)(time() - 301);
    throws(fn() => $callback->handle($invalidBody, $oldTimestamp, hash_hmac('sha256', $oldTimestamp . '.' . $invalidBody, 'php-suite-callback-secret')), 'callback outside timestamp window is rejected', RuntimeException::class);
    verify(scalar($db, 'SELECT status FROM delivery_jobs WHERE id=?', [(int)$callbackJob['id']]) === 'accepted' && scalar($db, 'SELECT state FROM quota_reservations WHERE id=?', [(int)$callbackJob['reservation_id']]) === 'consumed', 'accepted callback consumes its quota reservation');

    // True two-process contention over one user's daily limit (InnoDB bucket lock).
    $raceOwnerId = $auth->register('Race Tester', 'race-' . $prefix . '@example.test', 'Race-test-password-42');
    $userIds[] = $raceOwnerId;
    $db->prepare("UPDATE users SET daily_limit=1,timezone='UTC' WHERE id=?")->execute([$raceOwnerId]);
    $raceContactId = $contacts->create($raceOwnerId, ['phone' => '0241234570', 'name' => 'Race recipient', 'consent_status' => 'opted_in']);
    $db->prepare('INSERT INTO templates(user_id,name,language,created_at,updated_at) VALUES(?,?,\'en\',UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$raceOwnerId, 'Race template ' . $prefix]);
    $raceTemplateId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO template_parts(user_id,template_id,sequence_no,part_type,text_content,created_at) VALUES(?,?,1,'text','race',UTC_TIMESTAMP())")->execute([$raceOwnerId, $raceTemplateId]);
    $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix;
    if (!mkdir($barrier)) throw new RuntimeException('Could not create concurrency-test barrier.');
    $releaseFile = $barrier . DIRECTORY_SEPARATOR . 'release';
    $readyFiles = [];
    for ($i = 0; $i < 2; $i++) {
        $readyFiles[$i] = $barrier . DIRECTORY_SEPARATOR . 'ready-' . $i;
        $command = [PHP_BINARY, __DIR__ . '/reserve-contender.php', (string)$raceOwnerId, (string)$raceTemplateId, (string)$raceContactId, $scheduled->format(DATE_ATOM), $releaseFile, $readyFiles[$i]];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($proc)) throw new RuntimeException('Could not start concurrency contender.');
        fclose($pipes[0]);
        $childProcesses[] = ['proc' => $proc, 'pipes' => $pipes];
    }
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && (!is_file($readyFiles[0]) || !is_file($readyFiles[1]))) usleep(10000);
    if (!is_file($readyFiles[0]) || !is_file($readyFiles[1])) throw new RuntimeException('Concurrency contenders did not reach the start barrier.');
    file_put_contents($releaseFile, 'go');
    $outcomes = [];
    foreach ($childProcesses as $child) {
        $stdout = stream_get_contents($child['pipes'][1]);
        $stderr = stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        $status = proc_close($child['proc']);
        $childProcesses = array_slice($childProcesses, 1);
        $outcome = json_decode((string)$stdout, true);
        if ($status !== 0 || !is_array($outcome)) throw new RuntimeException('Concurrency child failed: ' . trim((string)$stderr . ' ' . (string)$stdout));
        $outcomes[] = $outcome;
    }
    verify(count(array_filter($outcomes, static fn(array $outcome): bool => ($outcome['ok'] ?? false) === true)) === 1 && count(array_filter($outcomes, static fn(array $outcome): bool => ($outcome['quota_exhausted'] ?? false) === true)) === 1, 'concurrent campaign reservations enforce a one-slot daily quota exactly once');
    verify((int)scalar($db, 'SELECT reserved_count FROM quota_days WHERE user_id=? AND quota_day=?', [$raceOwnerId, $quotaDay]) === 1, 'concurrent quota bucket counter remains exactly one');
    @unlink($releaseFile);
    foreach ($readyFiles as $readyFile) @unlink($readyFile);
    @rmdir($barrier);
} catch (Throwable $error) {
    $failures[] = 'Unhandled test error: ' . $error->getMessage();
    fwrite(STDERR, "ERROR - " . get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
} finally {
    foreach ($childProcesses as $child) {
        foreach ([1, 2] as $pipe) if (isset($child['pipes'][$pipe]) && is_resource($child['pipes'][$pipe])) fclose($child['pipes'][$pipe]);
        if (isset($child['proc']) && is_resource($child['proc'])) proc_terminate($child['proc']);
    }
    if ($userIds) {
        $marks = implode(',', array_fill(0, count($userIds), '?'));
        // Delete event rows with no remaining job (FK uses SET NULL), then account rows cascade.
        $db->prepare('DELETE FROM webhook_events WHERE event_id LIKE ?')->execute([$prefix . '%']);
        $db->prepare("DELETE FROM users WHERE id IN ({$marks})")->execute($userIds);
    }
    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix);
}

if ($failures) {
    fwrite(STDERR, sprintf("\nFAILED: %d of %d checks failed.\n", count($failures), $checks));
    exit(1);
}
echo sprintf("\nPASS: all %d checks passed against whatsup_test.\n", $checks);
