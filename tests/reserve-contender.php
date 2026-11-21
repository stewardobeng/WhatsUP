<?php
declare(strict_types=1);

/** Child process for run.php's two-process InnoDB quota contention test. */
if (PHP_SAPI !== 'cli' || $argc !== 7) {
    fwrite(STDERR, "Usage: php reserve-contender.php user template contact scheduled release-file ready-file\n");
    exit(2);
}
$dsn = getenv('WHATSUP_TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=whatsup_test;charset=utf8mb4';
if (!preg_match('/(?:^|;)dbname=whatsup_test(?:;|$)/i', $dsn)) {
    fwrite(STDERR, "Refusing non-test database.\n");
    exit(2);
}
putenv('DB_DSN=' . $dsn);
putenv('DB_USER=' . (getenv('WHATSUP_TEST_DB_USER') !== false ? getenv('WHATSUP_TEST_DB_USER') : 'root'));
putenv('DB_PASSWORD=' . (getenv('WHATSUP_TEST_DB_PASSWORD') !== false ? getenv('WHATSUP_TEST_DB_PASSWORD') : ''));
require dirname(__DIR__) . '/app/bootstrap.php';

use WhatsUP\Core\Database;
use WhatsUP\Services\CampaignService;

$userId = (int)$argv[1];
$templateId = (int)$argv[2];
$contactId = (int)$argv[3];
$scheduled = new DateTimeImmutable($argv[4]);
$releaseFile = $argv[5];
$readyFile = $argv[6];
$db = Database::connection();
file_put_contents($readyFile, 'ready');
$deadline = microtime(true) + 15;
while (!is_file($releaseFile) && microtime(true) < $deadline) usleep(10000);
if (!is_file($releaseFile)) {
    fwrite(STDERR, "Start barrier timed out.\n");
    exit(3);
}
try {
    $result = (new CampaignService($db))->create([
        'user_id' => $userId,
        'template_id' => $templateId,
        'name' => 'Concurrent reservation',
        'contact_ids' => [$contactId],
        'scheduled_for' => $scheduled,
    ]);
    echo json_encode(['ok' => true, 'campaign_id' => $result['campaign_id']], JSON_THROW_ON_ERROR), "\n";
    exit(0);
} catch (RuntimeException $error) {
    if (str_contains($error->getMessage(), 'Daily quota exhausted')) {
        echo json_encode(['ok' => false, 'quota_exhausted' => true], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
