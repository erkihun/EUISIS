<?php

declare(strict_types=1);

// Communication & Collaboration — server-side messages (docs/communication-module.md).
// Page text lives in resources/js/i18n/{en,am}/communication.ts.
return [
    'errors' => [
        'expiry_in_past' => 'The expiry must be in the future.',
        'expiry_before_publish' => 'The announcement would expire before it is published. Change the expiry or the time.',
        'too_many_attachments' => 'An announcement can have at most 10 files.',
        'members_unavailable' => 'Some of these people cannot be added.',
        'too_many_members' => 'A group can have at most :max members.',
        'unit_outside_organization' => 'The unit must belong to the chosen organization.',
        'not_a_member' => 'This person is not a member.',
        'use_leave' => 'Use "Leave" to leave a conversation yourself.',
        'cannot_remove_owner' => 'The owner cannot be removed. The owner can hand over ownership first.',
        'mandatory_member' => 'Membership of this channel follows the organization and cannot be changed here.',
        'removed_from_channel' => 'An admin removed you from this channel. Ask an admin to add you again.',
        'recipient_unavailable' => 'You cannot start a conversation with this person.',
        'empty_message' => 'Write a message or attach a file.',
        'parent_invalid' => 'You can only reply to a message in this conversation.',
        'broadcast_mention_forbidden' => 'Only group or channel admins can mention everyone.',
        'attachment_invalid' => 'One of the files is not available. Upload it again.',
        'type_not_allowed' => 'This type of file cannot be attached.',
        'type_mismatch' => 'The file content does not match its type.',
        'too_large' => 'The file is larger than :max KB.',
        'reaction_invalid' => 'Choose one of the offered reactions.',
        'message_deleted' => 'This message was deleted.',
        'too_many_pins' => 'This conversation already has the maximum number of pinned messages.',
        'forward_targets' => 'Choose between 1 and :max conversations.',
        'groups_disabled' => 'Creating groups is switched off.',
        'member_unavailable' => 'One of these people cannot be added.',
        'owner_must_stay' => 'Hand ownership to another member before leaving, or archive the group.',
        'last_owner' => 'A group must keep at least one owner.',
        'channel_slug_taken' => 'A channel with this name already exists here.',
        'channel_not_joinable' => 'This channel cannot be joined.',
        'announcement_targets_required' => 'Choose who receives this announcement.',
        'announcement_target_invalid' => 'One of the recipients is not valid or is outside your scope.',
        'announcement_not_editable' => 'Only draft or scheduled announcements can be changed.',
        'publish_at_past' => 'The publish time must be in the future.',
        'expires_before_publish' => 'The expiry must be after the publish time.',
        'already_acknowledged' => 'You have already acknowledged this announcement.',
        'task_assignee_invalid' => 'The task can only be assigned to a member of this conversation.',
        'report_duplicate' => 'You have already reported this.',
        'access_window_invalid' => 'The access window must start now or later and last at most :days days.',
        'access_target_required' => 'Name the conversation or the employee the access covers.',
        'access_not_in_force' => 'This investigation access is not active.',
        'cannot_block_self' => 'You cannot block yourself.',
    ],

    'labels' => [
        'unknown_person' => 'Former member',
        'deleted_message' => 'This message was deleted.',
    ],

    'system' => [
        'member_joined' => ':actor joined.',
        'member_added' => ':actor added :subject.',
        'member_removed' => ':actor removed :subject.',
        'member_left' => ':actor left.',
        'group_created' => ':actor created the group.',
        'channel_created' => ':actor created the channel.',
        'task_created' => ':actor created a task from a message.',
    ],

    'notifications' => [
        'open' => 'Open',
        'generic' => ['title' => 'Communication', 'message' => 'You have new activity in Communication.'],
        'direct_message' => ['title' => 'New message', 'message' => ':name sent you a message.'],
        'mentioned' => ['title' => 'You were mentioned', 'message' => ':name mentioned you in a conversation.'],
        'added_to_group' => ['title' => 'Added to a group', 'message' => ':name added you to :group.'],
        'removed_from_group' => ['title' => 'Removed from a group', 'message' => 'You were removed from :group.'],
        'channel_invitation' => ['title' => 'Added to a channel', 'message' => ':name added you to the channel :channel.'],
        'announcement_important' => ['title' => 'Important announcement', 'message' => ':title'],
        'announcement_urgent' => ['title' => 'Urgent announcement', 'message' => ':title'],
        'announcement_ack_required' => ['title' => 'Please acknowledge an announcement', 'message' => ':title'],
        'task_assigned' => ['title' => 'Task assigned to you', 'message' => ':name assigned you the task ":task".'],
    ],

    'export' => [
        'headers' => ['Employee', 'Employee number', 'Organization', 'Delivered', 'Viewed', 'Acknowledged'],
    ],

    'flash' => [
        'announcement_saved' => 'Announcement saved as a draft.',
        'announcement_saved_for_publisher' => 'Saved. Someone who may publish in this scope will publish it.',
        'announcement_published' => 'Announcement published.',
        'announcement_scheduled' => 'Announcement scheduled.',
        'announcement_cancelled' => 'Announcement withdrawn.',
        'announcement_acknowledged' => 'Thank you. Your acknowledgement is recorded.',
        'settings_saved' => 'Communication settings saved.',
    ],
    'targets' => [
        'city' => 'Everyone in the city administration',
    ],
];
