<?php
// PHP 5.5+ standalone proxy. URL query values are decoded once by PHP.
function sportsSapDefaultParameters($input)
{
    foreach (array('fname', 'lname', 'cnic', 'amount') as $field) {
        if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
            throw new InvalidArgumentException('Missing or invalid parameter: '.$field);
        }
    }
    $defaults = array(
        'address' => '', 'phone' => '', 'desc' => '', 'area' => '', 'type' => '',
        'glSAP' => '0000610338', 'reacon' => '260100',
        'center' => '0011050015', 'pcenter' => '001105001',
        'file' => 'DAM-BALLOT-APPL-2024', 'company_code' => '1000',
        'baseline' => '2026-11-18'
    );
    $params = array();
    foreach (array('fname', 'lname', 'cnic', 'amount') as $field) {
        $params[$field] = trim($input[$field]);
    }
    foreach ($defaults as $field => $default) {
        if (array_key_exists($field, $input) && !is_string($input[$field])) {
            throw new InvalidArgumentException('Invalid parameter: '.$field);
        }
        $params[$field] = array_key_exists($field, $input) ? trim($input[$field]) : $default;
    }
    foreach ($params as $field => $value) {
        if (strlen($value) > 4000 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Invalid parameter: '.$field);
        }
    }
    foreach (array('reacon', 'center', 'pcenter') as $field) {
        if (!preg_match('/^[0-9]+$/D', $params[$field])) {
            throw new InvalidArgumentException($field.' must contain digits.');
        }
    }
    if (!preg_match('/^[0-9]{4}$/D', $params['company_code'])) {
        throw new InvalidArgumentException('company_code must contain exactly four digits.');
    }
    if ($params['file'] === '') {
        throw new InvalidArgumentException('file must not be empty.');
    }
    $amounts = explode(',', $params['amount']);
    $accounts = explode(',', $params['glSAP']);
    if (count($amounts) !== count($accounts)) {
        throw new InvalidArgumentException('amount and glSAP must contain matching fee lines.');
    }
    foreach ($amounts as $i => $amount) {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $amount) || (float) $amount <= 0 || !preg_match('/^[0-9]+$/D', $accounts[$i])) {
            throw new InvalidArgumentException('Invalid amount or glSAP fee line.');
        }
    }
    $date = DateTime::createFromFormat('!Y-m-d', $params['baseline']);
    if (!$date || $date->format('Y-m-d') !== $params['baseline']) {
        throw new InvalidArgumentException('baseline must be a valid date in YYYY-MM-DD format.');
    }
    return $params;
}

// Including this file for tests never executes a SAP request.
if (realpath(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '') !== __FILE__) {
    return;
}


// Compatible with PHP 5.5. Keep warnings out of the response body.
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$url = null;

function failResponse($message, $status)
{
    global $url;
    http_response_code($status);
    echo 'error: ' . $message . "\n";
    if ($url !== null) {
        echo "\nSAP server link:\n" . $url . "\n";
    }
    exit;
}

try {
    $params = sportsSapDefaultParameters($_GET);
} catch (InvalidArgumentException $exception) {
    failResponse($exception->getMessage(), 400);
}
$url = 'http://172.16.70.8:8080/SAPVERIFICATION/Challan?'
    . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

if (!function_exists('curl_init')) {
    failResponse('PHP cURL extension is not installed.', 500);
}
$ch = curl_init();
curl_setopt_array($ch, array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => array('Accept: application/soap+xml, application/xml, text/xml, application/json')
));
$data = curl_exec($ch);
$curlNumber = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
curl_close($ch);

$challanNo = null;
$sapError = null;
$soapFault = null;
$parseError = null;
$xmlErrors = array();
$body = $data === false ? '' : trim($data);

if ($body !== '') {
    $json = json_decode($body, true);
    if (is_array($json)) {
        if (isset($json['error'])) {
            $sapError = is_scalar($json['error']) ? (string) $json['error'] : json_encode($json['error']);
        } elseif (isset($json['challan_no']) && is_scalar($json['challan_no'])) {
            $challanNo = trim((string) $json['challan_no']);
        }
    } elseif (!class_exists('DOMDocument')) {
        $parseError = 'PHP DOM/XML extension is not installed.';
    } else {
        $previousErrors = libxml_use_internal_errors(true);
        // Prevent external entity loading on PHP 5.5.
        $previousEntities = libxml_disable_entity_loader(true);
        libxml_clear_errors();
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $loaded = $document->loadXML($body, LIBXML_NONET);
        foreach (libxml_get_errors() as $error) {
            $xmlErrors[] = trim($error->message) . ' (line ' . $error->line . ')';
        }
        libxml_clear_errors();
        libxml_disable_entity_loader($previousEntities);
        libxml_use_internal_errors($previousErrors);
        if ($loaded) {
            $xpath = new DOMXPath($document);
            $faults = $xpath->query('//*[local-name()="Fault"]');
            if ($faults && $faults->length > 0) {
                $soapFault = trim($faults->item(0)->textContent);
            } else {
                $nodes = $xpath->query('//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ_-", "abcdefghijklmnopqrstuvwxyz")="challanno"]');
                if ($nodes && $nodes->length === 1) {
                    $challanNo = trim($nodes->item(0)->textContent);
                }
            }
        } else {
            $parseError = 'SAP response is neither recognized JSON nor valid XML.';
        }
    }
}
if ($challanNo !== null && ($challanNo === '' || stripos($challanNo, 'error') !== false || !preg_match('/^[A-Za-z0-9\/-]+$/D', $challanNo))) {
    $challanNo = null;
}
$successful = $data !== false && $httpCode >= 200 && $httpCode < 300
    && $sapError === null && $soapFault === null && $challanNo !== null;

if ($debug) {
    http_response_code($successful ? 200 : 502);
    echo "SAP DIAGNOSTIC RESPONSE\n=======================\n";
    echo 'HTTP status: ' . $httpCode . "\n";
    echo 'Content-Type: ' . ($contentType ? $contentType : '(none)') . "\n";
    echo 'Duration: ' . number_format($totalTime, 3) . " seconds\n";
    echo 'cURL error number: ' . $curlNumber . "\n";
    echo 'cURL error: ' . ($curlError !== '' ? $curlError : '(none)') . "\n";
    echo 'Challan number: ' . ($challanNo !== null ? $challanNo : '(not found)') . "\n";
    echo 'SAP error: ' . ($sapError !== null ? $sapError : '(none)') . "\n";
    echo 'SOAP fault: ' . ($soapFault !== null ? $soapFault : '(none)') . "\n";
    echo 'Parser error: ' . ($parseError !== null ? $parseError : '(none)') . "\n";
    if (!empty($xmlErrors)) {
        echo "\nXML ERRORS\n" . implode("\n", $xmlErrors) . "\n";
    }
    if (!$successful) {
        echo "\nSAP server link:\n" . $url . "\n";
    }
    echo "\nRAW SAP RESPONSE\n=======================\n";
    echo $data === false ? '(no response body)' : $data;
    exit;
}
if ($data === false) {
    failResponse('SAP connection failed: ' . $curlError, 502);
}
if ($sapError !== null) {
    failResponse('SAP rejected the request: ' . $sapError, 502);
}
if ($soapFault !== null) {
    failResponse('SAP SOAP fault: ' . $soapFault, 502);
}
if ($httpCode < 200 || $httpCode >= 300) {
    failResponse('SAP returned HTTP ' . $httpCode . '. Use debug=1 for the raw response.', 502);
}
if (!$successful) {
    failResponse('SAP did not return a recognized challan number. Use debug=1 to inspect the raw response.', 502);
}
echo $challanNo;
