<?php
function comparison_exclude_drafts($rows, &$skipped)
{
    $skipped = 0; $result = array();
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['status_id']) && (string)$row['status_id'] === '1') {
            $skipped++; continue;
        }
        $result[] = $row;
    }
    return $result;
}
function comparison_report($remote, $local, $map, $scoped)
{
    $index = array(); $apiIndex = array(); $issues = array(); $matched = 0;
    foreach ($local as $row) {
        $key = isset($row['OWC_NO']) ? trim((string)$row['OWC_NO']) : '';
        if ($key === '') {
            $issues[] = array('type' => 'local_missing_application_number', 'local' => $row);
            continue;
        }
        $index['n:'.$key][] = $row;
    }
    foreach ($remote as $row) {
        if (!is_array($row) || !array_key_exists('application_number', $row) || !isset($row['status_id'])) {
            throw new Exception('Invalid application response row');
        }
        $key = trim((string)$row['application_number']);
        if ($key === '') {
            $issues[] = array('type' => 'ebiz_missing_application_number', 'ebiz' => $row);
            continue;
        }
        $apiIndex['n:'.$key][] = $row;
    }
    foreach ($apiIndex as $key => $rows) {
        $locals = isset($index[$key]) ? $index[$key] : array();
        if (count($rows) > 1 || count($locals) > 1) {
            $issues[] = array('type' => 'ambiguous_duplicate', 'application_number' => substr($key, 2),
                              'ebiz' => $rows, 'local' => $locals);
            continue;
        }
        $row = $rows[0];
        if (!$locals) {
            $issues[] = array('type' => 'missing_in_oracle', 'ebiz' => $row);
            continue;
        }
        $status = (int)$row['status_id']; $localRow = $locals[0];
        $idMatches = true;
        if (array_key_exists('ebiz_private_number', $row)) {
            if (!array_key_exists('EBIZ_ID', $localRow)) {
                throw new Exception('Oracle query must return EBIZ_ID for private-number comparison');
            }
            $idMatches = trim((string)$row['ebiz_private_number']) === trim((string)$localRow['EBIZ_ID']);
            if (!$idMatches) {
                $issues[] = array('type' => 'ebiz_id_mismatch', 'ebiz' => $row, 'local' => $localRow,
                                  'expected_ebiz_id' => $row['ebiz_private_number']);
            }
        }
        if (!isset($map[$status])) {
            $issues[] = array('type' => 'unmapped_status', 'ebiz' => $row, 'local' => $localRow);
        } elseif (!isset($localRow['CASE_PROGRESS_ID']) ||
                  !in_array((int)$localRow['CASE_PROGRESS_ID'], $map[$status], true)) {
            $issues[] = array('type' => 'status_mismatch', 'ebiz' => $row, 'local' => $localRow,
                              'expected_case_progress_ids' => $map[$status]);
        } elseif ($idMatches) { $matched++; }
    }
    if ($scoped) {
        foreach ($index as $key => $rows) {
            if (!isset($apiIndex[$key])) {
                $issues[] = array('type' => count($rows) > 1 ? 'local_duplicate_absent_from_api' : 'absent_from_api_scope',
                                  'application_number' => substr($key, 2), 'local' => $rows);
            }
        }
    }
    $counts = array();
    foreach ($issues as $issue) {
        $type = $issue['type']; $counts[$type] = isset($counts[$type]) ? $counts[$type] + 1 : 1;
    }
    return array('error' => false, 'compared_at' => gmdate('c'), 'api_fetch_complete' => true,
        'reverse_comparison_enabled' => (bool)$scoped,
        'summary' => array('api_records' => count($remote), 'local_records_loaded' => count($local),
                           'matched' => $matched, 'issues_by_type' => $counts), 'issues' => $issues);
}
function ebiz_request($path, $token, $body, $verifySsl = true)
{
    $ch = curl_init('https://crm-ebiz.punjab.gov.pk/api/v1/'.$path);
    $headers = array('Accept: application/json');
    if ($token !== null) { $headers[] = 'Authorization: Bearer '.$token; }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => $verifySsl, CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_FOLLOWLOCATION => false));
    $text = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlCode = curl_errno($ch); curl_close($ch);
    $stage = $path === 'auth/login' ? 'login' : 'application download';
    if ($text === false) {
        throw new Exception('eBiz '.$stage.' connection failed (cURL '.$curlCode.'). Check server DNS, outbound HTTPS, TLS certificates and connection timeout.');
    }
    if ($code < 200 || $code >= 300) { throw new Exception('eBiz '.$stage.' failed (HTTP '.$code.')'); }
    $data = json_decode($text, true);
    if (!is_array($data) || !empty($data['error'])) { throw new Exception('Invalid or unsuccessful eBiz response'); }
    return $data;
}
function fetch_applications($request, $maxPages, &$metadata = null, $allowChanges = false)
{
    $metadata = array('population_changed' => false);
    $all = array(); $last = null; $total = null; $perPage = null; $seen = array();
    for ($page = 1; $page <= $maxPages; $page++) {
        $data = call_user_func($request, $page);
        if (!isset($data['data'], $data['pagination']) || !is_array($data['data'])) {
            throw new Exception('Missing application data or pagination');
        }
        $p = $data['pagination'];
        foreach (array('current_page', 'last_page', 'total', 'per_page') as $field) {
            if (!isset($p[$field]) || !is_numeric($p[$field])) { throw new Exception('Invalid pagination'); }
        }
        if ((int)$p['current_page'] !== $page || (int)$p['last_page'] < $page || (int)$p['per_page'] < 1) {
            throw new Exception('Unexpected pagination');
        }
        if ($last === null) { $last = (int)$p['last_page']; $total = (int)$p['total']; $perPage = (int)$p['per_page']; }
        if ($perPage !== (int)$p['per_page']) { throw new Exception('API page size changed during download'); }
        if ($last !== (int)$p['last_page'] || $total !== (int)$p['total']) {
            if (!$allowChanges) { throw new Exception('API population changed during download; retry'); }
            $metadata['population_changed'] = true;
            $last = (int)$p['last_page']; $total = (int)$p['total'];
        }
        if ($last > $maxPages) { throw new Exception('Page limit exceeded; no comparison produced'); }
        $fingerprint = hash('sha256', json_encode($data['data']));
        if (isset($seen[$fingerprint]) && count($data['data'])) { throw new Exception('Repeated API page'); }
        $seen[$fingerprint] = true;
        foreach ($data['data'] as $row) { $all[] = $row; }
        if ($page === $last) {
            if (count($all) !== $total && !$metadata['population_changed']) { throw new Exception('Incomplete API download'); }
            $metadata['final_reported_total'] = $total;
            $metadata['downloaded_rows'] = count($all);
            $metadata['pages_fetched'] = $page;
            return $all;
        }
    }
    throw new Exception('Incomplete API download');
}
