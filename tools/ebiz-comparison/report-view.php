<?php
// Included only after successful authenticated comparison.
if (!isset($report) || !defined('EBIZ_COMPARISON_BROWSER')) { http_response_code(404); exit; }
header_remove('Content-Disposition');
header('Content-Type: text/html; charset=utf-8');
$viewNonce = bin2hex(openssl_random_pseudo_bytes(24));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-".$viewNonce."'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
function report_escape($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$encodedReport = json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($encodedReport === false) { throw new Exception('Report contains invalid text encoding'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>eBiz comparison results</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#18313a;font:15px system-ui,sans-serif}main{max-width:1450px;margin:auto;padding:28px}h1{margin:12px 0}a{color:#075e54}p{line-height:1.5}.cards{display:flex;gap:14px;flex-wrap:wrap;margin:24px 0}.card{background:white;border:1px solid #dbe4e8;border-radius:12px;padding:18px;flex:1;min-width:160px}.card strong{display:block;font-size:30px}.warning{background:#fff1cc;padding:14px;border-radius:8px}.tools{display:flex;gap:12px;flex-wrap:wrap;margin:20px 0}input,select,button{font:inherit;padding:11px;border:1px solid #bacbd0;border-radius:7px;background:white}input{flex:1;min-width:220px}button{cursor:pointer}button.primary{background:#075e54;color:white}button:disabled{opacity:.5;cursor:default}.table-wrap{overflow:auto;background:white;border:1px solid #dbe4e8;border-radius:10px}table{border-collapse:collapse;width:100%;min-width:1050px}th,td{text-align:left;padding:13px;border-bottom:1px solid #e2e8ed;vertical-align:top;white-space:pre-line;overflow-wrap:anywhere}th{background:#e8f0f2;font-size:13px}td{max-width:260px}.footer{display:flex;align-items:center;gap:14px;margin:16px 0;flex-wrap:wrap}.muted{color:#526872;font-size:13px}.empty{text-align:center;padding:35px}details{margin:18px 0}summary{cursor:pointer}li{margin:8px 0}
</style></head><body><main>
<a href="browser.php">← Back to comparison</a><h1>eBiz / Oracle comparison</h1>
<p class="muted">Compared at <?php echo report_escape($report['compared_at']); ?> · Read-only report</p>
<?php foreach ($report['warnings'] as $warning) { ?><p class="warning"><?php echo report_escape($warning); ?></p><?php } ?>
<div class="cards"><div class="card">eBiz non-draft records<strong><?php echo (int)$report['summary']['api_records']; ?></strong></div><div class="card">Oracle records loaded<strong><?php echo (int)$report['summary']['local_records_loaded']; ?></strong></div><div class="card">Matching records<strong><?php echo (int)$report['summary']['matched']; ?></strong></div><div class="card">Issues to review<strong><?php echo count($report['issues']); ?></strong></div></div>
<p class="muted">Draft cases skipped: <?php echo isset($report['summary']['drafts_skipped']) ? (int)$report['summary']['drafts_skipped'] : 0; ?>. Drafts are excluded from all comparison issues.</p>
<p><?php echo report_escape($report['scope_note']); ?></p>
<p class="muted">Reverse missing-in-eBiz checks: <?php echo $report['reverse_comparison_enabled'] ? 'enabled for configured Oracle scope' : 'disabled'; ?>. Oracle records loaded may be only the subset matching API application numbers. Missing application numbers and unmapped statuses require review and do not establish a sync failure.</p>
<details><summary>Issue counts</summary><ul><?php foreach ($report['summary']['issues_by_type'] as $kind=>$count) { ?><li><?php echo report_escape(str_replace('_',' ',$kind)); ?>: <?php echo (int)$count; ?></li><?php } ?></ul></details>
<div class="tools"><input id="search" type="search" aria-label="Search issues" placeholder="Search application, case ID, status or eBiz number"><select id="type" aria-label="Issue type"><option value="">All issue types</option></select><button id="download" class="primary">Download JSON</button></div>
<div class="table-wrap"><table><thead><tr><th>Issue</th><th>Application / OWC no.</th><th>Oracle case ID</th><th>Oracle progress</th><th>eBiz status</th><th>Expected Oracle progress</th><th>Oracle EBIZ_ID</th><th>eBiz private number</th><th>eBiz public number</th></tr></thead><tbody id="results"></tbody></table></div>
<div class="footer"><button id="previous">Previous</button><span id="page" aria-live="polite"></span><button id="next">Next</button><span class="muted">50 issues per page</span></div><noscript>Enable JavaScript to search and view the discrepancy table.</noscript>
</main><script nonce="<?php echo report_escape($viewNonce); ?>">
(function(){
'use strict';
var report=<?php echo $encodedReport; ?>;
var labels={1:'In Process',2:'Pending',3:'Approved',4:'Objection',5:'Complete - Approved',6:'Complete - Disapproved'};
function title(s){return s.replace(/_/g,' ');}
function list(v){if(!v){return [];}return Array.isArray(v)?v:[v];}
function values(rows,key){return rows.map(function(r){return r[key]===null||r[key]===undefined?'—':String(r[key]);}).join('\n')||'—';}
function progress(id){return id===null||id===undefined?'—':String(id)+' · '+(labels[id]||'Unknown');}
var rows=report.issues.map(function(issue){
 var ebiz=list(issue.ebiz),local=list(issue.local);
 var app=issue.application_number||values(ebiz,'application_number');
 if(app==='—'){app=values(local,'OWC_NO');}
 var cells=[title(issue.type),app,values(local,'CASE_ID'),local.map(function(r){return progress(r.CASE_PROGRESS_ID);}).join('\n')||'—',ebiz.map(function(r){return String(r.status_id)+' · '+(r.application_status||'Unknown');}).join('\n')||'—',(issue.expected_case_progress_ids||[]).map(progress).join('\n')||'—',values(local,'EBIZ_ID'),values(ebiz,'ebiz_private_number'),values(ebiz,'ebiz_public_number')];
 return {type:issue.type,cells:cells,search:cells.join(' ').toLowerCase()};
});
var search=document.getElementById('search'),type=document.getElementById('type'),body=document.getElementById('results'),page=0,filtered=rows;
Object.keys(report.summary.issues_by_type).sort().forEach(function(key){var o=document.createElement('option');o.value=key;o.textContent=title(key)+' ('+report.summary.issues_by_type[key]+')';type.appendChild(o);});
function render(){
 body.textContent='';var start=page*50,end=Math.min(start+50,filtered.length);
 if(!filtered.length){var tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=9;td.className='empty';td.textContent='No issues match these filters.';tr.appendChild(td);body.appendChild(tr);}
 filtered.slice(start,end).forEach(function(row){var tr=document.createElement('tr');row.cells.forEach(function(value){var td=document.createElement('td');td.textContent=value;tr.appendChild(td);});body.appendChild(tr);});
 document.getElementById('page').textContent=filtered.length?(start+1)+'–'+end+' of '+filtered.length+' issues':'0 issues';
 document.getElementById('previous').disabled=page===0;document.getElementById('next').disabled=end>=filtered.length;
}
function filter(){var q=search.value.toLowerCase();filtered=rows.filter(function(row){return (!type.value||row.type===type.value)&&row.search.indexOf(q)!==-1;});page=0;render();}
search.addEventListener('input',filter);type.addEventListener('change',filter);
document.getElementById('previous').onclick=function(){if(page>0){page--;render();}};
document.getElementById('next').onclick=function(){if((page+1)*50<filtered.length){page++;render();}};
document.getElementById('download').onclick=function(){var url=URL.createObjectURL(new Blob([JSON.stringify(report,null,2)],{type:'application/json'})),a=document.createElement('a');a.href=url;a.download='ebiz-comparison.json';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},1000);};
render();
})();
</script></body></html>
