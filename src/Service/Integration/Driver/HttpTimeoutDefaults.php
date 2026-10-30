<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

/**
 * A ceiling on how long a driver waits for a service that has stopped
 * answering.
 *
 * Without one the request waits for `default_socket_timeout`, longer than
 * `max_execution_time`, so a self-hosted service that accepts the connection
 * and then says nothing ends the request with a MaxExecutionTimeError. That
 * fatal is raised inside curl and does not unwind through the driver's catch,
 * so the picker never gets its IntegrationException and the FrankenPHP worker
 * thread dies with the request.
 *
 * Only `timeout` — the wait for the next byte. No whole-transfer ceiling: the
 * same helper carries download() and upload() bodies the size of a mail
 * attachment, and how long those legitimately take depends on a link this code
 * knows nothing about. A caller that wants either value chooses it in its own
 * $options, which win.
 */
trait HttpTimeoutDefaults
{
    /** Generous for a service on a LAN, short against one that has gone away. */
    private const float IDLE_TIMEOUT = 10.0;

    /**
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    private function withTimeoutDefaults(array $options): array
    {
        return $options + ['timeout' => self::IDLE_TIMEOUT];
    }
}
