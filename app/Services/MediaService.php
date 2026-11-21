<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use finfo;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Private file storage and ownership metadata for rows in the media table. */
final class MediaService
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav',
        'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'application/pdf' => 'pdf', 'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    private readonly string $storageRoot;
    /** @var list<string> */
    private readonly array $allowedMimeTypes;

    /** $storageRoot is the application's storage directory containing media/{user_id}. */
    public function __construct(
        private readonly PDO $pdo,
        string $storageRoot,
        private readonly int $maxBytes = 20_971_520,
        ?array $allowedMimeTypes = null,
    ) {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Maximum upload size must be positive.');
        }
        $this->storageRoot = rtrim($storageRoot, DIRECTORY_SEPARATOR);
        $this->allowedMimeTypes = $allowedMimeTypes ?? array_keys(self::MIME_EXTENSIONS);
    }

    /**
     * Save an HTTP upload, validate actual content, and register it in `media`.
     *
     * @return array{id:int,user_id:int,storage_name:string,original_name:string,mime_type:string,size_bytes:int,media_type:string,created_at:string}
     */
    public function upload(int $userId, array $upload): array
    {
        $this->assertUserId($userId);
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The upload did not complete successfully.');
        }
        $tmp = $upload['tmp_name'] ?? null;
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('A valid HTTP uploaded file is required.');
        }
        $size = filesize($tmp);
        if (!is_int($size) || $size < 1 || $size > $this->maxBytes) {
            throw new InvalidArgumentException('The file is empty or exceeds the upload size limit.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mime) || !in_array($mime, $this->allowedMimeTypes, true) || !isset(self::MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('This file type is not allowed.');
        }
        $mediaType = $this->mediaTypeForMime($mime);
        $originalName = $this->safeOriginalName($upload['name'] ?? 'upload');
        $directory = $this->userDirectory($userId, true);
        $storageName = bin2hex(random_bytes(24)) . '.' . self::MIME_EXTENSIONS[$mime];
        $destination = $directory . DIRECTORY_SEPARATOR . $storageName;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('Unable to store uploaded media.');
        }
        @chmod($destination, 0600);

        try {
            $stmt = $this->pdo->prepare('INSERT INTO media (user_id, storage_name, original_name, mime_type, size_bytes, media_type, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
            $stmt->execute([$userId, $storageName, $originalName, $mime, $size, $mediaType]);
            $id = (int) $this->pdo->lastInsertId();
            return $this->get($userId, $id) ?? throw new RuntimeException('Uploaded media metadata could not be loaded.');
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        }
    }

    /** Return true only when the given media row belongs to the user and matches the requested category. */
    public function owns(int $userId, int $mediaId, ?string $expectedType = null): bool
    {
        $row = $this->row($userId, $mediaId);
        if ($row === null) {
            return false;
        }
        return $expectedType === null || $row['media_type'] === $expectedType;
    }

    /** Fetch private metadata after checking ownership. */
    public function get(int $userId, int $mediaId): ?array
    {
        $row = $this->row($userId, $mediaId);
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'storage_name' => (string) $row['storage_name'],
            'original_name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'media_type' => (string) $row['media_type'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** Metadata alias for template previews, restricted to an owner's record. */
    public function metadata(int $userId, int $mediaId): array
    {
        return $this->get($userId, $mediaId) ?? throw new InvalidArgumentException('Media was not found.');
    }

    /** Stream an owned media file with safe download headers; returns false for missing/foreign media. */
    public function streamDownload(int $userId, int $mediaId): bool
    {
        $row = $this->row($userId, $mediaId);
        if ($row === null) {
            return false;
        }
        $path = $this->pathForRow($userId, $row);
        if ($path === null || !is_file($path)) {
            return false;
        }
        header('Content-Type: ' . $row['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . addcslashes($row['original_name'], "\\\"") . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        fpassthru($handle);
        fclose($handle);
        return true;
    }

    /**
     * Delete an owned metadata row and its file; foreign ids cannot delete another user's media.
     * Media still referenced by a template part is kept so template validation cannot silently break.
     */
    public function delete(int $userId, int $mediaId): bool
    {
        $row = $this->row($userId, $mediaId);
        if ($row === null) {
            return false;
        }
        $references = $this->pdo->prepare('SELECT COUNT(*) FROM template_parts WHERE media_id = ? AND user_id = ?');
        $references->execute([$mediaId, $userId]);
        if ((int) $references->fetchColumn() > 0) {
            throw new InvalidArgumentException('This media is still used by a template and cannot be deleted.');
        }
        $path = $this->pathForRow($userId, $row);
        if ($path === null) {
            throw new RuntimeException('Private media path is invalid.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM media WHERE id = ? AND user_id = ?');
            $stmt->execute([$mediaId, $userId]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        // The row is now inaccessible; FK constraints set nullable media_id references to NULL.
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Media metadata was removed but its private file could not be deleted.');
        }
        return true;
    }

    /** Safe absolute path for trusted internal processing; null for missing/foreign media. */
    public function pathFor(int $userId, int $mediaId): ?string
    {
        $row = $this->row($userId, $mediaId);
        return $row === null ? null : $this->pathForRow($userId, $row);
    }

    private function row(int $userId, int $mediaId): ?array
    {
        $this->assertUserId($userId);
        if ($mediaId < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, user_id, storage_name, original_name, mime_type, size_bytes, media_type, created_at FROM media WHERE id = ? AND user_id = ?');
        $stmt->execute([$mediaId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function pathForRow(int $userId, array $row): ?string
    {
        if ((int) $row['user_id'] !== $userId || !preg_match('/\A[a-f0-9]{48}\.(?:jpg|png|gif|webp|mp3|m4a|ogg|wav|webm|pdf|txt|doc|docx)\z/D', (string) $row['storage_name'])) {
            return null;
        }
        try {
            $directory = $this->userDirectory($userId, false);
        } catch (RuntimeException) {
            return null;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $row['storage_name'];
        return is_link($path) ? null : $path;
    }

    private function userDirectory(int $userId, bool $create): string
    {
        $this->assertUserId($userId);
        $root = $this->storageRoot;
        if (!is_dir($root)) {
            throw new RuntimeException('Configured storage directory does not exist.');
        }
        $mediaRoot = $root . DIRECTORY_SEPARATOR . 'media';
        $userDirectory = $mediaRoot . DIRECTORY_SEPARATOR . (string) $userId;
        foreach ([$mediaRoot, $userDirectory] as $directory) {
            if (is_link($directory)) {
                throw new RuntimeException('Media storage paths must not be symbolic links.');
            }
            if (!is_dir($directory)) {
                if (!$create || (!mkdir($directory, 0700) && !is_dir($directory))) {
                    throw new RuntimeException('Private media storage is unavailable.');
                }
                @chmod($directory, 0700);
            }
        }
        $realRoot = realpath($root);
        $realUser = realpath($userDirectory);
        if ($realRoot === false || $realUser === false || !str_starts_with($realUser, $realRoot . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private media storage path is invalid.');
        }
        return $realUser;
    }

    private function mediaTypeForMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (in_array($mime, ['application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
            return 'document';
        }
        throw new InvalidArgumentException('The file type is not supported for media storage.');
    }

    private function safeOriginalName(mixed $name): string
    {
        $name = is_string($name) ? basename(str_replace('\\', '/', $name)) : 'upload';
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'upload';
        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 255, 'UTF-8');
        }
        return substr($name, 0, 255);
    }

    private function assertUserId(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('A valid authenticated user id is required.');
        }
    }
}
