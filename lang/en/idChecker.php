<?php

declare(strict_types=1);

/*
 * Notification text for the public Global ID Checker.
 *
 * These messages go TO the employee. They carry no name, organization or
 * contact detail — the recipient already knows who they are, and repeating it
 * would leak that data to anyone with access to the inbox or handset.
 */
return [
    'otpMailSubject' => 'ID card verification code',
    'otpMailGreeting' => 'Hello,',
    'otpMailIntro' => 'Someone is verifying your employee ID card (:card). Share this code with them only if you requested the check:',
    'otpMailExpiry' => 'This code expires in :minutes minutes.',
    'otpMailIgnore' => 'If you did not expect this, ignore this message and report it to your administrator. Your details were not shown.',
    'otpSmsBody' => 'ID card verification code: :code. Expires in :minutes minutes. Do not share unless you requested the check.',
];
