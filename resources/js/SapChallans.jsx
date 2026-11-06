import { appPath, appPathname } from "./paths";
import ChallanAdjustmentModal from "./ChallanAdjustmentModal";
import React, { useEffect, useState } from "react";
import axios from "axios";
import { ChevronLeft, ChevronRight, Printer, ReceiptText, Search, X } from "lucide-react";

const money = (value) => `PKR ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function GenerateChallanModal({ reference, onClose, onSaved }) {
    const due = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    const [query, setQuery] = useState("");
    const [members, setMembers] = useState([]);
    const [selected, setSelected] = useState(null);
    const [centerOption, setCenterOption] = useState("");
    const centerOptions = reference.complexes.find(complex => complex.id === selected?.sports_complex_id)?.sap_center_options || [];
    useEffect(() => { setCenterOption(""); setPreview(null); }, [selected?.id]);
    const [approvals,setApprovals]=useState([]);
    useEffect(()=>{setApprovals([]);if(selected?.status === "frozen")axios.get(`/api/members/${selected.id}`).then(({data})=>setApprovals((data.freezes||[]).filter(f=>!f.unfrozen_at).flatMap(f=>f.approvals||[]).filter(a=>!a.challan_id)));},[selected?.id]);
    const [charges, setCharges] = useState([{ fee_type_id: "", description: "", amount: "" }]);
    const [dueOn, setDueOn] = useState(due);
    const [notes, setNotes] = useState("");
    const [preview, setPreview] = useState(null);
    const [submissionKey] = useState(() => crypto.randomUUID());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => {
        setMembers([]);
        if(query.trim().length<2||selected)return;
        const controller=new AbortController();
        let current=true;
        const timer=setTimeout(async()=>{
            try {
                const {data}=await axios.get("/api/members",{params:{q:query.trim(),per_page:8},signal:controller.signal});
                if(current)setMembers(data.data);
            }catch(exception){if(current&&!axios.isCancel(exception))setError("Unable to search members. Please try again.");}
        },250);
        return ()=>{current=false;clearTimeout(timer);controller.abort();};
    },[query,selected]);

    function updateCharge(index, field, value) {
        setCharges(charges.map((charge, i) => i === index ? { ...charge, [field]: value } : charge));
        setPreview(null);
    }

    function showError(exception) {
        const errors = exception.response?.data?.errors;
        setError(errors ? Object.values(errors)[0][0] : exception.response?.data?.message || "Unable to generate SAP challan.");
    }

    async function previewChallan() {
        if (!selected) return setError("Select a member before previewing the challan.");
        setBusy(true);
        setError("");
        try {
            const { data } = await axios.post(`/api/members/${selected.id}/challan-preview`, { charges, sap_center_option: centerOption || null });
            setPreview(data);
        } catch (exception) {
            showError(exception);
        } finally {
            setBusy(false);
        }
    }

    async function submit(event) {
        event.preventDefault();
        if (!preview) return previewChallan();
        setBusy(true);
        setError("");
        try {
            const { data } = await axios.post("/api/challans", {
                member_id: selected.id,
                sap_center_option: centerOption || null,
                charges,
                due_on: dueOn,
                notes,
                idempotency_key: submissionKey,
            });
            onSaved(data);
        } catch (exception) {
            showError(exception);
        } finally {
            setBusy(false);
        }
    }

    return <div className="modal-backdrop">
        <form className="modal challan-modal" onSubmit={submit}>
            <div className="modal-head"><div><span className="eyebrow">SAP CHALLAN GENERATION</span><h2>Generate challan</h2></div><button type="button" onClick={onClose}><X /></button></div>
            <p className="modal-copy">Review previous outstanding charges and current GL-based fees before sending the encoded request to SAP.</p>
            {error && <div className="alert">{error}</div>}
            <label className="member-lookup">Member search<div className="search"><Search /><input placeholder="Name, customer number, CNIC or phone" value={query} onChange={(event) => { setQuery(event.target.value); setSelected(null); setPreview(null); }} /></div></label>
            {members.length > 0 && <div className="lookup-results">{members.map((member) => <button type="button" key={member.id} onClick={() => { setSelected(member); setQuery(member.full_name); setMembers([]); setPreview(null); }}><span><b>{member.full_name}</b><small>{member.customer_no} · {member.sports_complex?.name}</small></span><strong>Select</strong></button>)}</div>}
            {selected && <div className="selected-member"><div><span>File / member no.</span><b>{selected.membership_no || selected.customer_no}</b></div><div><span>Applicant</span><b>{selected.full_name}</b></div><div><span>Complex</span><b>{selected.sports_complex?.name}</b></div></div>}

            {selected && centerOptions.length > 0 && <label>Cost and profit center pair<select required value={centerOption} onChange={event => { setCenterOption(event.target.value); setPreview(null); }}><option value="">Select the center for this challan</option>{centerOptions.map(option => <option key={option.id} value={option.id}>{option.label} — Cost {option.cost_center} / Profit {option.profit_center}</option>)}</select></label>}
            <div className="charge-editor">
                <div className="charge-heading"><b>Current charges</b><button type="button" className="text-button" onClick={() => { setCharges([...charges, { fee_type_id: "", description: "", amount: "" }]); setPreview(null); }}>+ Add charge</button></div>
                {charges.map((charge, index) => <div className="charge-row" key={index}>
                    <select required value={charge.fee_type_id} onChange={(event) => updateCharge(index, "fee_type_id", event.target.value)}><option value="">Select fee type</option>{reference.fee_types.filter(t=>selected?.status === "frozen" ? t.is_freeze_fee : !t.is_freeze_fee).map((type) => <option key={type.id} value={type.id}>{type.name}{type.gl_account ? "" : " — GL missing"}</option>)}</select>
                    {selected?.status === 'frozen' && <select required value={charge.freeze_fee_approval_id||''} onChange={e=>{const a=approvals.find(a=>a.id===Number(e.target.value));setCharges(charges.map((v,i)=>i===index?{...v,freeze_fee_approval_id:a?.id||'',fee_type_id:a?.fee_type_id||'',amount:a?.amount||''}:v));setPreview(null);}}><option value="">Select approved freeze fee</option>{approvals.map(a=><option key={a.id} value={a.id}>Approval #{a.id} - PKR {a.amount}</option>)}</select>}
                    <input placeholder="Description (optional)" maxLength="255" value={charge.description} onChange={(event) => updateCharge(index, "description", event.target.value)} />
                    <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={charge.amount} onChange={(event) => updateCharge(index, "amount", event.target.value)} />
                    {charges.length > 1 && <button type="button" className="remove-charge" aria-label="Remove charge" onClick={() => { setCharges(charges.filter((_, i) => i !== index)); setPreview(null); }}>×</button>}
                </div>)}
            </div>

            <div className="form-grid"><label>Due date<input required type="date" min={new Date().toISOString().slice(0, 10)} value={dueOn} onChange={(event) => setDueOn(event.target.value)} /></label><label className="wide">Notes<textarea maxLength="2000" value={notes} onChange={(event) => setNotes(event.target.value)} /></label></div>

            {preview && <div className="challan-preview">
                <div className="preview-person"><b>{preview.applicant.name}</b><span>{preview.applicant.file_no} · {preview.applicant.sports_complex}</span></div>
                <div className="table-wrap"><table><thead><tr><th>Description</th><th>GL Account</th><th>Amount</th></tr></thead><tbody>{preview.items.map((item, index) => <tr key={index}><td>{item.description}{item.is_previous_outstanding && <small>Carried forward</small>}</td><td>{item.gl_account}</td><td>{money(item.amount)}</td></tr>)}</tbody></table></div>
                <div className="preview-totals"><span>Previous outstanding <b>{money(preview.previous_outstanding)}</b></span><span>Current charges <b>{money(preview.current_amount)}</b></span><strong>Total challan <b>{money(preview.total)}</b></strong></div>
                <div className="center-line">Cost Center {preview.cost_center} · Profit Center {preview.profit_center} · Reconciliation {preview.reconciliation_account}</div>
                <label>SAP request URL<textarea readOnly rows={5} value={preview.request_url || ''} onFocus={event => event.target.select()} style={{width:'100%',overflowWrap:'anywhere'}} /></label>
                <button type="button" className="secondary" onClick={async event => { const button = event.currentTarget; try { await navigator.clipboard.writeText(preview.request_url); button.textContent = 'Copied'; } catch { setError('Select the URL and copy it manually.'); } }}>Copy URL</button>
                <p className="modal-copy">Previewing does not send this request. Opening the URL can create a real challan; do not open it again after generation or an uncertain response.</p>
            </div>}

            <div className="modal-actions"><button type="button" className="secondary" onClick={onClose}>Cancel</button><button className="primary" disabled={busy}>{busy ? "Please wait…" : preview ? "Generate SAP Challan" : "Preview challan"}</button></div>
        </form>
    </div>;
}

export default function SapChallans({ reference, user }) {
    const [result, setResult] = useState(null);
    const [q, setQ] = useState("");
    const [status, setStatus] = useState("");
    const [page, setPage] = useState(1);
    const [modal, setModal] = useState(false);
    const [created, setCreated] = useState(null);
    const [history, setHistory] = useState(null);
    const [mockTest, setMockTest] = useState(null);
    const [scenario, setScenario] = useState('success');
    const [paymentBusy, setPaymentBusy] = useState(false);
    const [paymentMessage, setPaymentMessage] = useState('');
    const [adjusting,setAdjusting]=useState(null),[account,setAccount]=useState(""),[category,setCategory]=useState(""),[frozen,setFrozen]=useState(false);

    async function load() {
        const { data } = await axios.get("/api/challans", { params: { q, status, page, reconciliation_account:account,membership_category_id:category,frozen:frozen?1:0 } });
        setResult(data);
    }
    useEffect(() => { const timer = setTimeout(load, 250); return () => clearTimeout(timer); }, [q, status, page, account, category, frozen]);
    async function paymentAction(challan, action) {
        if (action === 'mock') {
            setScenario('success');
            setMockTest({challan, key: crypto.randomUUID()});
            setPaymentMessage('');
            return;
        }
        try {
            if (action === 'history') {
                const {data} = await axios.get(`/api/challans/${challan.id}/payment-history`);
                setHistory(data); return;
            }
            const {data} = await axios.post(`/api/challans/${challan.id}/verify-payment`);
            setPaymentMessage(data.result); load();
        } catch (e) { setPaymentMessage(e.response?.data?.message || 'Payment action failed'); }
    }

    async function runMockPayment() {
        if (paymentBusy) return;
        setPaymentBusy(true);
        try {
            const {data} = await axios.post(`/api/challans/${mockTest.challan.id}/mock-payment`, {scenario, idempotency_key: mockTest.key});
            setPaymentMessage(`MOCK payment: ${data.status}`);
            setMockTest(null);
            await load();
        } catch (e) { setPaymentMessage(e.response?.data?.message || 'Payment action failed. Retry uses the same request key.'); }
        finally { setPaymentBusy(false); }
    }

    return <>
        {paymentMessage && <p role="status">{paymentMessage}</p>}
        {mockTest && <div className="modal-backdrop"><div className="modal">
            <h2>Run mock payment</h2><p>TEST MODE — {mockTest.challan.challan_no} · {money(mockTest.challan.amount)}</p>
            <label>Payment scenario<select value={scenario} disabled={paymentBusy} onChange={event => {setScenario(event.target.value); setMockTest({...mockTest, key: crypto.randomUUID()});}}>
                {['success','failed','pending','cancelled','timeout','duplicate_callback','amount_mismatch','invalid_transaction','delayed'].map(value => <option key={value} value={value}>{value.replaceAll('_',' ')}</option>)}
            </select></label>
            <button disabled={paymentBusy} onClick={() => setMockTest(null)}>Cancel</button>
            <button disabled={paymentBusy} onClick={runMockPayment}>{paymentBusy ? 'Running…' : 'Run simulation'}</button>
        </div></div>}
        <header className="page-header"><div><span className="eyebrow">SAP CHALLAN MANAGEMENT</span><h1>Challans</h1><p>Generate, print, and track audited SAP challans with carried-forward outstanding charges.</p></div><button className="primary" onClick={() => { setCreated(null); setModal(true); }}><ReceiptText />Generate challan</button></header>
        {result?.payment_mode === 'mock' && <div className="success-banner">TEST MODE — simulated payments; no bank settlement</div>}
        {result?.metrics && <div className="panel">{Object.entries(result.metrics).map(([env,m]) => <p key={env}><b>{env.toUpperCase()}</b> · Generated {m.total} · Pending {m.pending} · Paid {m.paid} · Errors {m.generation_errors} · Outstanding {money(m.outstanding)} · Verified {money(m.verified_collections)}</p>)}</div>}
        {created && <div className="success-banner"><span><b>{created.challan_no}</b> was generated ({created.environment}) for {created.member.full_name}.</span><a href={appPath(`/challans/${created.id}/print`)} target="_blank" rel="noreferrer"><Printer />Print challan</a></div>}
        <section className="panel member-panel">
            <div className="filters"><div className="search"><Search /><input placeholder="Challan, member, CNIC or SAP document no." value={q} onChange={(event) => { setQ(event.target.value); setPage(1); }} /></div><select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}><option value="">All statuses</option><option value="generated">Generated / pending</option><option value="issued">Issued / pending</option><option value="unpaid">Unpaid</option><option value="draft">Draft</option><option value="paid">Paid</option><option value="failed">Failed</option><option value="superseded">Superseded</option><option value="cancelled">Cancelled</option></select><select value={account} onChange={e=>{setAccount(e.target.value);setPage(1);}}><option value="">All REACON accounts</option>{[...new Set((reference.membership_categories||[]).map(c=>c.account?.code).filter(Boolean).concat('260111'))].sort().map(a=><option key={a} value={a}>{a}</option>)}</select><select value={category} onChange={e=>{setCategory(e.target.value);setPage(1);}}><option value="">All membership categories</option>{reference.membership_categories?.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select><label><input type="checkbox" checked={frozen} onChange={e=>{setFrozen(e.target.checked);setPage(1);}}/> Frozen at generation</label></div>
            <div className="table-wrap"><table className="challan-table"><thead><tr><th>Challan</th><th>Member / category / REACON</th><th>Date</th><th>Total</th><th>SAP / status</th><th></th></tr></thead><tbody>{result?.data.map((challan) => <tr key={challan.id}>
                <td><b>{challan.challan_no}</b><small>{challan.sports_complex?.code}</small></td><td><b>{challan.member?.full_name}</b><small>{challan.member?.customer_no}</small><small>{challan.membership_category_name||"Legacy category"} · {challan.reconciliation_account||"Unmapped"}</small></td><td>{new Date(challan.generated_at || challan.created_at).toLocaleDateString()}</td><td><b>{money(challan.amount)}</b></td><td><span className={`status ${challan.status}`}>{challan.status}</span><small>{challan.sap_document_no || challan.sap_status.replaceAll("_", " ")}</small><small>SAP: {challan.sap_status}</small><small>{challan.creator?.name || "System"}</small><small>{challan.adjustment_status?.replaceAll("_"," ")}</small></td><td className="actions"><a className="text-button" href={appPath(`/challans/${challan.id}/print`)} target="_blank" rel="noreferrer">View / Print</a><small>{challan.environment === 'mock' ? 'TEST MODE · MOCK' : 'SAP'}</small><button className="text-button" onClick={()=>setAdjusting(challan)}>{user?.role === "admin" ? "Adjust challan / History" : "Adjustment history"}</button><button className="text-button" onClick={() => paymentAction(challan, 'history')}>History / Response</button>{challan.status === 'generated' && <button className="text-button" onClick={() => paymentAction(challan, challan.environment === 'mock' ? 'mock' : 'verify')}>{challan.environment === 'mock' ? 'Run Mock Payment' : 'Verify Payment'}</button>}</td>
            </tr>)}</tbody></table></div>
            {result && <div className="pagination"><span>Showing {result.from || 0}–{result.to || 0} of {result.total.toLocaleString()}</span><div><button disabled={page === 1} onClick={() => setPage(page - 1)}><ChevronLeft /></button><b>Page {result.current_page} of {result.last_page}</b><button disabled={page === result.last_page} onClick={() => setPage(page + 1)}><ChevronRight /></button></div></div>}
        </section>
        {adjusting&&<ChallanAdjustmentModal challan={adjusting} canAdjust={user?.role === "admin"} onClose={()=>setAdjusting(null)} onSaved={load}/>}
        {history && <div className="modal-backdrop"><div className="modal"><button onClick={() => setHistory(null)}>Close</button><h2>Payment history ({history.environment})</h2>{history.transactions.map(tx => <p key={tx.id}>{tx.order_id} · {tx.status} {tx.provider === 'mock' && tx.status === 'verified' && <a href={appPath(`/api/mock-transactions/${tx.id}/receipt`)} target="_blank">MOCK Receipt</a>}{tx.provider === 'mock' && tx.status === 'pending' && <button onClick={async () => {try {await axios.post(`/api/mock-transactions/${tx.id}/confirm`);setPaymentMessage("MOCK payment: verified");setHistory(null);load();}catch(e){window.alert(e.response?.data?.message);}}}>Confirm delayed mock payment</button>}</p>)}<pre style={{whiteSpace:'pre-wrap',maxHeight:'50vh',overflow:'auto'}}>{JSON.stringify(history,null,2)}</pre></div></div>}
        {modal && <GenerateChallanModal reference={reference} onClose={() => setModal(false)} onSaved={(challan) => { setModal(false); setCreated(challan); load(); }} />}
    </>;
}
