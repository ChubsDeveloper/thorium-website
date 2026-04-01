<?php
// PayPal configuration addition for app/config.php
return [
    // ... existing config ...
    
    // PayPal integration settings
    'paypal' => [
        'client_id' => $_ENV['PAYPAL_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['PAYPAL_CLIENT_SECRET'] ?? '',
        'sandbox' => filter_var($_ENV['PAYPAL_SANDBOX'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'webhook_id' => $_ENV['PAYPAL_WEBHOOK_ID'] ?? '',
        'currency' => $_ENV['PAYPAL_CURRENCY'] ?? 'USD',
        'api_base' => filter_var($_ENV['PAYPAL_SANDBOX'] ?? true, FILTER_VALIDATE_BOOLEAN) 
            ? 'https://api.sandbox.paypal.com' 
            : 'https://api.paypal.com',
        'web_base' => filter_var($_ENV['PAYPAL_SANDBOX'] ?? true, FILTER_VALIDATE_BOOLEAN) 
            ? 'https://www.sandbox.paypal.com' 
            : 'https://www.paypal.com',
    ],

    // Donation settings
    'donations' => [
        'min_amount' => (float)($_ENV['DONATION_MIN_AMOUNT'] ?? 1.00),
        'max_amount' => (float)($_ENV['DONATION_MAX_AMOUNT'] ?? 500.00),
        'points_per_dollar' => (int)($_ENV['DONATION_POINTS_PER_DOLLAR'] ?? 100),
        'enabled' => true,
    ],
];
