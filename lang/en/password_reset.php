<?php

return [
    'request_title' => 'Forgot your password?',
    'request_action' => 'Send Reset Link',
    'reset_action' => 'Reset Password',
    'back_to_login' => 'Back to sign in',
    'request_another' => 'Request a new reset link',
    'requested' => 'If this email is linked to an eligible account, a password reset link will be sent.',
    'unavailable' => 'Password recovery is currently unavailable. Contact your system administrator.',
    'invalid_link' => 'This password reset link is invalid or has expired. Request a new link.',
    'throttled' => 'Too many reset attempts. Wait a few minutes and try again.',
    'completed' => 'Your password was reset. Sign in with your new password.',
    'mail' => [
        'reset_subject' => 'Reset your booking account password',
        'requested' => 'A password reset was requested for your booking system account.',
        'expires' => 'The reset link expires in :minutes minutes.',
        'ignore' => 'You can ignore this message if you did not request a reset.',
        'changed_subject' => 'Your booking account password was reset',
        'changed' => 'The password for your booking system account was successfully reset.',
        'changed_at' => 'Time of change: :time',
        'report' => 'Contact your system administrator immediately if you did not make this change.',
    ],
];
