<?php
// PHP 5.5-compatible browser entry point. Credentials remain in the session.
ini_set('display_errors', '0');
require_once __DIR__.'/runtime-errors.php';
require_once __DIR__.'/config-loader.php';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$isHttps = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
session_set_cookie_params(0, '', '', $isHttps, true);
session_start();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');
if (empty($_SESSION['compare_csrf'])) {
    $strong = false; $bytes = openssl_random_pseudo_bytes(32, $strong);
    if ($bytes === false || !$strong) { http_response_code(503); exit('Secure random generator unavailable'); }
    $_SESSION['compare_csrf'] = bin2hex($bytes);
}
function browser_equal($a, $b) {
    $a = hash('sha256', (string)$a); $b = hash('sha256', (string)$b); $diff = 0;
    for ($i=0; $i<64; $i++) { $diff |= ord($a[$i]) ^ ord($b[$i]); }
    return $diff === 0;
}
function browser_escape($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$error = '';
if (isset($_SESSION['compare_last_seen']) && time() - $_SESSION['compare_last_seen'] > 1800) {
    unset($_SESSION['compare_browser_key']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !browser_equal($_POST['csrf'], $_SESSION['compare_csrf'])) {
        http_response_code(403); exit('Session expired. Reload this page and try again.');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'logout') {
        unset($_SESSION['compare_browser_key']); session_regenerate_id(true);
    } elseif ($action === 'login') {
        $GLOBALS['comparison_stage'] = 'loading login configuration';
        $path = comparison_config_path();
        $c = is_file($path) && is_readable($path) ? comparison_load_config($path) : array();
        if (!is_array($c) || empty($c['api_key'])) {
            $error = 'Server configuration is missing or the comparison key is not configured.';
        } elseif (!isset($_POST['password']) || !is_string($_POST['password']) || !browser_equal($_POST['password'], $c['api_key'])) {
            // Fixed delay reduces rapid password attempts. Restrict access at the web server for public deployments.
            sleep(1); $error = 'Incorrect comparison key.';
        } else {
            session_regenerate_id(true);
            $_SESSION['compare_browser_key'] = $c['api_key'];
            $_SESSION['compare_last_seen'] = time();
        }
    } elseif ($action === 'run' && !empty($_SESSION['compare_browser_key'])) {
        $_SERVER['HTTP_X_COMPARISON_KEY'] = $_SESSION['compare_browser_key'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SESSION['compare_last_seen'] = time();
        session_write_close();
        define('EBIZ_COMPARISON_BROWSER', true);
        require __DIR__.'/compare.php';
        exit;
    }
}
$loggedIn = !empty($_SESSION['compare_browser_key']);
if ($loggedIn) { $_SESSION['compare_last_seen'] = time(); }
$csrf = $_SESSION['compare_csrf'];
session_write_close();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>eBiz comparison</title>
<style>body{font:16px system-ui,sans-serif;background:#f1f5f9;color:#172b3a;margin:0;padding:24px}main{max-width:480px;margin:8vh auto;background:white;padding:28px;border-radius:16px;box-shadow:0 8px 32px #172b3a14}h1{font-size:25px;margin-top:0}p{line-height:1.6}label{display:block;margin-bottom:8px}input{box-sizing:border-box;width:100%;font-size:18px;padding:12px;border:1px solid #94a3b8;border-radius:8px}button{font:inherit;padding:13px 18px;background:#075e54;color:white;border:0;border-radius:8px;cursor:pointer;margin-top:16px;width:100%}.secondary{background:#e2e8f0;color:#172b3a}.error{color:#a01818}small{display:block;margin-top:20px;line-height:1.5;color:#526376}</style></head><body><main>
<h1>eBiz / Oracle comparison</h1>
<?php if ($error !== '') { ?><p class="error" role="alert"><?php echo browser_escape($error); ?></p><?php } ?>
<?php if (!$loggedIn) { ?>
<p>Enter your comparison key to access the report.</p>
<form method="post"><input type="hidden" name="csrf" value="<?php echo browser_escape($csrf); ?>"><input type="hidden" name="action" value="login"><label for="password">Comparison key</label><input id="password" type="password" name="password" required autocomplete="current-password"><button type="submit">Sign in</button></form>
<?php } else { ?>
<p>Compare eBiz applications against Oracle and view the results. The comparison may take several minutes.</p>
<form method="post"><input type="hidden" name="csrf" value="<?php echo browser_escape($csrf); ?>"><input type="hidden" name="action" value="run"><button type="submit">Run comparison</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?php echo browser_escape($csrf); ?>"><input type="hidden" name="action" value="logout"><button class="secondary" type="submit">Sign out</button></form>
<small>The report lists discrepancies for investigation. Neither system is updated.</small>
<?php } ?>
</main></body></html>
