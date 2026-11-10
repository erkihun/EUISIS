<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** The letter timeline's entries. */
enum LetterEventType: string
{
    case Registered = 'registered';
    case Edited = 'edited';
    case NumberAssigned = 'number_assigned';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case Routed = 'routed';
    case Acknowledged = 'acknowledged';
    case Completed = 'completed';
    case RoutingReturned = 'routing_returned';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case AttachmentAdded = 'attachment_added';
    case ReplyCreated = 'reply_created';
    case Superseded = 'superseded';
    case ReceiptAcknowledged = 'receipt_acknowledged';
    case ReminderSent = 'reminder_sent';
    case Emailed = 'emailed';
    case Escalated = 'escalated';
    case InterimReplyDue = 'interim_reply_due';
}
