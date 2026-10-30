<?php

declare(strict_types=1);

use App\Notifications\PerformanceNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Grievance\Application\Notifications\GrievanceNotification;

// Risk register INF-3: notifications are delivered by the queue workers on the
// `notifications` queue, never inside the web request (SMTP latency, fan-out).
it('queues grievance notifications on the notifications queue', function (): void {
    $notification = new GrievanceNotification('submitted', 'GRV-1', null, ['database']);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBe('notifications');
});

it('queues performance notifications on the notifications queue', function (): void {
    $notification = new PerformanceNotification('assessment_overdue', null, ['database']);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBe('notifications');
});
