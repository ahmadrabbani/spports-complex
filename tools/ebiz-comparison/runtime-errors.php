<?php
// PHP 5.5 fatal-error diagnostics. Do not display credentials or a stack trace.
$GLOBALS['comparison_stage'] = 'starting browser request';
$GLOBALS['comparison_error_reserve'] = str_repeat('x', 65536);
function comparison_fatal_shutdown()
{
    $error = error_get_last();
    if (!$error || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) { return; }
    unset($GLOBALS['comparison_error_reserve']);
    $stage = isset($GLOBALS['comparison_stage']) ? $GLOBALS['comparison_stage'] : 'unknown stage';
    $location = basename($error['file']).':'.$error['line'];
    // The full PHP error stays in the server's PHP error log.
    error_log('eBiz comparison fatal at '.$stage.' ('.$location.'): '.$error['message']);
    if (!headers_sent()) {
        header_remove('Content-Disposition');
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(200);
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Comparison stopped</title><h1>PHP stopped the comparison</h1><p>Stage: '.htmlspecialchars($stage, ENT_QUOTES, 'UTF-8').'</p><p>Location: '.htmlspecialchars($location, ENT_QUOTES, 'UTF-8').'</p><p>PHP error type: '.(int)$error['type'].'</p><p>No complete report was produced. Share this stage and location, together with the matching PHP error-log entry.</p><p><a href="browser.php">Return to comparison</a></p></html>';
}
register_shutdown_function('comparison_fatal_shutdown');
