<?php

$fixedCode = env('OTP_FIXED_CODE', '000000');

return [
    'fixed_code_enabled' => filter_var(
        env('OTP_FIXED_CODE_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),
    'fixed_code' => is_string($fixedCode) && preg_match('/^\d{6}$/', $fixedCode)
        ? $fixedCode
        : '000000',
];
