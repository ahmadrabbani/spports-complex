<?php
// Copy to config.php beside compare.php, or use a private absolute path via EBIZ_COMPARE_CONFIG.
return array(
    'api_key' => getenv('COMPARE_API_KEY'),
    'ebiz_username' => getenv('EBIZ_USERNAME'),
    'ebiz_password' => getenv('EBIZ_PASSWORD'),
    // Adjust after inspecting the login response; no credentials are returned.
    'token_path' => 'data.token',
    // false bypasses certificate verification; HTTPS encryption remains enabled.
    'verify_ssl' => true,
    'oracle_user' => getenv('ORACLE_USER'),
    'oracle_password' => getenv('ORACLE_PASSWORD'),
    'oracle_dsn' => getenv('ORACLE_DSN'), // host:1521/service_name
    'max_pages' => 500,
    // Only enable after confirming this SELECT covers the same eBiz population.
    // Must return CASE_ID, OWC_NO, CASE_PROGRESS_ID, EBIZ_ID. SELECT only.
    'local_scope_sql' => null,
    'status_map' => array(4 => array(1), 6 => array(1), 5 => array(4),
                          7 => array(3, 5), 11 => array(6))
);
