<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** CRUD and validation for user-owned templates using database/migrations/001_initial.sql. */
final class TemplateService
{
    private const PART_TYPES = ['text', 'image', 'audio', 'document'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly MediaService $media,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(int $userId): array
    {
        $this->assertUserId($userId);
        $stmt = $this->pdo->prepare('SELECT id, user_id, name, language, provider_template_name, created_at, updated_at FROM templates WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        return array_map(fn(array $row): array => $this->decodeTemplate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Foreign and missing template ids are indistinguishable. */
    public function get(int $userId, int $templateId): ?array
    {
        $this->assertUserId($userId);
        $this->assertTemplateId($templateId);
        $stmt = $this->pdo->prepare('SELECT id, user_id, name, language, provider_template_name, created_at, updated_at FROM templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $userId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($template)) {
            return null;
        }
        $template = $this->decodeTemplate($template);
        $parts = $this->pdo->prepare('SELECT id, sequence_no, part_type, text_content, media_id, caption FROM template_parts WHERE template_id = ? AND user_id = ? ORDER BY sequence_no ASC');
        $parts->execute([$templateId, $userId]);
        $template['parts'] = array_map(static fn(array $part): array => [
            'id' => (int) $part['id'],
            'sequence' => (int) $part['sequence_no'],
            'type' => (string) $part['part_type'],
            'text' => $part['text_content'],
            'media_id' => $part['media_id'] === null ? null : (int) $part['media_id'],
            'caption' => $part['caption'],
        ], $parts->fetchAll(PDO::FETCH_ASSOC));
        return $template;
    }

    /** Create a template after validating the complete ordered part list. */
    public function create(int $userId, array $data): array
    {
        $this->assertUserId($userId);
        $template = $this->normalizeTemplate($data);
        $parts = $this->normalizeParts($userId, $data['parts'] ?? null);
        $id = $this->transaction(function () use ($userId, $template, $parts): int {
            $stmt = $this->pdo->prepare('INSERT INTO templates (user_id, name, language, provider_template_name, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            $stmt->execute([$userId, $template['name'], $template['language'], $template['provider_template_name']]);
            $id = (int) $this->pdo->lastInsertId();
            $this->insertParts($userId, $id, $parts);
            return $id;
        });
        return $this->get($userId, $id) ?? throw new RuntimeException('Created template could not be loaded.');
    }

    /** Replace metadata and the complete ordered parts list. */
    public function update(int $userId, int $templateId, array $data): ?array
    {
        $this->assertUserId($userId);
        $this->assertTemplateId($templateId);
        if ($this->get($userId, $templateId) === null) {
            return null;
        }
        $template = $this->normalizeTemplate($data);
        $parts = $this->normalizeParts($userId, $data['parts'] ?? null);
        $this->transaction(function () use ($userId, $templateId, $template, $parts): void {
            $stmt = $this->pdo->prepare('UPDATE templates SET name = ?, language = ?, provider_template_name = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?');
            $stmt->execute([$template['name'], $template['language'], $template['provider_template_name'], $templateId, $userId]);
            $this->pdo->prepare('DELETE FROM template_parts WHERE template_id = ? AND user_id = ?')->execute([$templateId, $userId]);
            $this->insertParts($userId, $templateId, $parts);
        });
        return $this->get($userId, $templateId);
    }

    /** Duplicate an owned template, preserving provider name and part order. */
    public function duplicate(int $userId, int $templateId, ?string $newName = null): ?array
    {
        $source = $this->get($userId, $templateId);
        if ($source === null) {
            return null;
        }
        return $this->create($userId, [
            'name' => $newName ?? ($source['name'] . ' (copy)'),
            'language' => $source['language'],
            'provider_template_name' => $source['provider_template_name'],
            'parts' => $source['parts'],
        ]);
    }

    /** Delete an owned template. Template-part references cascade; media itself is not implicitly removed. */
    public function delete(int $userId, int $templateId): bool
    {
        $this->assertUserId($userId);
        $this->assertTemplateId($templateId);
        $stmt = $this->pdo->prepare('DELETE FROM templates WHERE id = ? AND user_id = ?');
        $stmt->execute([$templateId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Return render-ready preview data, including validated owned-media metadata. */
    public function preview(int $userId, int $templateId): ?array
    {
        $template = $this->get($userId, $templateId);
        if ($template === null) {
            return null;
        }
        $parts = [];
        foreach ($template['parts'] as $part) {
            $item = ['sequence' => $part['sequence'], 'type' => $part['type']];
            if ($part['type'] === 'text') {
                $item['text'] = $part['text'];
            } else {
                $item['media'] = $this->media->metadata($userId, (int) $part['media_id']);
                $item['caption'] = $part['caption'];
            }
            $parts[] = $item;
        }
        return [
            'id' => $template['id'],
            'name' => $template['name'],
            'language' => $template['language'],
            'provider_template_name' => $template['provider_template_name'],
            'parts' => $parts,
        ];
    }

    /** Validate and normalize template input without writing it. */
    public function validate(int $userId, array $data): array
    {
        $this->assertUserId($userId);
        $template = $this->normalizeTemplate($data);
        $template['parts'] = $this->normalizeParts($userId, $data['parts'] ?? null);
        return $template;
    }

    private function normalizeTemplate(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $language = trim((string) ($data['language'] ?? 'en'));
        $providerName = isset($data['provider_template_name']) ? trim((string) $data['provider_template_name']) : null;
        if ($name === '' || mb_strlen($name, 'UTF-8') > 140) {
            throw new InvalidArgumentException('Template name is required and must be at most 140 characters.');
        }
        if (!preg_match('/\A[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]{2,8})*\z/D', $language) || strlen($language) > 16) {
            throw new InvalidArgumentException('A valid language code of at most 16 characters is required.');
        }
        if ($providerName !== null && ($providerName === '' || mb_strlen($providerName, 'UTF-8') > 120)) {
            throw new InvalidArgumentException('Provider template name must be null or 1–120 characters.');
        }
        return ['name' => $name, 'language' => $language, 'provider_template_name' => $providerName];
    }

    /** @return list<array{sequence:int,type:string,text:?string,media_id:?int,caption:?string}> */
    private function normalizeParts(int $userId, mixed $parts): array
    {
        if (!is_array($parts) || $parts === [] || !array_is_list($parts)) {
            throw new InvalidArgumentException('Template parts must be a non-empty ordered list.');
        }
        $normalized = [];
        foreach ($parts as $index => $part) {
            if (!is_array($part)) {
                throw new InvalidArgumentException('Each template part must be an object.');
            }
            $sequence = $part['sequence'] ?? ($index + 1);
            if (!is_int($sequence) || $sequence !== $index + 1) {
                throw new InvalidArgumentException('Template part sequence must be contiguous and start at 1.');
            }
            $type = $part['type'] ?? $part['part_type'] ?? null;
            if (!is_string($type) || !in_array($type, self::PART_TYPES, true)) {
                throw new InvalidArgumentException('Template part type must be text, image, audio, or document.');
            }
            $caption = isset($part['caption']) ? trim((string) $part['caption']) : null;
            if ($caption !== null && mb_strlen($caption, 'UTF-8') > 1024) {
                throw new InvalidArgumentException('Part captions must be at most 1024 characters.');
            }
            if ($type === 'text') {
                $text = trim((string) ($part['text'] ?? $part['text_content'] ?? ''));
                if ($text === '' || mb_strlen($text, 'UTF-8') > 65535) {
                    throw new InvalidArgumentException('Text parts must be non-empty and fit the database text column.');
                }
                if ($caption !== null && $caption !== '') {
                    throw new InvalidArgumentException('Captions are only valid for media parts.');
                }
                $normalized[] = ['sequence' => $sequence, 'type' => $type, 'text' => $text, 'media_id' => null, 'caption' => null];
                continue;
            }
            $mediaId = $part['media_id'] ?? null;
            if (!is_int($mediaId) || !$this->media->owns($userId, $mediaId, $type)) {
                throw new InvalidArgumentException('Media must exist, belong to this user, and match the part type.');
            }
            $normalized[] = ['sequence' => $sequence, 'type' => $type, 'text' => null, 'media_id' => $mediaId, 'caption' => ($caption === '' ? null : $caption)];
        }
        return $normalized;
    }

    private function insertParts(int $userId, int $templateId, array $parts): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO template_parts (user_id, template_id, sequence_no, part_type, text_content, media_id, caption, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        foreach ($parts as $part) {
            $stmt->execute([$userId, $templateId, $part['sequence'], $part['type'], $part['text'], $part['media_id'], $part['caption']]);
        }
    }

    private function decodeTemplate(array $template): array
    {
        $template['id'] = (int) $template['id'];
        $template['user_id'] = (int) $template['user_id'];
        return $template;
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertUserId(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('A valid authenticated user id is required.');
        }
    }

    private function assertTemplateId(int $templateId): void
    {
        if ($templateId < 1) {
            throw new InvalidArgumentException('A valid template id is required.');
        }
    }
}
