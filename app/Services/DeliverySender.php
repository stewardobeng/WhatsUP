<?php
declare(strict_types=1);

namespace WhatsUP\Services;

/** Minimal outbound transport contract so the queue can be tested without network I/O. */
interface DeliverySender
{
    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function send(array $payload, string $idempotencyKey): array;
}
