<?php

return [
    'title' => 'My Profile',
    'back_to_panel' => 'Back to the panel',
    'personal_information' => 'Personal Information',
    'account_security' => 'Account Security',
    'save_changes' => 'Save Changes',
    'change_email' => 'Change Email',
    'change_password' => 'Change Password',
    'send_verification' => 'Send Verification Email',
    'email_verified' => 'Email Verified',
    'email_unverified' => 'Email Unverified',
    'email_pending' => 'Email Change Pending',
    'email_description' => 'Keep using your current email to sign in until you verify the new address.',
    'email_unavailable' => 'Changing your email requires the booking system administrator to configure account email.',
    'password_description' => 'Use at least 12 characters, including letters and numbers. Your other panel sessions will be signed out on their next request.',
    'fields' => [
        'name' => 'Name',
        'email' => 'Email Address',
        'new_email' => 'New Email Address',
        'current_password' => 'Current Password',
        'new_password' => 'New Password',
        'confirm_password' => 'Confirm New Password',
    ],
    'notifications' => [
        'name_updated' => 'Name Updated',
        'email_sent' => 'Verification Email Sent',
        'password_updated' => 'Password Updated',
    ],
    'errors' => [
        'invalid_current_password' => 'The current password is incorrect.',
        'password_letters' => 'Include at least one letter in your new password.',
        'password_numbers' => 'Include at least one number in your new password.',
        'password_format' => 'Use a password of at most 72 bytes without null characters.',
        'password_reused' => 'Choose a password different from your current password.',
        'password_throttled' => 'Too many password change attempts. Try again in :seconds seconds.',
    ],
];
