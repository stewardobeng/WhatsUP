<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use WhatsUP\Core\Config;
use WhatsUP\Core\Database;
use WhatsUP\Services\AccessCodeService;
use WhatsUP\Services\CampaignService;
use WhatsUP\Services\ContactService;
use WhatsUP\Services\MediaService;
use WhatsUP\Services\PhoneNormalizer;
use WhatsUP\Services\TemplateService;
use WhatsUP\Services\WebhookClient;
use WhatsUP\Worker\DeliveryWorker;

if (Config::get('APP_ENV') !== 'local' || !Config::bool('SIMULATOR_ENABLED', false)) {
    fwrite(STDERR, "Refusing: APP_ENV=local and SIMULATOR_ENABLED=true are required.\n");
    exit(2);
}
$endpoint = (string) Config::required('WEBHOOK_URL');
$host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Refusing: local simulator endpoint must use localhost.\n");
    exit(2);
}

$db = Database::connection();
$email = 'local-e2e-' . bin2hex(random_bytes(8)) . '@example.test';
$userId = 0;
try {
    $db->prepare("INSERT INTO users(name,email,password_hash,role,status,daily_limit,timezone,created_at,updated_at) VALUES('Local E2E Fixture',?,?, 'operator','active',100,'UTC',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
    $userId = (int) $db->lastInsertId();

    $contacts = new ContactService($db, new PhoneNormalizer((string) Config::get('DEFAULT_REGION', 'GH')));
    $contactId = $contacts->create($userId, [
        'name' => 'Synthetic Local Test Recipient',
        'phone' => '+233201000001',
        'consent_status' => 'opted_in',
        'consent_source' => 'Local E2E test fixture',
    ]);
    $media = new MediaService($db, dirname(__DIR__) . '/storage', (int) Config::get('MAX_UPLOAD_BYTES', '15728640'));
    $templates = new TemplateService($db, $media);
    $template = $templates->create($userId, [
        'name' => 'Local E2E ' . bin2hex(random_bytes(3)),
        'language' => 'en',
        'parts' => [['sequence' => 1, 'type' => 'text', 'text' => 'Synthetic local simulator verification.']],
    ]);

    $codes = new AccessCodeService($db);
    $issued = $codes->issue('Local E2E test', [$userId], (new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'))));
    if (!$codes->redeem($issued['code'], $userId) || $codes->redeem($issued['code'], $userId)) {
        throw new RuntimeException('Single-use access-code verification failed.');
    }

    $result = (new CampaignService($db))->create([
        'user_id' => $userId,
        'template_id' => $template['id'],
        'name' => 'Local simulator E2E',
        'contact_ids' => [$contactId],
    ]);
    if ($result['recipient_count'] !== 1 || $result['part_count'] !== 1) {
        throw new RuntimeException('Campaign did not reserve the expected recipient and part.');
    }

    $sender = new WebhookClient(
        $endpoint,
        (string) Config::required('WEBHOOK_SECRET'),
        15,
        Config::bool('WEBHOOK_ALLOW_HTTP_LOCAL', false),
    );
    $worker = new DeliveryWorker($db, $sender, (int) Config::get('WORKER_MAX_ATTEMPTS', '6'));
    if (!$worker->runOne()) {
        throw new RuntimeException('Worker did not claim the test campaign job.');
    }
    $q = $db->prepare('SELECT j.status job_status,r.status recipient_status FROM delivery_jobs j JOIN campaign_recipients r ON r.id=j.recipient_id WHERE j.campaign_id=?');
    $q->execute([$result['campaign_id']]);
    $status = $q->fetch();
    if (!$status || $status['job_status'] !== 'delivered' || $status['recipient_status'] !== 'delivered') {
        throw new RuntimeException('Simulator/callback did not mark both job and recipient delivered: ' . json_encode($status));
    }
    echo "PASS: access code, campaign reservation, signed Apache simulator request, signed callback, and delivered status verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($userId > 0) {
        $delete = $db->prepare('DELETE FROM users WHERE id=? AND email=?');
        $delete->execute([$userId, $email]);
    }
}
