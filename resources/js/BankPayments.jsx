import React, { useEffect, useState } from "react";
import axios from "axios";
import { Activity, RefreshCw, Search, ShieldCheck, WalletCards } from "lucide-react";

const money = (value) => `PKR ${Number(value || 0).toLocaleString("en-PK", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pretty = (value) => value?.replaceAll("_", " ");

export default function BankPayments({ user }) {
    const [data, setData] = useState(null);
    const [q, setQ] = useState("");
    const [status, setStatus] = useState("");
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState("");
    const [error, setError] = useState("");
    async function load() {
        const response = await axios.get("/api/bank/transactions", { params: { q, status } });
        setData(response.data);
    }
    useEffect(() => { const timer = setTimeout(() => load().catch((e) => setError(e.response?.data?.message || "Unable to load bank transactions.")), 250); return () => clearTimeout(timer); }, [q, status]);
    async function diagnose() {
        setBusy(true); setError(""); setMessage("");
        try { const response = await axios.post("/api/bank/diagnostics/test-connection"); setMessage(response.data.message); await load(); }
        catch (e) { setError(e.response?.data?.message || "The MPGS diagnostic failed."); }
        finally { setBusy(false); }
    }
    async function verify(transaction) {
        setBusy(true); setError(""); setMessage("");
        try { const response = await axios.post(`/api/bank/transactions/${transaction.id}/verify`); setMessage(`${response.data.order_id}: ${pretty(response.data.status)}`); await load(); }
        catch (e) { setError(e.response?.data?.message || "The payment inquiry failed; no status was changed."); }
        finally { setBusy(false); }
    }
    if (!data) return <div className="loading"><Activity/> Loading bank dashboard…</div>;
    const config = data.configuration;
    return <>
        <div className="bank-test-banner">LDA SPORTS COMPLEX - BANK TEST ENVIRONMENT</div>
        <header className="page-header"><div><span className="eyebrow">BOP MPGS SANDBOX</span><h1>Bank payments</h1><p>Server-verified Hosted Checkout sessions, inquiries, receipts, and audit history.</p></div>{user.role === "admin" && !user.sports_complex_id && !user.is_complex_admin && <button className="primary" disabled={busy || !config.configured} onClick={diagnose}><ShieldCheck/>{busy ? "Contacting bank…" : "Test bank connection"}</button>}</header>
        {message && <div className="success-banner"><span>{message}</span></div>}{error && <div className="alert">{error}</div>}
        <section className="bank-config panel"><div><span>Environment</span><b>{config.environment}</b></div><div><span>Configuration</span><b>{config.configured && config.enabled ? "Enabled" : "Not ready"}</b></div><div><span>Merchant</span><b>{config.merchant || "Not configured"}</b></div><div><span>Verification</span><b>{config.verification_method}</b></div><div><span>Webhook</span><b>Unavailable in supplied guide</b></div></section>
        <section className="metrics bank-metrics"><article className="metric green"><WalletCards/><div><span>Total transactions</span><strong>{data.metrics.total}</strong></div></article><article className="metric amber"><RefreshCw/><div><span>Pending / exceptions</span><strong>{data.metrics.pending}</strong></div></article><article className="metric blue"><ShieldCheck/><div><span>Verified collected</span><strong>{money(data.metrics.collected)}</strong><small>{data.metrics.verified} verified</small></div></article></section>
        <section className="panel member-panel"><div className="filters"><div className="search"><Search/><input placeholder="Search order, challan, member, CNIC or bank reference" value={q} onChange={(e)=>setQ(e.target.value)}/></div><select value={status} onChange={(e)=>setStatus(e.target.value)}><option value="">All statuses</option><option value="session_created">Session created</option><option value="pending">Pending</option><option value="verified">Verified</option><option value="verification_required">Verification required</option><option value="failed">Failed</option><option value="reversed">Reversed</option></select></div><div className="table-wrap"><table><thead><tr><th>Order</th><th>Applicant</th><th>Challan</th><th>Amount</th><th>Gateway</th><th>Status</th><th/></tr></thead><tbody>{data.transactions.data.map((item)=><tr key={item.id}><td><b>{item.order_id}</b><small>{item.environment}</small></td><td><b>{item.member?.full_name}</b><small>{item.member?.customer_no}</small></td><td>{item.challan?.challan_no}<small>{item.challan?.sports_complex?.code}</small></td><td><b>{money(item.amount)}</b></td><td>{item.gateway_order_status || item.gateway_result || "—"}<small>{item.bank_transaction_reference || "No bank reference"}</small></td><td><span className={`status ${item.status}`}>{pretty(item.status)}</span>{item.failure_message&&<small>{item.failure_message}</small>}</td><td><button className="text-button" disabled={busy || item.status === "verified"} onClick={()=>verify(item)}>Verify payment</button></td></tr>)}</tbody></table></div></section>
        {data.last_api_log && <p className="bank-last-log">Last bank call: {pretty(data.last_api_log.operation)} · HTTP {data.last_api_log.http_status || "no response"} · {data.last_api_log.duration_ms || 0} ms. Secrets are redacted from audit logs.</p>}
    </>;
}
