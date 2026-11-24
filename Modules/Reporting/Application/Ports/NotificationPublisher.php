<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

/**
 * Requests a notice for people named by id. Each (delivery key, person) is
 * sent at most once, however often the caller repeats the request. The module
 * records that a notice was requested and handed over, never that it was read.
 */
interface NotificationPublisher
{
    /**
     * @param  string  $track  submission, revision, review or workflow
     * @param  string  $stage  e.g. before_3, due, overdue, escalated, submitted, returned
     * @param  string  $deliveryKey  identifies the occasion; the recipient id is appended by the publisher
     * @param  list<int>  $userIds
     * @param  array<string, string>  $params  what the text needs (names, period, deadline); never the narrative
     * @return int how many people were newly notified
     */
    public function publish(string $track, string $stage, string $deliveryKey, array $userIds, array $params, ?string $obligationId = null): int;
}
