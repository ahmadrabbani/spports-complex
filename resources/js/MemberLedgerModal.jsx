import React, { useEffect, useState } from "react";
import axios from "axios";
import { ChevronLeft, ChevronRight, ReceiptText, X } from "lucide-react";

const money = value => `PKR ${Number(value || 0).toLocaleString("en-PK", {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

export default function MemberLedgerModal({ member, onClose }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState("");
    const [page, setPage] = useState(1);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setData(null); setError(""); setPage(1);
        axios.get(`/api/members/${member.id}/ledger`, {signal: controller.signal})
            .then(({data}) => setData(data))
            .catch(e => {if (!axios.isCancel(e)) setError(e.response?.data?.message || "Unable to load this member’s ledger.");});
        return () => controller.abort();
    }, [member.id, attempt]);
    useEffect(() => {
        const close = e => {if (e.key === "Escape") onClose();};
        document.addEventListener("keydown", close);
        return () => document.removeEventListener("keydown", close);
    }, [onClose]);
    const rows = data?.transactions || [];
    const pages = Math.max(1, Math.ceil(rows.length / 25));
    return <div className="modal-backdrop"><section className="modal member-ledger-modal" role="dialog" aria-modal="true" aria-labelledby="member-ledger-title">
        <div className="modal-head"><div><span className="eyebrow">MEMBER ACCOUNT</span><h2 id="member-ledger-title"><ReceiptText/> Member ledger</h2><p>{member.full_name} · {member.customer_no} · {member.sports_complex?.name}</p></div><button type="button" aria-label="Close member ledger" onClick={onClose}><X/></button></div>
        {error ? <div className="alert" role="alert">{error} <button type="button" className="text-button" onClick={()=>setAttempt(a=>a+1)}>Try again</button></div> : !data ? <p role="status">Loading member ledger…</p> : <>
            <p className="member-ledger-source">{data.source === "sap_customer_ledger" ? "Imported SAP customer ledger" : "Application charges and recorded payments"}. Running balances follow transaction order; newest entries appear first.</p>
            <div className="ledger-summary"><article><span>Total charges</span><b>{money(data.summary.total_charged)}</b></article><article><span>Total payments / credits</span><b className="credit">{money(data.summary.total_paid)}</b></article><article><span>Closing balance</span><b className={Number(data.summary.balance)>0 ? "due" : "credit"}>{money(data.summary.balance)}</b></article></div>
            {rows.length ? <><div className="ledger-table"><table><thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Status</th><th>Debit</th><th>Credit</th><th>Running balance</th></tr></thead><tbody>{rows.slice((page-1)*25,page*25).map(row=><tr key={row.key}><td>{row.date || "Not recorded"}{row.date_is_anomalous && <small className="ledger-date-warning">Source date needs review</small>}</td><td>{row.reference || "—"}{row.document_type && <small>SAP {row.document_type}</small>}{row.clearing_document && <small>Clearing: {row.clearing_document}</small>}</td><td>{row.description}<small className={`ledger-type ${row.type}`}>{row.type}</small></td><td>{row.sap_payment_status === 'paid' ? 'Paid · freshly confirmed by SAP' : row.status}{row.sap_payment_status && row.sap_payment_status !== 'paid' && <small>SAP: {row.sap_payment_status.replaceAll('_', ' ')}</small>}{row.sap_payment_document && <small>Payment: {row.sap_payment_document}</small>}{row.sap_payment_checked_at && <small>Checked: {new Date(row.sap_payment_checked_at).toLocaleString()}</small>}{row.sap_verification_number && <small>{row.sap_verification_number}</small>}</td><td>{money(row.debit)}</td><td className="credit">{money(row.credit)}</td><td>{money(row.balance)}</td></tr>)}</tbody></table></div><div className="pagination"><span>{rows.length.toLocaleString()} transactions · Page {page} of {pages}</span><div><button aria-label="Previous ledger page" disabled={page===1} onClick={()=>setPage(page-1)}><ChevronLeft/></button><button aria-label="Next ledger page" disabled={page===pages} onClick={()=>setPage(page+1)}><ChevronRight/></button></div></div></> : <div className="empty-state">No ledger transactions have been recorded for this member.</div>}
        </>}
        <div className="modal-actions"><button type="button" className="secondary" onClick={onClose}>Close</button></div>
    </section></div>;
}
