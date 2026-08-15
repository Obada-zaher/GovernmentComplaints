<?php

return [
    // English API text remains in its existing canonical form.
    'messages' => [],
    'mail' => [
        'greeting' => 'Hello :name,',
        'complaint_number' => 'Complaint Number: :number',
        'title' => 'Title: :title',
        'status' => 'Status: :status',
        'sign_in_for_details' => 'Please sign in to the Government Complaint Management System for more details.',
        'ignore_code' => 'If you did not request this code, please ignore this email.',
        'subjects' => [
            'complaint_assigned' => 'Complaint Assignment Notification',
            'sla_breached' => 'SLA Breach Alert',
            'complaint_resolved' => 'Complaint Resolution Notification',
            'login_otp' => 'GCMS Login Verification Code',
            'email_otp' => 'GCMS Email Verification Code',
            'account_otp' => 'GCMS Account Verification Code',
            'password_reset' => 'GCMS Password Reset',
        ],
        'purposes' => [
            'login' => 'login verification',
            'email' => 'email verification',
            'account' => 'account verification',
        ],
        'otp' => [
            'request' => 'Government Complaints Management System received a request for :purpose.',
            'code' => 'Your verification code is: :code',
            'expires' => 'This code expires in :minutes minutes.',
        ],
        'password_reset' => [
            'request' => 'Government Complaints Management System received a password reset request for your account.',
            'use_token' => 'Use this reset token in the API reset-password request:',
            'ignore' => 'If you did not request a password reset, please ignore this email.',
        ],
    ],
];
