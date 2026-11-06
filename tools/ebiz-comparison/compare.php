<?php
// PHP 5.5+ / OCI8 / cURL. This endpoint performs no database or eBiz updates.
ini_set('display_errors', '0');
require_once __DIR__.'/runtime-errors.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__.'/compare-lib.php';
require_once __DIR__.'/config-loader.php';
function respond_error($status, $message) {
    if (defined('EBIZ_COMPARISON_BROWSER') && EBIZ_COMPARISON_BROWSER) {
        // Render handled errors instead of triggering browser/proxy generic error pages.
        http_response_code(200);
        header_remove('Content-Disposition');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comparison failed</title><body><h1>Comparison could not complete</h1><p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p><p>No complete comparison report was produced.</p><p><a href="browser.php">Return to comparison</a></p></body></html>';
        exit;
    }
    http_response_code($status);
    echo json_encode(array('error' => true, 'message' => $message)); exit;
}
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET'); respond_error(405, 'Use GET');
}
$configPath = comparison_config_path();
$GLOBALS['comparison_stage'] = 'loading comparison configuration';
if (!is_file($configPath) || !is_readable($configPath)) {
    respond_error(503, 'Comparison configuration is missing or unreadable. Place config.php beside compare.php or set EBIZ_COMPARE_CONFIG to its absolute path');
}
$c = comparison_load_config($configPath);
if (!is_array($c)) { respond_error(503, 'Comparison configuration must return an array; use config.example.php as the template'); }
if (empty($c['api_key'])) { respond_error(503, 'Comparison API key is not configured'); }
$provided = isset($_SERVER['HTTP_X_COMPARISON_KEY']) ? $_SERVER['HTTP_X_COMPARISON_KEY'] : '';
// Constant-time comparison also works on PHP 5.5 (before hash_equals).
$expectedHash = hash('sha256', $c['api_key']); $providedHash = hash('sha256', $provided); $diff = 0;
for ($i = 0; $i < 64; $i++) { $diff |= ord($expectedHash[$i]) ^ ord($providedHash[$i]); }
if ($diff !== 0) { respond_error(401, 'Unauthorized'); }
foreach (array('ebiz_username', 'ebiz_password', 'oracle_user', 'oracle_password', 'oracle_dsn', 'token_path') as $key) {
    if (empty($c[$key])) { respond_error(503, 'Required server configuration is missing'); }
}
if (!extension_loaded('oci8') || !extension_loaded('curl')) { respond_error(503, 'OCI8 and cURL extensions are required'); }
// Prevent concurrent full scans by this deployment.
$lock = fopen(sys_get_temp_dir().'/ebiz-compare-'.hash('sha256', __DIR__).'.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { respond_error(409, 'A comparison is already running'); }
set_time_limit(0);
$conn = null;
try {
    $started = gmdate('c');
    $verifySsl = !isset($c['verify_ssl']) || $c['verify_ssl'] !== false;
    $GLOBALS['comparison_stage'] = 'eBiz login';
    $login = ebiz_request('auth/login', null, array('username' => $c['ebiz_username'], 'password' => $c['ebiz_password']), $verifySsl);
    $token = $login;
    $GLOBALS['comparison_stage'] = 'reading eBiz login token';
    foreach (explode('.', $c['token_path']) as $part) {
        if (!is_array($token) || !isset($token[$part])) { throw new Exception('Login token path not found; configure token_path'); }
        $token = $token[$part];
    }
    if (!is_string($token) || $token === '' || preg_match('/[\r\n]/', $token)) { throw new Exception('Invalid login token'); }
    $remote = fetch_applications(function ($page) use ($token, $verifySsl) {
        $GLOBALS['comparison_stage'] = 'downloading eBiz page '.$page;
        return ebiz_request('get-applications?page='.$page, $token, null, $verifySsl);
    }, (int)$c['max_pages'], $fetchMetadata, true);
    // Filter after pagination checks so upstream totals remain verifiable.
    $draftNumbers = array();
    foreach ($remote as $row) {
        if (isset($row['status_id']) && (string)$row['status_id'] === '1' && isset($row['application_number'])) {
            $draftNumber = trim((string)$row['application_number']);
            if ($draftNumber !== '') { $draftNumbers['n:'.$draftNumber] = true; }
        }
    }
    $remote = comparison_exclude_drafts($remote, $draftsSkipped);
    $GLOBALS['comparison_stage'] = 'connecting to Oracle';
    $conn = @oci_connect($c['oracle_user'], $c['oracle_password'], $c['oracle_dsn'], 'AL32UTF8');
    if (!$conn) { throw new Exception('Oracle connection failed'); }
    $transaction = @oci_parse($conn, 'SET TRANSACTION READ ONLY');
    if (!$transaction || !@oci_execute($transaction, OCI_NO_AUTO_COMMIT)) { throw new Exception('Cannot establish Oracle read-only transaction'); }
    oci_free_statement($transaction);
    $scoped = !empty($c['local_scope_sql']); $local = array();
    if ($scoped) {
        if (!preg_match('/^\s*SELECT\b/i', $c['local_scope_sql'])) { throw new Exception('local_scope_sql must be SELECT'); }
        $queries = array(array($c['local_scope_sql'], array()));
    } else {
        $numbers = array();
        foreach ($remote as $row) {
            if (!is_array($row) || !array_key_exists('application_number', $row)) { throw new Exception('Invalid API row'); }
            $number = trim((string)$row['application_number']);
            if ($number !== '') { $numbers['n:'.$number] = $number; }
        }
        $queries = array();
        // Oracle 11g supports at most 1000 expressions in an IN list.
        foreach (array_chunk(array_values($numbers), 500) as $chunk) {
            $binds = array(); $placeholders = array();
            foreach ($chunk as $i => $number) { $name = ':n'.$i; $placeholders[] = $name; $binds[$name] = $number; }
            $queries[] = array('SELECT CASE_ID, OWC_NO, CASE_PROGRESS_ID, EBIZ_ID FROM LRS.PLOT_CASE WHERE TRIM(OWC_NO) IN ('.implode(',', $placeholders).')', $binds);
        }
    }
    foreach ($queries as $query) {
        $GLOBALS['comparison_stage'] = 'reading Oracle cases';
        $stmt = @oci_parse($conn, $query[0]);
        if (!$stmt) { throw new Exception('Oracle query preparation failed'); }
        $binds = $query[1];
        foreach ($binds as $name => &$value) {
            if (!@oci_bind_by_name($stmt, $name, $value, -1, SQLT_CHR)) { throw new Exception('Oracle parameter binding failed'); }
        }
        unset($value);
        if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) { throw new Exception('Oracle comparison query failed'); }
        while (($row = oci_fetch_array($stmt, OCI_ASSOC | OCI_RETURN_NULLS)) !== false) {
            foreach (array('CASE_ID', 'OWC_NO', 'CASE_PROGRESS_ID', 'EBIZ_ID') as $field) {
                if (!array_key_exists($field, $row)) { throw new Exception('Oracle query must return CASE_ID, OWC_NO, CASE_PROGRESS_ID, EBIZ_ID'); }
            }
            $local[] = $row;
        }
        if (oci_error($stmt)) { throw new Exception('Oracle row fetch failed'); }
        oci_free_statement($stmt);
    }
    $GLOBALS['comparison_stage'] = 'building comparison report';
    // A scoped Oracle row linked to a draft must not become a reverse missing-record issue.
    $nonDraftNumbers = array();
    foreach ($remote as $row) {
        if (isset($row['application_number'])) { $nonDraftNumbers['n:'.trim((string)$row['application_number'])] = true; }
    }
    $local = array_values(array_filter($local, function ($row) use ($draftNumbers, $nonDraftNumbers) {
        $key = 'n:'.trim((string)$row['OWC_NO']);
        return !isset($draftNumbers[$key]) || isset($nonDraftNumbers[$key]);
    }));
    $report = comparison_report($remote, $local, $c['status_map'], $scoped && !$fetchMetadata['population_changed']);
    $report['download'] = $fetchMetadata;
    $report['summary']['drafts_skipped'] = $draftsSkipped;
    $report['snapshot_consistent'] = false;
    $report['warnings'] = $fetchMetadata['population_changed'] ? array(
        'eBiz totals changed during pagination. Rows may have shifted between pages; results are provisional and duplicate candidates require review.',
        'Reverse missing-in-eBiz checks are disabled for this run. Recheck individual exceptions before acting.'
    ) : array();
    $report['started_at'] = $started;
    $report['scope_note'] = 'eBiz account-visible applications; pagination is checked but the upstream API does not provide a point-in-time snapshot.';
    oci_rollback($conn); oci_close($conn);
    if (defined('EBIZ_COMPARISON_BROWSER') && EBIZ_COMPARISON_BROWSER) {
        require __DIR__.'/report-view.php';
    } else {
        echo json_encode($report, JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    if ($conn) { @oci_rollback($conn); @oci_close($conn); }
    respond_error(502, $e->getMessage());
}
