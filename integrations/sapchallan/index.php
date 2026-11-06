<?php
// Deploy on the existing SAP proxy host as sapchallan/index.php (PHP 5.5+ with cURL).
// GET values have already been URL-decoded by PHP. Do not decode them again.
function sportsSapParameters($input)
{
    $fields = array('fname', 'lname', 'address', 'phone', 'cnic', 'amount', 'desc', 'area', 'type', 'glSAP', 'reacon', 'center', 'pcenter', 'file');
    $parameters = array();
    foreach ($fields as $field) {
        if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
            throw new InvalidArgumentException('Missing or invalid field: '.$field);
        }
        $parameters[$field] = trim($input[$field]);
        if (strlen($parameters[$field]) > 4000 || preg_match('/[\x00-\x1F\x7F]/', $parameters[$field])) {
            throw new InvalidArgumentException('Invalid field: '.$field);
        }
    }
    // Keep accounting codes as strings, including their leading zeros.
    foreach (array('reacon', 'center', 'pcenter', 'area', 'type') as $field) {
        if (!preg_match('/^[0-9]+$/D', $parameters[$field])) {
            throw new InvalidArgumentException('Invalid field: '.$field);
        }
    }
    $amounts = explode(',', $parameters['amount']);
    $descriptions = explode(',', $parameters['desc']);
    $accounts = explode(',', $parameters['glSAP']);
    if (count($amounts) !== count($descriptions) || count($amounts) !== count($accounts)) {
        throw new InvalidArgumentException('amount, desc and glSAP must contain matching fee lines.');
    }
    foreach ($amounts as $i => $amount) {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $amount) || (float) $amount <= 0 || trim($descriptions[$i]) === '' || !preg_match('/^[0-9]+$/D', $accounts[$i])) {
            throw new InvalidArgumentException('Invalid fee line.');
        }
    }
    return $parameters;
}

function sportsSapDocument($body)
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || (isset($decoded['success']) && $decoded['success'] === false) || (isset($decoded['status']) && in_array(strtolower((string) $decoded['status']), array('error', 'failed', 'failure'), true))) {
        return null;
    }
    // The upstream contract returns challan_no; never echo an upstream URL or HTML.
    if (isset($decoded['challan_no']) && is_scalar($decoded['challan_no']) && preg_match('/^[0-9]{10}(?:[0-9]{8})?$/D', (string) $decoded['challan_no'])) {
        return (string) $decoded['challan_no'];
    }
    return null;
}

function sportsSapErrorPayload($message, $url)
{
    $payload = array('success' => false, 'error' => $message);
    if (is_string($url) && $url !== '') {
        $payload['sap_url'] = $url;
    }
    return $payload;
}

function sportsSapError($status, $message)
{
    global $url;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(sportsSapErrorPayload($message, isset($url) ? $url : null), JSON_UNESCAPED_SLASHES);
    exit;
}

// Allow the parameter/response contract to be tested without contacting SAP.
if (realpath(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '') !== __FILE__) {
    return;
}
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    sportsSapError(405, 'Use GET.');
}
try {
    $parameters = sportsSapParameters($_GET);
} catch (InvalidArgumentException $exception) {
    sportsSapError(422, $exception->getMessage());
}
$url = 'http://172.16.70.8:8080/SAPVERIFICATION/Challan?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
if (!function_exists('curl_init')) {
    sportsSapError(503, 'SAP proxy cURL extension is unavailable.');
}
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_FOLLOWLOCATION => false,
));
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$failed = $body === false;
curl_close($ch);
// Never retry creation: a timeout may occur after SAP issued the document.
if ($failed || $status < 200 || $status >= 300) {
    sportsSapError(502, 'SAP creation outcome is uncertain. Reconcile with SAP before retrying.');
}
$document = sportsSapDocument($body);
if ($document === null) {
    $decoded = json_decode($body, true);
    $error = is_array($decoded) && isset($decoded['error']) && is_scalar($decoded['error'])
        ? 'SAP rejected the request: '.substr((string) $decoded['error'], 0, 500)
        : 'SAP did not return a valid challan number. Reconcile with SAP before retrying.';
    sportsSapError(502, $error);
}
// Preserve the existing plain-text success contract for current consumers.
header('Content-Type: text/plain; charset=utf-8');
echo $document;
