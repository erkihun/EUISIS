<?php

return [
    'title' => 'System Settings',
    'default_password_required' => 'Configure a new default password before enabling the default password policy.',
    'messages' => [
        'general_updated' => 'General settings updated successfully.',
        'localization_updated' => 'Localization settings updated successfully.',
        'notifications_updated' => 'Notification settings updated successfully.',
        'email_updated' => 'Email settings updated successfully.',
        'sms_updated' => 'SMS settings updated successfully.',
        'telegram_updated' => 'Telegram settings updated successfully.',
        'security_updated' => 'Security settings updated successfully.',
        'appearance_updated' => 'Appearance settings updated successfully.',
        'id_cards_updated' => 'ID card settings updated successfully.',
        'setting_updated' => 'Setting updated successfully.',
        'cache_cleared' => 'Settings cache cleared successfully.',
        'test_channel_missing' => ':channel test target is not configured.',
        'test_channel_queued' => ':channel test has been queued safely.',
        'test_channel_sent' => ':channel test message was delivered to the server for :target. Check that it arrives.',
        'test_channel_failed' => ':channel test failed: :error',
        'test_email_subject' => ':app test email',
        'test_email_body' => 'This is a test message from :app. If you received it, email delivery works.',
        'test_sms_body' => ':app test message: SMS delivery works.',
        'test_sms_refused' => 'The SMS gateway did not accept the message. Check the SMS settings and the spend limit.',
    ],
];
