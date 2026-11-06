import OfferLetterExtraction, {notifyFamilyUpdate} from './OfferLetterExtraction';
import React, {useEffect,useState} from 'react';
import axios from 'axios';
import {appPath} from './paths';
export default function MemberDocuments({memberId,familyMemberId=null,canUpload=false,offerOnlyOnce=false,membershipNumber=null}) {
 const [documents,setDocuments]=useState([]),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const [title,setTitle]=useState(''),[category,setCategory]=useState(familyMemberId?'supporting_document':'offer_letter'),[file,setFile]=useState(null);
 const [membershipConfirmation,setMembershipConfirmation]=useState('');
 const isOffer=offerOnlyOnce||category==='offer_letter';
 const normalized=v=>(v||'').replace(/\s+/g,'').toUpperCase();
 const numberMatches=membershipConfirmation.trim() && (membershipNumber===null || (normalized(membershipNumber) && normalized(membershipNumber)===normalized(membershipConfirmation)));
 const offerExists=documents.some(d=>d.category==='offer_letter');
 const url=`/api/members/${memberId}/documents`;
 useEffect(()=>{setDocuments([]);setMembershipConfirmation('');setFile(null);setError('');axios.get(url,{params:{family_member_id:familyMemberId}}).then(({data})=>setDocuments(data)).catch(()=>setError('Unable to load documents.'));},[url,familyMemberId]);
 useEffect(()=>{if(!documents.some(d=>['queued','processing'].includes(d.extraction_status)))return;const timer=setInterval(()=>{axios.get(url,{params:{family_member_id:familyMemberId}}).then(({data})=>{if(data.some(d=>d.extraction_status==='imported'&&documents.find(old=>old.id===d.id)?.extraction_status!=='imported'))notifyFamilyUpdate(memberId);setDocuments(data);}).catch(()=>{});},2500);return()=>clearInterval(timer);},[documents,url,familyMemberId,memberId]);
 async function upload(e){e.preventDefault();if(isOffer&&!numberMatches){setError('Enter the membership number matching the selected member before uploading.');return;}setBusy(true);setError('');const form=e.currentTarget;
  try {const body=new FormData();body.append('title',title);body.append('category',offerOnlyOnce?'offer_letter':category);body.append('file',file);if(isOffer)body.append('membership_number',membershipConfirmation);if(familyMemberId)body.append('family_member_id',familyMemberId);const {data}=await axios.post(url,body);setDocuments(current=>[data,...current]);if(data.extraction_status==='imported')notifyFamilyUpdate(memberId);setTitle('');setFile(null);setMembershipConfirmation('');form.reset();}
  catch(e){setError(Object.values(e.response?.data?.errors||{}).flat()[0]||e.response?.data?.message||'Unable to upload document.');}finally{setBusy(false);}
 }
 return <section className="member-documents"><h3>{familyMemberId?'Supporting documents':'Offer letters & documents'}</h3>{error&&<p role="alert">{error}</p>}
  {documents.length?<ul>{documents.map(d=><li key={d.id}><a href={appPath(d.download_url)}>{d.title}</a><small>{d.category.replaceAll('_',' ')} · {d.original_name} · {new Date(d.created_at).toLocaleDateString()}</small>{d.category==='offer_letter'&&!familyMemberId&&<OfferLetterExtraction document={d} memberId={memberId} canManage={canUpload} onUpdated={updated=>setDocuments(current=>current.map(item=>item.id===updated.id?updated:item))}/>}</li>)}</ul>:<p>No documents uploaded.</p>}
  {offerOnlyOnce && offerExists && <p>Offer letter uploaded. A second upload is not permitted for this account.</p>}
  {canUpload&&(!offerOnlyOnce || !offerExists)&&<form onSubmit={upload}><label>Document title<input required maxLength={150} value={title} onChange={e=>setTitle(e.target.value)}/></label>{!offerOnlyOnce && <label>Type<select value={category} onChange={e=>setCategory(e.target.value)}><option value="supporting_document">Supporting document</option><option value="offer_letter">Offer letter</option></select></label>}{isOffer&&<label>Confirm membership number<input required value={membershipConfirmation} maxLength={80} autoComplete="off" onChange={e=>setMembershipConfirmation(e.target.value)}/><small>Enter the number from the offer letter. It must match the selected member{membershipNumber ? ` (${membershipNumber})` : ''}.</small></label>}<label>PDF or image · up to 10 MB<input required disabled={isOffer&&!numberMatches} type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={e=>setFile(e.target.files?.[0]||null)}/></label><button className="primary" disabled={busy||!file||(isOffer&&!numberMatches)}>{busy?'Uploading…':'Upload document'}</button></form>}
 </section>;
}
