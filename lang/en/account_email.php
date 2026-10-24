<?php

// Account emails (auth-email-verification; auth-password-reset adds `reset`;
// alerts-email-golive adds `test`, the operator test email of `mail:test`).
// No user-supplied text is ever interpolated into them.

return [
    'layout' => [
        'why' => 'You are receiving this email because an account was created on chartiko.com with this address.',
        'privacy' => 'Privacy policy',
        'ignore' => 'If you did not create a Chartiko account, ignore this email: until it is confirmed, the account cannot use any feature.',
    ],
    'verify' => [
        'subject' => 'Confirm your email on Chartiko',
        'heading' => 'Confirm your email address',
        'intro' => 'To activate your Chartiko account, confirm that this address is yours.',
        'button' => 'Confirm my email',
        'fallback' => 'If the button does not work, copy this link into your browser:',
        'expires' => 'The link expires in 24 hours. If it expires, sign in and ask for a new one.',
    ],
    'reset' => [
        'subject' => 'Reset your Chartiko password',
        'heading' => 'Reset your password',
        'intro' => 'We received a request to change the password for your Chartiko account.',
        'button' => 'Change password',
        'fallback' => 'If the button does not work, copy this link into your browser:',
        'expires' => 'The link expires in 60 minutes and can be used only once.',
        'ignore' => 'If you did not request this, ignore this email. Your password will not change.',
        'why' => 'You are receiving this email because a password recovery was requested for a chartiko.com account with this address.',
    ],
    'test' => [
        'subject' => 'Chartiko test email',
        'heading' => 'Chartiko test email',
        'intro' => 'This email checks how Chartiko sends email. No action is needed.',
        'site' => 'Chartiko website:',
        'ignore' => 'If you did not expect this email, ignore it: no account was created with this address.',
        'why' => 'You are receiving this email because the chartiko.com site administrator sent a test to this address.',
    ],
];
