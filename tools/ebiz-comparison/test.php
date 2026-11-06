<?php
require __DIR__.'/compare-lib.php';
function check($condition, $message) { if (!$condition) { throw new Exception($message); } }
$map = array(4=>array(1),6=>array(1),5=>array(4),7=>array(3,5),11=>array(6));
$remote = array(); $local = array();
foreach (array(array(4,1),array(6,1),array(5,4),array(7,3),array(7,5),array(11,6),array(7,1),array(9,2)) as $i=>$pair) {
    $remote[] = array('application_number'=>'A'.$i,'status_id'=>$pair[0]);
    $local[] = array('OWC_NO'=>'A'.$i,'CASE_ID'=>$i,'CASE_PROGRESS_ID'=>$pair[1]);
}
$remote[] = array('application_number'=>null,'status_id'=>1);
$remote[] = array('application_number'=>'missing','status_id'=>7);
$r = comparison_report($remote,$local,$map,false);
check($r['summary']['matched']===6, 'Status mappings failed');
foreach (array('status_mismatch','unmapped_status','ebiz_missing_application_number','missing_in_oracle') as $type) {
    check($r['summary']['issues_by_type'][$type]===1, $type);
}
$remote[] = $remote[0]; $r = comparison_report($remote,$local,$map,false);
check($r['summary']['issues_by_type']['ambiguous_duplicate']===1,'Duplicate API key');
$local[] = $local[1]; $local[] = array('OWC_NO'=>'only-local','CASE_ID'=>99,'CASE_PROGRESS_ID'=>1);
$r = comparison_report($remote,$local,$map,true);
check($r['summary']['issues_by_type']['ambiguous_duplicate']===2,'Duplicate Oracle key');
check($r['summary']['issues_by_type']['absent_from_api_scope']===1,'Reverse scope');
$r = comparison_report($remote,$local,$map,false);
check(!isset($r['summary']['issues_by_type']['absent_from_api_scope']),'Unscoped reverse comparison');
$pages = function($page) { return array('data'=>array(array('application_number'=>'A'.$page,'status_id'=>4)),
    'pagination'=>array('current_page'=>$page,'last_page'=>2,'total'=>2,'per_page'=>1)); };
check(count(fetch_applications($pages,5))===2,'Pagination');
foreach (array('limit','repeated','incomplete','changed','wrong_page') as $mode) {
    $failed = false;
    try {
        fetch_applications(function($page) use ($pages,$mode) {
            $d=$pages($page);
            if ($mode==='repeated') { $d['data'][0]['application_number']='same'; }
            if ($mode==='incomplete') { $d['pagination']['total']=3; }
            if ($mode==='changed' && $page===2) { $d['pagination']['total']=3; }
            if ($mode==='wrong_page') { $d['pagination']['current_page']=1; }
            return $d;
        }, $mode==='limit' ? 1 : 5);
    } catch (Exception $e) { $failed=true; }
    check($failed,'Must fail on '.$mode);
}
echo "PASS: mappings, missing identifiers, mismatches, unmapped statuses, duplicate keys, reverse scope and pagination failure cases\n";
$meta = null;
$changing = function($page) use ($pages) {
    $d=$pages($page);
    if ($page>=2) { $d['pagination']['last_page']=3; $d['pagination']['total']=3; }
    return $d;
};
check(count(fetch_applications($changing,5,$meta,true))===3,'Follow growing page count');
check($meta['population_changed']===true && $meta['pages_fetched']===3,'Record drift metadata');
$meta=null;
check(count(fetch_applications($pages,5,$meta,true))===2 && !$meta['population_changed'],'Stable population metadata');
echo "PASS: live population changes complete with warning metadata and follow added pages\n";
$idRemote=array(array('application_number'=>'COM1','status_id'=>7,'ebiz_private_number'=>'171739-20082026'));
$idLocal=array(array('CASE_ID'=>1,'OWC_NO'=>'COM1','CASE_PROGRESS_ID'=>3,'EBIZ_ID'=>'171739-20082026'));
$r=comparison_report($idRemote,$idLocal,$map,false);
check($r['summary']['matched']===1 && count($r['issues'])===0,'Matching private number');
foreach (array('old-id',null,'old|171739-20082026') as $badId) {
    $idLocal[0]['EBIZ_ID']=$badId;
    $r=comparison_report($idRemote,$idLocal,$map,false);
    check($r['summary']['matched']===0 && $r['summary']['issues_by_type']['ebiz_id_mismatch']===1,'Mismatching private number');
}
$idLocal[0]['CASE_PROGRESS_ID']=1;
$r=comparison_report($idRemote,$idLocal,$map,false);
check(count($r['issues'])===2,'Report both status and ID mismatch');
echo "PASS: private-number mapping, blank and old IDs, concatenated IDs, combined status/ID discrepancies\n";
$draftRows=array(array('application_number'=>null,'status_id'=>1),array('application_number'=>'DRAFT1','status_id'=>'1'),array('application_number'=>null,'status_id'=>2),array('application_number'=>'ACTIVE1','status_id'=>4));
$remaining=comparison_exclude_drafts($draftRows,$skipped);
check($skipped===2 && count($remaining)===2,'Exclude numeric and string Draft IDs');
check($remaining[0]['status_id']===2 && $remaining[1]['status_id']===4,'Keep other statuses');
$r=comparison_report($remaining,array(),$map,false);
check($r['summary']['api_records']===2 && $r['summary']['issues_by_type']['ebiz_missing_application_number']===1,'Drafts excluded from issues and record totals');
echo "PASS: draft exclusion, skipped counts, retained non-draft statuses and issue totals\n";
