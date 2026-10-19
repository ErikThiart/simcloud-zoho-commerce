<?php
// Copy ABOVE the public web root. Load via SIMCLOUD_ZOHO_CONFIG=/absolute/private/config.php.
return [
    'organization_id' => '',
    'bridge_key' => '', // At least 32 random characters. Enter only in a Zoho secure connection.
    'simcloud_token' => '',
    'zoho_access_token' => '', // Short-lived token for controlled development; never commit.
    'zoho_client_id' => '',
    'zoho_client_secret' => '',
    'zoho_refresh_token' => '',
    'zoho_region' => 'com', // com, eu, in, com.au, jp, ca, com.cn, sa
    'database' => '/absolute/private/simcloud-zoho/ledger.sqlite',
    'encryption_key' => '', // Base64 of 32 random bytes; back up separately from the database.
    'live_enabled' => false,
    'verified_payment_modes' => [], // Exact gateway names observed on paid ONLINE orders.
    'recipient_field' => 'cf_simcloud_recipient',
    'meter_field' => 'cf_simcloud_meter',
    'mail_from' => '', // Verified sender on your host. Required for voucher/token fulfilment.
];
