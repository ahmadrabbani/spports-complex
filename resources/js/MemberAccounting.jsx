import React, {useEffect,useState} from 'react';
import axios from 'axios';
const errorText=e=>Object.values(e.response?.data?.errors||{}).flat()[0]||e.response?.data?.message||'Unable to save changes.';
export default function MemberAccounting({member,onChanged,canManage}) {
 const [categories,setCategories]=useState([]),[fees,setFees]=useState([]),[category,setCategory]=useState(member.membership_category_id||''),[reason,setReason]=useState(''),[ends,setEnds]=useState(''),[fee,setFee]=useState(''),[error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
 useEffect(()=>{axios.get('/api/reference-data').then(({data})=>{setCategories(data.membership_categories||[]);setFees(data.fee_types.filter(f=>f.is_freeze_fee));}).catch(e=>setError(errorText(e)));},[]);
 const frozen=member.status==='frozen';
 async function act(action) {
  if(!reason.trim())return setError('A reason is required.');
  setBusy(true);setError('');setNotice('');
  try {
   if(action==='category')await axios.put(`/api/members/${member.id}/category`,{membership_category_id:category,reason});
   else if(action==='freeze')await axios.post(`/api/members/${member.id}/freeze`,{starts_on:new Date().toLocaleDateString('en-CA'),ends_on:ends||null,reason,original_membership_category_id:member.reconciliation_account==='260111'?category:undefined});
   else if(action==='unfreeze')await axios.post(`/api/members/${member.id}/unfreeze`,{reason});
   else await axios.post(`/api/members/${member.id}/freeze-fee-approvals`,{fee_type_id:fee,reason});
   setReason('');setNotice(action==='approve'?'Freeze fee approved for one challan.':'Membership updated.');await onChanged();
  }catch(e){setError(errorText(e));}finally{setBusy(false);}
 }
 return <section className="accounting-section"><h3>Membership category &amp; reconciliation</h3>
 <div className="member-profile-facts"><div><span>Current category</span><b>{member.category?.name||'Needs mapping review'}</b></div><div><span>Original REACON</span><b>{member.original_reconciliation_account||(member.reconciliation_account==='260111'?'Needs review':member.reconciliation_account)||'Not mapped'}</b></div><div><span>Effective REACON</span><b>{member.effective_reconciliation_account||'Not mapped'}</b></div><div><span>Membership status</span><b>{member.status}</b></div></div>
 {error&&<div className="alert">{error}</div>}{notice&&<div className="success-message">{notice}</div>}
 {canManage&&<><div className="form-grid"><label>Membership category<select value={category} disabled={frozen||busy} onChange={e=>setCategory(e.target.value)}><option value="">Select a category</option>{categories.map(c=><option key={c.id} value={c.id}>{c.name} - {c.account?.code}</option>)}</select></label><label>Freeze end date (optional)<input type="date" value={ends} disabled={frozen||busy} onChange={e=>setEnds(e.target.value)}/></label><label className="wide">Reason for change<textarea value={reason} maxLength={1000} onChange={e=>setReason(e.target.value)}/></label></div><div className="modal-actions">{!frozen&&<button disabled={busy||!category} className="secondary" onClick={()=>act('category')}>Update category</button>}<button disabled={busy} className="secondary" onClick={()=>act(frozen?'unfreeze':'freeze')}>{frozen?'Unfreeze membership':'Freeze membership now'}</button></div>
 {frozen&&<div className="form-grid"><label>Configured freeze fee<select value={fee} onChange={e=>setFee(e.target.value)}><option value="">Select fee</option>{fees.map(f=><option value={f.id} key={f.id}>{f.name} - PKR {f.freeze_amount}</option>)}</select></label><button className="secondary" disabled={busy||!fee} onClick={()=>act('approve')}>Approve one freeze fee</button></div>}</>}
 {member.reconciliation_account==='260111'&&!frozen&&<p className="alert">Legacy frozen customer: confirm the original category and record the freeze before billing or unfreezing.</p>}<h4>Freeze history</h4>{!member.freezes?.length&&<p>No recorded freeze history.</p>}{member.freezes?.map(f=><div key={f.id} className="panel"><b>{f.starts_on?.slice(0,10)} - {f.unfrozen_at?.slice(0,10)||f.ends_on?.slice(0,10)||'Open'}</b><p>{f.reason} · {f.actor?.name||'System'}</p><small>Original REACON: {f.original_account||'Needs review'}{f.unfreeze_reason&&` · Unfreeze: ${f.unfreeze_reason}`}</small>{f.approvals?.map(a=><p key={a.id}>Approval #{a.id}: {a.fee_type?.name} · PKR {a.amount} · {a.challan_id?'Used':'Unused'}</p>)}</div>)}
 <details><summary>Account change history</summary>{member.account_audits?.map(a=><p key={a.id}>{a.old_account||'Unmapped'} → {a.new_account||'Unmapped'} · {a.reason} · {a.actor?.name||'System'} · {new Date(a.created_at).toLocaleString()}</p>)}</details>
 </section>;
}
