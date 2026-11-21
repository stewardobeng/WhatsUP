<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Builds an immutable audience/part snapshot and reserves quota atomically. */
final class CampaignService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Input: user_id, template_id, name, contact_ids?, group_ids?, exclude_contact_ids?, scheduled_for?.
     * Returns campaign_id, recipient_count, excluded_count and part_count.
     */
    public function create(array $input): array
    {
        $userId = (int)($input['user_id'] ?? 0);
        $templateId = (int)($input['template_id'] ?? 0);
        if ($userId < 1 || $templateId < 1) throw new RuntimeException('user_id and template_id are required.');
        $scheduled = $input['scheduled_for'] ?? null;
        if ($scheduled !== null && !$scheduled instanceof DateTimeImmutable) throw new RuntimeException('scheduled_for must be DateTimeImmutable or null.');
        if ($scheduled && $scheduled->getTimestamp() < time()) throw new RuntimeException('scheduled_for must be in the future.');
        $at = ($scheduled ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));

        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $userQ = $this->pdo->prepare("SELECT daily_limit,timezone,status FROM users WHERE id=? FOR UPDATE");
            $userQ->execute([$userId]);
            $user = $userQ->fetch(PDO::FETCH_ASSOC);
            if (!$user || $user['status'] !== 'active') throw new RuntimeException('Active user not found.');

            $templateQ = $this->pdo->prepare('SELECT id FROM templates WHERE id=? AND user_id=?');
            $templateQ->execute([$templateId, $userId]);
            if (!$templateQ->fetchColumn()) throw new RuntimeException('Template does not belong to this user.');
            $partsQ = $this->pdo->prepare('SELECT id,sequence_no,part_type,text_content,media_id,caption FROM template_parts WHERE template_id=? AND user_id=? ORDER BY sequence_no');
            $partsQ->execute([$templateId, $userId]);
            $parts = $partsQ->fetchAll(PDO::FETCH_ASSOC);
            if (!$parts) throw new RuntimeException('Template has no parts.');

            $this->pdo->prepare('INSERT INTO campaigns(user_id,template_id,name,status,scheduled_for,recipient_count,excluded_count,part_count,quota_day,created_at,updated_at) VALUES(?,?,?,?,?,0,0,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
                ->execute([$userId, $templateId, (string)($input['name'] ?? 'Campaign'), 'queued', $scheduled?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), count($parts), $at->format('Y-m-d')]);
            $campaignId = (int)$this->pdo->lastInsertId();
            try {
                $localTimezone = new DateTimeZone((string)$user['timezone']);
                $quotaDay = new DateTimeImmutable($at->setTimezone($localTimezone)->format('Y-m-d'), new DateTimeZone('UTC'));
            } catch (Throwable $e) {
                throw new RuntimeException('Invalid user timezone configured.', 0, $e);
            }

            $targetContactIds = $this->cleanIds($input['contact_ids'] ?? []);
            $groupIds = $this->cleanIds($input['group_ids'] ?? []);
            foreach ($targetContactIds as $id) $this->pdo->prepare("INSERT INTO campaign_targets(campaign_id,target_type,target_id) VALUES(?,'contact',?)")->execute([$campaignId, $id]);
            foreach ($groupIds as $id) $this->pdo->prepare("INSERT INTO campaign_targets(campaign_id,target_type,target_id) VALUES(?,'group',?)")->execute([$campaignId, $id]);

            $contacts = $this->snapshot($userId, $targetContactIds, $groupIds);
            $exclude = array_fill_keys($this->cleanIds($input['exclude_contact_ids'] ?? []), true);
            foreach ($parts as $part) {
                if ($part['media_id'] !== null) {
                    $m = $this->pdo->prepare('SELECT id FROM media WHERE id=? AND user_id=?');
                    $m->execute([(int)$part['media_id'], $userId]);
                    if (!$m->fetchColumn()) throw new RuntimeException('Template references media not owned by this user.');
                }
                $this->pdo->prepare('INSERT INTO campaign_parts(campaign_id,template_part_id,sequence_no,part_type,text_content,media_id,caption) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$campaignId, (int)$part['id'], (int)$part['sequence_no'], $part['part_type'], $part['text_content'], $part['media_id'], $part['caption']]);
            }
            $cpQ = $this->pdo->prepare('SELECT id,sequence_no FROM campaign_parts WHERE campaign_id=? ORDER BY sequence_no');
            $cpQ->execute([$campaignId]);
            $campaignParts = $cpQ->fetchAll(PDO::FETCH_ASSOC);

            $excludedCount = 0;
            $recipientCount = 0;
            $quota = new QuotaService($this->pdo);
            foreach ($contacts as $contact) {
                $reason = null;
                if (isset($exclude[$contact['id']])) $reason = 'excluded';
                elseif ($contact['consent_status'] === 'unsubscribed') $reason = 'suppressed';
                elseif ($contact['consent_status'] !== 'opted_in') $reason = 'no_consent';
                if ($reason !== null) { $excludedCount++; continue; }

                $this->pdo->prepare("INSERT INTO campaign_recipients(campaign_id,user_id,contact_id,phone_e164,display_name,status,created_at,updated_at) VALUES(?,?,?,?,?,'queued',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                    ->execute([$campaignId, $userId, $contact['id'], $contact['phone_e164'], $contact['name']]);
                $recipientId = (int)$this->pdo->lastInsertId();
                $recipientCount++;
                foreach ($campaignParts as $index => $cp) {
                    $reservationId = $quota->reserve($userId, $quotaDay, (int)$user['daily_limit'], $campaignId, $recipientId, (int)$cp['id']);
                    $jobKey = hash('sha256', $campaignId . ':' . $recipientId . ':' . $cp['id']);
                    $this->pdo->prepare("INSERT INTO delivery_jobs(campaign_id,recipient_id,campaign_part_id,reservation_id,status,attempts,max_attempts,available_at,idempotency_key,created_at,updated_at) VALUES(?,?,?,?,'queued',0,6,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                        ->execute([$campaignId, $recipientId, (int)$cp['id'], $reservationId, $at->format('Y-m-d H:i:s'), $jobKey]);
                }
            }
            $this->pdo->prepare('UPDATE campaigns SET recipient_count=?,excluded_count=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$recipientCount, $excludedCount, $campaignId]);
            if ($own) $this->pdo->commit();
            return ['campaign_id' => $campaignId, 'recipient_count' => $recipientCount, 'excluded_count' => $excludedCount, 'part_count' => count($campaignParts)];
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array{id:int,phone_e164:string,name:string,consent_status:string}> */
    private function snapshot(int $userId, array $contactIds, array $groupIds): array
    {
        $union = [];
        if ($contactIds !== []) {
            $in = implode(',', array_fill(0, count($contactIds), '?'));
            $s = $this->pdo->prepare("SELECT id FROM contacts WHERE user_id=? AND id IN ({$in})");
            $s->execute(array_merge([$userId], $contactIds));
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $union[(int)$id] = true;
        }
        if ($groupIds !== []) {
            $in = implode(',', array_fill(0, count($groupIds), '?'));
            $s = $this->pdo->prepare("SELECT DISTINCT c.id FROM contacts c JOIN group_contacts gc ON gc.contact_id=c.id AND gc.user_id=c.user_id JOIN groups g ON g.id=gc.group_id AND g.user_id=c.user_id WHERE c.user_id=? AND g.id IN ({$in})");
            $s->execute(array_merge([$userId], $groupIds));
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $union[(int)$id] = true;
        }
        if (!$union) return [];
        $ids = array_keys($union);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->pdo->prepare("SELECT id,phone_e164,name,consent_status FROM contacts WHERE user_id=? AND id IN ({$in}) ORDER BY id");
        $s->execute(array_merge([$userId], $ids));
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Account/user local allowed send time defaults to 08:00–20:00. */
    private static function inWindow(DateTimeImmutable $at, string $timezone): bool
    {
        try { $hour = (int)$at->setTimezone(new DateTimeZone($timezone))->format('G'); }
        catch (Throwable) { $hour = (int)$at->setTimezone(new DateTimeZone('UTC'))->format('G'); }
        return $hour >= 8 && $hour < 20;
    }

    private function cleanIds(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
    }

    public function cancel(int $userId, int $campaignId): int
    {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT id FROM campaigns WHERE id=? AND user_id=? FOR UPDATE');
            $q->execute([$campaignId, $userId]);
            if (!$q->fetchColumn()) throw new RuntimeException('Campaign not found.');
            $jobs = $this->pdo->prepare("SELECT id,reservation_id FROM delivery_jobs WHERE campaign_id=? AND status IN ('queued','retry_wait') FOR UPDATE");
            $jobs->execute([$campaignId]);
            $rows = $jobs->fetchAll(PDO::FETCH_ASSOC);
            $quota = new QuotaService($this->pdo);
            foreach ($rows as $row) {
                $this->pdo->prepare("UPDATE delivery_jobs SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('queued','retry_wait')")->execute([$row['id']]);
                $quota->release((int)$row['reservation_id']);
            }
            $this->pdo->prepare("UPDATE campaigns SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$campaignId]);
            $this->pdo->commit();
            return count($rows);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function progress(int $userId, int $campaignId): array
    {
        $owner = $this->pdo->prepare('SELECT id FROM campaigns WHERE id=? AND user_id=?');
        $owner->execute([$campaignId, $userId]);
        if (!$owner->fetchColumn()) throw new RuntimeException('Campaign not found.');
        $q = $this->pdo->prepare('SELECT status,COUNT(*) AS count FROM delivery_jobs WHERE campaign_id=? GROUP BY status');
        $q->execute([$campaignId]);
        $result = array_fill_keys(['queued','sending','accepted','delivered','failed','retry_wait','cancelled'], 0);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $result[$row['status']] = (int)$row['count'];
        return $result;
    }
}
