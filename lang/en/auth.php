<?php

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'sign_in' => 'Sign In',
    'sign_out' => 'Sign Out',
    'email' => 'Email Address',
    'password_label' => 'Password',
    'remember_me' => 'Remember Me',
    'forgot_password' => 'Forgot your password?',
    'reset_password' => 'Reset Password',
    'confirm_password' => 'Confirm Password',
    'send_reset_link' => 'Send Password Reset Link',

    'account_inactive' => 'Your account has been deactivated. Please contact your administrator.',

    // Employee self-registration
    'employee_not_found' => 'No employee record was found with that employee number.',
    'employee_no_email' => 'This employee record has no email address on file. Please contact HR.',
    'employee_no_contact' => 'This employee record must have an email address and phone number on file. Please contact HR.',
    'employee_inactive' => 'Only active employees can create an account. Please contact HR.',
    'employee_already_registered' => 'An account already exists for this employee. Please sign in instead.',
    'registration_otp_sent' => 'A verification code was sent to the email address and phone number held in your employee record.',
    'registration_otp_invalid' => 'The verification code is invalid.',
    'registration_otp_expired' => 'The verification code has expired. Request a new code.',
    'registration_otp_attempts' => 'Too many incorrect attempts. Request a new code.',
    'registration_otp_required' => 'Request a verification code before creating your account.',
    'registration_otp_delivery_failed' => 'The verification code could not be delivered. Please try again later.',
    'registration_otp_subject' => 'Verify your employee account',
    'registration_otp_greeting' => 'Employee account verification',
    'registration_otp_intro' => 'Use this one-time code to finish creating your employee portal account:',
    'registration_otp_expiry' => 'This code expires in :minutes minutes.',
    'registration_otp_ignore' => 'If you did not request an account, ignore this message and contact your administrator.',
    'registration_otp_sms' => 'EUISIS registration code: :code. Expires in :minutes minutes. Do not share this code.',

    // Forced password change on first login
    'must_change_password' => 'You must change your default password before continuing.',
    'password_must_differ' => 'The new password cannot be the same as your current password.',
    'password_cannot_be_default' => 'The new password cannot be the default password.',
    'password_changed' => 'Password changed successfully.',
];
