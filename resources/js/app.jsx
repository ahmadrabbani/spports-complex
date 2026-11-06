import { appPath, appPathname } from "./paths";
import StaffAccess, {StaffPasswordChange} from "./StaffAccess";
import MemberAccounting from "./MemberAccounting";
import ReconciliationAccounts from "./ReconciliationAccounts";
import MemberDocuments from "./MemberDocuments";
import FamilyMembers from "./FamilyMembers";
import "./bootstrap";
import "../css/app.css";
import React, { useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import {
    Activity,
    Building2,
    CalendarDays,
    Camera,
    ChevronLeft,
    ChevronRight,
    LayoutDashboard,
    Images,
    Eye,
    CreditCard,
    LogOut,
    Menu,
    Printer,
    ReceiptText,
    Search,
    ShieldCheck,
    UserPlus,
    UploadCloud,
    Users,
    X,
} from "lucide-react";
import axios from "axios";
import SapChallans from "./SapChallans";
import AdminAppointments from "./AdminAppointments";
import PublicPortal from "./PublicPortal";
import ComplexImages from "./ComplexImages";
import Facilities from "./Facilities";
import BankPayments from "./BankPayments";
import MemberLedgerModal from "./MemberLedgerModal";

axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";

const money = (value) =>
    `PKR ${Number(value || 0).toLocaleString("en-PK", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const emptyMember = {
    sports_complex_id: "",
    customer_no: "",
    membership_no: "",
    full_name: "",
    title: "Mr.",
    cnic: "",
    phone: "",
    address: "",
    status: "active",
};

function Login({ onLogin }) {
    const [form, setForm] = useState({
        email: "",
        password: "",
    });
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        setError("");
        try {
            const { data } = await axios.post("/login", form);
            onLogin(data.user);
        } catch (e) {
            setError(e.response?.data?.message || "Unable to sign in.");
        } finally {
            setBusy(false);
        }
    }
    return (
        <main className="login-page">
            <section className="login-brand">
                <div className="mark">
                    <Activity />
                </div>
                <p>LDA Sports Complex</p>
                <h1>One place for every member, facility, and payment.</h1>
                <p className="muted">
                    Sign in with your assigned staff account to manage your sports complex and its customers.
                </p>
                <div className="brand-stat">
                    <strong>Secure access</strong>
                    <span>One assigned complex per administrator</span>
                </div>
            </section>
            <section className="login-panel">
                <form className="login-card" onSubmit={submit}>
                    <span className="eyebrow">STAFF PORTAL</span>
                    <h2>Welcome back</h2>
                    <p>Sign in with your staff account.</p>
                    {error && <div className="alert">{error}</div>}
                    <label>
                        Email
                        <input
                            type="email"
                            value={form.email}
                            onChange={(e) =>
                                setForm({ ...form, email: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Password
                        <input
                            type="password"
                            value={form.password}
                            onChange={(e) =>
                                setForm({ ...form, password: e.target.value })
                            }
                        />
                    </label>
                    <button disabled={busy}>
                        {busy ? "Signing in…" : "Sign in"}
                    </button>
                    <small>Use the login provided by your central administrator.</small>
                </form>
            </section>
        </main>
    );
}

function Sidebar({ view, setView, user, onLogout, open, setOpen }) {
    const limited=Boolean(user.is_complex_admin || (user.role === "admin" && user.sports_complex_id));
    const central=user.role==="admin"&&!user.sports_complex_id&&!user.is_complex_admin;
    return (
        <aside className={open ? "sidebar open" : "sidebar"}>
            <div className="logo">
                <div className="mark">
                    <Activity />
                </div>
                <span>
                    <b>LDA Sports Complex</b>
                    <small>Management Suite</small>
                </span>
                <button className="close-mobile" onClick={() => setOpen(false)}>
                    <X />
                </button>
            </div>
            <nav>
                <span>WORKSPACE</span><button hidden={limited} className={view === "reconciliation" ? "active" : ""} onClick={()=>{setView("reconciliation");setOpen(false);}}><ShieldCheck/> Reconciliation accounts</button>
                <button
                    className={view === "dashboard" ? "active" : ""}
                    onClick={() => {
                        setView("dashboard");
                        setOpen(false);
                    }}
                >
                    <LayoutDashboard />
                    Dashboard
                </button>
                <button
                    className={view === "members" ? "active" : ""}
                    onClick={() => {
                        setView("members");
                        setOpen(false);
                    }}
                >
                    <Users />
                    Members
                </button>
                <button hidden={limited}
                    className={view === "challans" ? "active" : ""}
                    onClick={() => {
                        setView("challans");
                        setOpen(false);
                    }}
                >
                    <ReceiptText />
                    SAP Challans
                </button>
                <button hidden={limited}
                    className={view === "bank-payments" ? "active" : ""}
                    onClick={() => {
                        setView("bank-payments");
                        setOpen(false);
                    }}
                >
                    <ReceiptText />
                    Bank Payments
                </button>
                <button hidden={limited}
                    className={view === "appointments" ? "active" : ""}
                    onClick={() => {
                        setView("appointments");
                        setOpen(false);
                    }}
                >
                    <CalendarDays />
                    Appointments
                </button>
                {central && <button
                    className={view === "complex-images" ? "active" : ""}
                    onClick={() => {
                        setView("complex-images");
                        setOpen(false);
                    }}
                >
                    <Images />
                    Complex images
                </button>}
                {central && <button
                    className={view === "facilities" ? "active" : ""}
                    onClick={() => {
                        setView("facilities");
                        setOpen(false);
                    }}
                >
                    <Building2 />
                    Facilities
                </button>}
                <button className={view==="staff-access"?"active":""} hidden={!central} onClick={()=>{setView("staff-access");setOpen(false);}}><ShieldCheck/>Staff access</button>
            </nav>
            <div className="profile">
                <div>{user.name.slice(0, 2).toUpperCase()}</div>
                <span>
                    <b>{user.name}</b>
                    <small>{user.sports_complex?.code ? `${user.sports_complex.code} · Complex admin` : user.role}</small>
                </span>
                <button onClick={onLogout}>
                    <LogOut />
                </button>
            </div>
        </aside>
    );
}

function Metric({ icon: Icon, label, value, note, tone }) {
    return (
        <article className={"metric " + tone}>
            <div className="metric-icon">
                <Icon />
            </div>
            <div>
                <span>{label}</span>
                <strong>{value}</strong>
                <small>{note}</small>
            </div>
        </article>
    );
}

function Dashboard({ data, onMembers, complexName }) {
    if (!data) return <div className="loading">Loading dashboard…</div>;
    return (
        <>
            <header className="page-header">
                <div>
                    <span className="eyebrow">OPERATIONS OVERVIEW</span>
                    <h1>Good day, Administrator</h1>
                    <p>
                        {complexName ? `Here is the latest picture for ${complexName}.` : "Here is the latest picture across your sports complexes."}
                    </p>
                </div>
                <button className="primary" onClick={onMembers}>
                    <UserPlus />
                    Manage members
                </button>
            </header>
            <section className="metrics">
                <Metric
                    icon={Users}
                    label="Total members"
                    value={data.metrics.members.toLocaleString()}
                    note={`${data.metrics.active.toLocaleString()} currently active`}
                    tone="green"
                />
                <Metric
                    icon={Building2}
                    label="Sports complexes"
                    value={data.metrics.complexes}
                    note={complexName ? "Assigned sports complex" : "Centralized portfolio"}
                    tone="blue"
                />
                <Metric
                    icon={Activity}
                    label="SAP credits this month"
                    value={money(data.metrics.sap_credits_this_month)}
                    note={`${data.metrics.sap_transactions.toLocaleString()} imported transactions`}
                    tone="amber"
                />
                <Metric
                    icon={ReceiptText}
                    label="SAP ledger balance"
                    value={money(data.metrics.sap_balance)}
                    note={`${money(data.metrics.sap_debits_this_month)} debited this month`}
                    tone="blue"
                />
            </section>
            <section className="dashboard-grid">
                <article className="panel complexes">
                    <div className="panel-title">
                        <div>
                            <h3>Complex portfolio</h3>
                            <p>Member distribution by location</p>
                        </div>
                    </div>
                    {data.complexes.map((c, i) => (
                        <div className="complex-row" key={c.id}>
                            <div className={"complex-badge c" + i}>
                                {c.code}
                            </div>
                            <span>
                                <b>{c.name}</b>
                                <small>
                                    {c.members_count.toLocaleString()} members
                                </small>
                            </span>
                            <strong>
                                {data.metrics.members
                                    ? Math.round(
                                          (c.members_count /
                                              data.metrics.members) *
                                              100,
                                      )
                                    : 0}
                                %
                            </strong>
                        </div>
                    ))}
                </article>
                <article className="panel recent">
                    <div className="panel-title">
                        <div>
                            <h3>Recent registrations</h3>
                            <p>Newest member records</p>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Complex</th>
                                <th>Ledger balance</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.recent_members.map((m) => (
                                <tr key={m.id}>
                                    <td>
                                        <b>{m.full_name}</b>
                                        <small>{m.customer_no}</small>
                                    </td>
                                    <td>{m.sports_complex?.name}</td>
                                    <td>
                                        <b className={Number(m.sap_balance ?? m.due_amount) > 0 ? "due-amount" : "zero-amount"}>
                                            {money(m.sap_balance ?? m.due_amount)}
                                        </b>
                                    </td>
                                    <td>
                                        {m.source_created_at
                                            ? new Date(
                                                  m.source_created_at,
                                              ).toLocaleDateString()
                                            : "—"}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </article>
            </section>
            <section className="panel dashboard-transactions">
                <div className="panel-title">
                    <div>
                        <h3>Latest SAP transactions</h3>
                        <p>Source-backed customer ledger activity across the selected portfolio</p>
                    </div>
                </div>
                {data.recent_transactions?.length ? (
                    <div className="table-wrap">
                        <table>
                            <thead><tr><th>Date</th><th>Member</th><th>Complex</th><th>Document</th><th>Description</th><th>Amount</th><th>Clearing</th></tr></thead>
                            <tbody>{data.recent_transactions.map((entry) => (
                                <tr key={entry.id}>
                                    <td>{new Date(entry.document_date + "T00:00:00").toLocaleDateString()}</td>
                                    <td><b>{entry.member?.full_name}</b><small>{entry.member?.customer_no}</small></td>
                                    <td>{entry.sports_complex?.code}</td>
                                    <td><b>{entry.document_no}</b><small>{entry.document_type}</small></td>
                                    <td>{entry.description || "—"}</td>
                                    <td><b className={Number(entry.amount) < 0 ? "transaction-credit" : "transaction-debit"}>{money(Math.abs(Number(entry.amount)))}</b></td>
                                    <td>{entry.clearing_document || "Open"}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                ) : <div className="empty-state">No SAP ledger transactions have been imported yet.</div>}
            </section>
        </>
    );
}

function MemberAvatar({ member, size = "small", editable = false, onEdit }) {
    return <div className={`member-avatar ${size}`}>
        {member.profile_image_url ? <img src={appPath(member.profile_image_url)} alt={`${member.full_name} photograph`}/> : <span>{member.full_name?.trim()?.charAt(0)?.toUpperCase() || "M"}</span>}
        {editable && <button type="button" aria-label={`Upload picture for ${member.full_name}`} onClick={onEdit}><Camera/></button>}
    </div>;
}

function MemberPhotoModal({ member, onClose, onSaved }) {
    const inputRef = useRef(null);
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(member.profile_image_url || "");
    const [zoom, setZoom] = useState(1);
    const [horizontal, setHorizontal] = useState(0);
    const [vertical, setVertical] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => () => { if (preview?.startsWith("blob:")) URL.revokeObjectURL(preview); }, [preview]);
    function choose(selected) {
        setError("");
        if (!selected) return;
        if (!['image/jpeg','image/png','image/webp'].includes(selected.type) || selected.size > 8 * 1024 * 1024) {
            return setError("Choose a JPG, PNG, or WebP image no larger than 8 MB.");
        }
        if (preview?.startsWith("blob:")) URL.revokeObjectURL(preview);
        setFile(selected); setPreview(URL.createObjectURL(selected)); setZoom(1); setHorizontal(0); setVertical(0);
    }
    async function croppedPhoto() {
        const image = new Image(); image.src = preview; await image.decode();
        if (image.naturalWidth < 240 || image.naturalHeight < 240 || image.naturalWidth > 6000 || image.naturalHeight > 6000) throw new Error("Image dimensions must be between 240 and 6000 pixels.");
        const side = Math.min(image.naturalWidth, image.naturalHeight) / zoom;
        const maxX = image.naturalWidth - side; const maxY = image.naturalHeight - side;
        const sx = (maxX / 2) * (1 + horizontal / 100); const sy = (maxY / 2) * (1 + vertical / 100);
        const canvas = document.createElement("canvas"); canvas.width = 720; canvas.height = 720;
        canvas.getContext("2d").drawImage(image, sx, sy, side, side, 0, 0, 720, 720);
        return await new Promise((resolve, reject) => canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error("Unable to crop this image.")), "image/webp", .86));
    }
    async function save() {
        if (!file) return setError("Choose a photograph first.");
        setBusy(true); setError("");
        try {
            const blob = await croppedPhoto(); const body = new FormData(); body.append("photo", blob, `member-${member.id}.webp`);
            const { data } = await axios.post(`/api/members/${member.id}/photo`, body);
            onSaved(data.member, data.message);
        } catch (exception) {
            setError(exception.response?.data?.errors?.photo?.[0] || exception.response?.data?.message || exception.message || "Unable to update the photograph.");
        } finally { setBusy(false); }
    }
    return <div className="modal-backdrop"><section className="modal member-photo-modal"><div className="modal-head"><div><span className="eyebrow">MEMBER PHOTOGRAPH</span><h2>{member.profile_image_url ? "Change picture" : "Upload picture"}</h2></div><button type="button" onClick={onClose}><X/></button></div>{error&&<div className="alert">{error}</div>}<div className="photo-modal-grid"><div className="photo-crop-preview">{preview ? <img src={appPath(preview)} alt="Crop preview" style={{transform:`scale(${zoom}) translate(${horizontal/3}%,${vertical/3}%)`}}/> : <span>{member.full_name.charAt(0)}</span>}<div className="crop-frame"/></div><div className="photo-controls"><div className="photo-drop-zone" onDragOver={(e)=>e.preventDefault()} onDrop={(e)=>{e.preventDefault();choose(e.dataTransfer.files?.[0]);}} onClick={()=>inputRef.current?.click()}><UploadCloud/><b>Drop photograph here</b><span>or click to choose JPG, PNG or WebP</span><small>240–6000 px · maximum 8 MB</small><input ref={inputRef} hidden type="file" accept="image/jpeg,image/png,image/webp" onChange={(e)=>choose(e.target.files?.[0])}/></div><label>Zoom <input type="range" min="1" max="2.5" step=".01" value={zoom} onChange={(e)=>setZoom(Number(e.target.value))}/></label><label>Horizontal position <input type="range" min="-100" max="100" value={horizontal} onChange={(e)=>setHorizontal(Number(e.target.value))}/></label><label>Vertical position <input type="range" min="-100" max="100" value={vertical} onChange={(e)=>setVertical(Number(e.target.value))}/></label><p>The saved photograph is square-cropped, resized to 720 × 720, and compressed as WebP.</p></div></div><div className="modal-actions"><button type="button" className="secondary" onClick={onClose}>Cancel</button><button type="button" className="primary" disabled={busy||!file} onClick={save}>{busy ? "Optimizing…" : "Crop & save picture"}</button></div></section></div>;
}

function MemberProfileModal({ member, onClose, onEdit, onPhoto, onLedger, canManage, onChanged, restricted = false }) {
    const [details, setDetails] = useState(null);
    useEffect(() => { axios.get(`/api/members/${member.id}`).then(({data})=>setDetails(data)); }, [member.id]);
    const current = details || member;
    return <div className="modal-backdrop"><section className="modal member-profile-modal"><div className="modal-head"><div><span className="eyebrow">MEMBER PROFILE</span><h2>{current.full_name}</h2></div><button type="button" onClick={onClose}><X/></button></div><div className="member-profile-head"><MemberAvatar member={current} size="large" editable={Boolean(onPhoto)} onEdit={onPhoto}/><div><span className={`status ${current.status}`}>{current.status}</span><h3>{current.membership_no || current.customer_no}</h3><p>{current.sports_complex?.name}</p>{onPhoto&&<button className="text-button" onClick={onPhoto}><Camera/> Upload / change picture</button>}</div></div><div className="member-profile-facts"><div><span>Membership number</span><b>{current.membership_no || "Not assigned"}</b></div><div><span>Customer number</span><b>{current.customer_no}</b></div><div><span>CNIC</span><b>{current.masked_cnic || "Not recorded"}</b></div><div><span>Phone</span><b>{current.phone || "Not recorded"}</b></div><div><span>Sports complex</span><b>{current.sports_complex?.name}</b></div><div><span>Photo last updated</span><b>{current.photo_updated_at ? new Date(current.photo_updated_at).toLocaleString() : "Not uploaded"}</b></div></div><MemberAccounting member={current} canManage={canManage} onChanged={async()=>{const {data}=await axios.get(`/api/members/${member.id}`);setDetails(data);onChanged?.();}}/><MemberDocuments memberId={current.id} membershipNumber={current.membership_no||""} canUpload={Boolean(onPhoto)} offerOnlyOnce={restricted}/><FamilyMembers memberId={current.id} eligibilityKey={`${current.membership_category_id}:${current.reconciliation_account}:${current.status}`} canUploadPhoto={Boolean(onPhoto)} canUploadDocuments={Boolean(onPhoto) && !restricted}/>{current.photo_updater&&<p className="photo-audit-note">Latest photograph approved by {current.photo_updater.name}.</p>}<div className="modal-actions"><button className="secondary" onClick={onLedger}><ReceiptText/> View ledger</button><a className="secondary button-link" href={appPath(`/members/${current.id}/membership-card`)} target="_blank" rel="noreferrer"><CreditCard/> Membership card</a>{!restricted && <button className="primary" onClick={onEdit}>Edit member</button>}</div></section></div>;
}

function MemberModal({ member, complexes, categories = [], canManage, onClose, onSaved }) {
    const [form, setForm] = useState(member || {...emptyMember,sports_complex_id:complexes.length===1?complexes[0].id:""});
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    async function save(e) {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            if (member) await axios.put(`/api/members/${member.id}`, form);
            else await axios.post("/api/members", form);
            onSaved();
        } catch (e) {
            setErrors(
                e.response?.data?.errors || {
                    form: [
                        e.response?.data?.message || "Unable to save member.",
                    ],
                },
            );
        } finally {
            setBusy(false);
        }
    }
    return (
        <div className="modal-backdrop">
            <form className="modal" onSubmit={save}>
                <div className="modal-head">
                    <div>
                        <span className="eyebrow">MEMBER RECORD</span>
                        <h2>{member ? "Edit member" : "Add member"}</h2>
                    </div>
                    <button type="button" onClick={onClose}>
                        <X />
                    </button>
                </div>
                {errors.form && <div className="alert">{errors.form[0]}</div>}
                <div className="form-grid">
                    {!member && canManage && <label className="wide">Membership category<select value={form.membership_category_id||""} onChange={e=>setForm({...form,membership_category_id:e.target.value||null})}><option value="">Select category (unmapped members cannot be billed)</option>{categories.map(c=><option key={c.id} value={c.id}>{c.name} - {c.account?.code}</option>)}</select></label>}
                    <label className="wide">
                        Full name
                        <input
                            value={form.full_name}
                            onChange={(e) =>
                                setForm({ ...form, full_name: e.target.value })
                            }
                        />
                        {errors.full_name && (
                            <small>{errors.full_name[0]}</small>
                        )}
                    </label>
                    <label>
                        Customer number
                        <input
                            value={form.customer_no}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    customer_no: e.target.value,
                                })
                            }
                        />
                    </label>
                    <label>
                        Membership number
                        <input
                            value={form.membership_no || ""}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    membership_no: e.target.value,
                                })
                            }
                        />
                    </label>
                    <label className="wide">
                        Sports complex
                        <select
                            value={form.sports_complex_id}
                            disabled={complexes.length===1}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    sports_complex_id: e.target.value,
                                })
                            }
                        >
                            <option value="">Select complex</option>
                            {complexes.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        CNIC
                        <input
                            value={form.cnic || ""}
                            onChange={(e) =>
                                setForm({ ...form, cnic: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Phone
                        <input
                            value={form.phone || ""}
                            onChange={(e) =>
                                setForm({ ...form, phone: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Status
                        <select
                            value={form.status}
                            onChange={(e) =>
                                setForm({ ...form, status: e.target.value })
                            }
                        >
                            <option value="active">Active</option>{form.status === "frozen" && <option value="frozen">Frozen</option>}
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </label>
                    <label className="wide">
                        Address
                        <textarea
                            value={form.address || ""}
                            onChange={(e) =>
                                setForm({ ...form, address: e.target.value })
                            }
                        />
                    </label>
                </div>
                <div className="modal-actions">
                    <button
                        type="button"
                        className="secondary"
                        onClick={onClose}
                    >
                        Cancel
                    </button>
                    <button className="primary" disabled={busy}>
                        {busy ? "Saving…" : "Save member"}
                    </button>
                </div>
            </form>
        </div>
    );
}

function Members({ reference, user }) {
    const [result, setResult] = useState(null);
    const [q, setQ] = useState("");
    const [complexId, setComplexId] = useState("");
    const [page, setPage] = useState(1);
    const [modal, setModal] = useState(undefined);
    const [profile, setProfile] = useState(null);
    const [photoMember, setPhotoMember] = useState(null);
    const [ledgerMember, setLedgerMember] = useState(null);
    const [notice, setNotice] = useState("");
    const [searching,setSearching]=useState(false);
    const [searchError,setSearchError]=useState("");
    const searchRequest=useRef(0);
    async function load(signal) {
        const requestId=++searchRequest.current;
        setSearching(true);setSearchError("");
        try {
            const {data}=await axios.get("/api/members",{params:{q:q.trim(),complex_id:complexId,page},signal});
            if(requestId===searchRequest.current)setResult(data);
        } catch(error) {
            if(!axios.isCancel(error)&&requestId===searchRequest.current)setSearchError(error.response?.data?.message||"Unable to search members. Please try again.");
        } finally {
            if(requestId===searchRequest.current)setSearching(false);
        }
    }
    useEffect(() => {
        const controller=new AbortController();
        setSearching(true);
        const timer=setTimeout(()=>load(controller.signal),250);
        return ()=>{clearTimeout(timer);controller.abort();searchRequest.current++;};
    },[q,complexId,page]);
    return (
        <>
            <header className="page-header">
                <div>
                    <span className="eyebrow">MEMBER DIRECTORY</span>
                    <h1>Members</h1>
                    <p>
                        {user.sports_complex_id ? `View customers at ${user.sports_complex?.name||"your assigned complex"}. Add family members, upload member pictures and one offer letter; member details are read-only.` : "Search, review, and maintain member profiles across every complex."}
                    </p>
                </div>
            </header>
            <section className="panel member-panel">
                {notice && <div className="success-message">{notice}</div>}{searchError&&<div className="alert" role="alert">{searchError}</div>}
                <div className="filters">
                    <div className="search">
                        <Search />
                        <input
                            aria-label="Search members" placeholder="Name, CNIC (with or without dashes), phone or customer no."
                            value={q}
                            onChange={(e) => {
                                setQ(e.target.value);
                                setPage(1);
                            }}
                        />
                    </div>
                    <select
                        value={complexId}
                        onChange={(e) => {
                            setComplexId(e.target.value);
                            setPage(1);
                        }}
                    >
                        <option value="">{user.sports_complex_id ? "Assigned complex" : "All complexes"}</option>
                        {reference.complexes.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Member name</th>
                                <th>Membership no.</th>
                                <th>CNIC</th>
                                <th>Complex</th>
                                <th>Due amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {searching&&<tr><td colSpan={8} role="status">Searching members…</td></tr>}
                            {!searching&&!searchError&&result?.data.length===0&&<tr><td colSpan={8}>No members match this search in the selected complex.</td></tr>}
                            {!searching&&!searchError&&result?.data.map((m) => (
                                <tr key={m.id}>
                                    <td><MemberAvatar member={m} editable={user.role === "admin"} onEdit={()=>setPhotoMember(m)}/></td>
                                    <td><b>{m.full_name}</b><small>{m.customer_no}</small></td>
                                    <td>{m.membership_no || m.customer_no}</td>
                                    <td>{m.masked_cnic || "Not recorded"}</td>
                                    <td>{m.sports_complex?.name}</td>
                                    <td><b className={Number(m.sap_balance ?? m.due_amount) > 0 ? "due-amount" : "zero-amount"}>{money(m.sap_balance ?? m.due_amount)}</b></td>
                                    <td>
                                        <span className={"status " + m.status}>
                                            {m.status}
                                        </span>
                                    </td>
                                    <td>
                                        <div className="member-row-actions"><button className="text-button" onClick={() => setProfile(m)}><Eye/> View profile</button><button className="text-button" onClick={() => setLedgerMember(m)}><ReceiptText/> View ledger</button>{!(user.is_complex_admin || (user.role === "admin" && user.sports_complex_id)) && <button className="text-button" onClick={() => setModal(m)}>Edit member</button>}{user.role === "admin" && <button className="text-button" onClick={() => setPhotoMember(m)}><Camera/> {m.profile_image_url ? "Change picture" : "Upload picture"}</button>}</div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {result && (
                    <div className="pagination">
                        <span>
                            Showing {result.from || 0}–{result.to || 0} of{" "}
                            {result.total.toLocaleString()}
                        </span>
                        <div>
                            <button
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                <ChevronLeft />
                            </button>
                            <b>
                                Page {result.current_page} of {result.last_page}
                            </b>
                            <button
                                disabled={page === result.last_page}
                                onClick={() => setPage(page + 1)}
                            >
                                <ChevronRight />
                            </button>
                        </div>
                    </div>
                )}
            </section>
            {modal !== undefined && (
                <MemberModal
                    member={modal}
                    complexes={reference.complexes}
                    categories={reference.membership_categories||[]} canManage={user.role==="admin"}
                    onClose={() => setModal(undefined)}
                    onSaved={() => {
                        setModal(undefined);
                        load();
                    }}
                />
            )}
            {profile && <MemberProfileModal member={profile} restricted={Boolean(user.is_complex_admin || (user.role === "admin" && user.sports_complex_id))} canManage={user.role==="admin" && !user.is_complex_admin && !user.sports_complex_id} onChanged={load} onLedger={()=>{setLedgerMember(profile);setProfile(null);}} onClose={()=>setProfile(null)} onEdit={()=>{setModal(profile);setProfile(null);}} onPhoto={user.role === "admin" ? ()=>{setPhotoMember(profile);setProfile(null);} : undefined}/>} 
            {ledgerMember && <MemberLedgerModal member={ledgerMember} onClose={()=>setLedgerMember(null)}/>}
            {photoMember && <MemberPhotoModal member={photoMember} onClose={()=>setPhotoMember(null)} onSaved={(updated,message)=>{setResult((current)=>({...current,data:current.data.map((item)=>item.id===updated.id?{...item,...updated}:item)}));setProfile((current)=>current?.id===updated.id?updated:current);setPhotoMember(null);setNotice(message);setTimeout(()=>setNotice(""),4000);}}/>}
        </>
    );
}

function ChallanModal({ onClose, onSaved }) {
    const today = new Date().toISOString().slice(0, 10);
    const due = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    const [query, setQuery] = useState("");
    const [members, setMembers] = useState([]);
    const [selected, setSelected] = useState(null);
    const [form, setForm] = useState({
        purpose: "Membership fee",
        amount: "",
        issued_on: today,
        due_on: due,
        notes: "",
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => {
        if (query.trim().length < 2 || selected) return setMembers([]);
        const timer = setTimeout(async () => {
            const { data } = await axios.get("/api/members", {
                params: { q: query, per_page: 8 },
            });
            setMembers(data.data);
        }, 250);
        return () => clearTimeout(timer);
    }, [query, selected]);

    async function submit(e) {
        e.preventDefault();
        if (!selected)
            return setError("Select a member before generating the challan.");
        setBusy(true);
        setError("");
        try {
            const { data } = await axios.post("/api/challans", {
                ...form,
                member_id: selected.id,
            });
            onSaved(data);
        } catch (e) {
            const errors = e.response?.data?.errors;
            setError(
                errors
                    ? Object.values(errors)[0][0]
                    : e.response?.data?.message ||
                          "Unable to generate challan.",
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="modal-backdrop">
            <form className="modal challan-modal" onSubmit={submit}>
                <div className="modal-head">
                    <div>
                        <span className="eyebrow">SAP-READY BILLING</span>
                        <h2>Generate challan</h2>
                    </div>
                    <button type="button" onClick={onClose}>
                        <X />
                    </button>
                </div>
                <p className="modal-copy">
                    Create a controlled challan using the member's SAP customer
                    and reconciliation-account references.
                </p>
                {error && <div className="alert">{error}</div>}
                <label className="member-lookup">
                    Member search
                    <div className="search">
                        <Search />
                        <input
                            placeholder="Name, customer number, CNIC or phone"
                            value={query}
                            onChange={(e) => {
                                setQuery(e.target.value);
                                setSelected(null);
                            }}
                        />
                    </div>
                </label>
                {members.length > 0 && (
                    <div className="lookup-results">
                        {members.map((member) => (
                            <button
                                type="button"
                                key={member.id}
                                onClick={() => {
                                    setSelected(member);
                                    setQuery(member.full_name);
                                    setMembers([]);
                                }}
                            >
                                <span>
                                    <b>{member.full_name}</b>
                                    <small>
                                        {member.customer_no} ·{" "}
                                        {member.sports_complex?.name}
                                    </small>
                                </span>
                                <strong>Select</strong>
                            </button>
                        ))}
                    </div>
                )}
                {selected && (
                    <div className="selected-member">
                        <div>
                            <span>SAP customer</span>
                            <b>{selected.customer_no}</b>
                        </div>
                        <div>
                            <span>Member</span>
                            <b>{selected.full_name}</b>
                        </div>
                        <div>
                            <span>Complex</span>
                            <b>{selected.sports_complex?.name}</b>
                        </div>
                    </div>
                )}
                <div className="form-grid">
                    <label className="wide">
                        Purpose
                        <input
                            value={form.purpose}
                            onChange={(e) =>
                                setForm({ ...form, purpose: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Amount (PKR)
                        <input
                            type="number"
                            min="1"
                            step="0.01"
                            value={form.amount}
                            onChange={(e) =>
                                setForm({ ...form, amount: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Issue date
                        <input
                            type="date"
                            value={form.issued_on}
                            onChange={(e) =>
                                setForm({ ...form, issued_on: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Due date
                        <input
                            type="date"
                            value={form.due_on}
                            onChange={(e) =>
                                setForm({ ...form, due_on: e.target.value })
                            }
                        />
                    </label>
                    <label className="wide">
                        Notes
                        <textarea
                            value={form.notes}
                            onChange={(e) =>
                                setForm({ ...form, notes: e.target.value })
                            }
                        />
                    </label>
                </div>
                <div className="sap-note">
                    <b>SAP posting remains controlled.</b> The application
                    creates the SAP payload and printable challan. It will
                    remain “Not posted” until a real SAP document number is
                    recorded.
                </div>
                <div className="modal-actions">
                    <button
                        type="button"
                        className="secondary"
                        onClick={onClose}
                    >
                        Cancel
                    </button>
                    <button className="primary" disabled={busy}>
                        {busy ? "Generating…" : "Generate challan"}
                    </button>
                </div>
            </form>
        </div>
    );
}

function Challans() {
    const [result, setResult] = useState(null);
    const [q, setQ] = useState("");
    const [status, setStatus] = useState("");
    const [page, setPage] = useState(1);
    const [modal, setModal] = useState(false);
    const [sapModal, setSapModal] = useState(null);
    const [created, setCreated] = useState(null);
    async function load() {
        const { data } = await axios.get("/api/challans", {
            params: { q, status, page },
        });
        setResult(data);
    }
    useEffect(() => {
        const timer = setTimeout(load, 250);
        return () => clearTimeout(timer);
    }, [q, status, page]);
    async function markPaid(challan) {
        await axios.put(`/api/challans/${challan.id}`, { status: "paid" });
        load();
    }
    async function recordSap(e) {
        e.preventDefault();
        const documentNo = new FormData(e.currentTarget).get("sap_document_no");
        await axios.put(`/api/challans/${sapModal.id}`, {
            status: sapModal.status,
            sap_status: "posted",
            sap_document_no: documentNo,
        });
        setSapModal(null);
        load();
    }
    return (
        <>
            <header className="page-header">
                <div>
                    <span className="eyebrow">SAP CHALLAN MANAGEMENT</span>
                    <h1>Challans</h1>
                    <p>
                        Generate, print, reconcile, and track member challans
                        against SAP references.
                    </p>
                </div>
                <button
                    className="primary"
                    onClick={() => {
                        setCreated(null);
                        setModal(true);
                    }}
                >
                    <ReceiptText />
                    Generate challan
                </button>
            </header>
            {created && (
                <div className="success-banner">
                    <span>
                        <b>{created.challan_no}</b> was generated for{" "}
                        {created.member.full_name}.
                    </span>
                    <a
                        href={appPath(`/challans/${created.id}/print`)}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <Printer />
                        Print challan
                    </a>
                </div>
            )}
            <section className="panel member-panel">
                <div className="filters">
                    <div className="search">
                        <Search />
                        <input
                            placeholder="Search challan, member or SAP document no."
                            value={q}
                            onChange={(e) => {
                                setQ(e.target.value);
                                setPage(1);
                            }}
                        />
                    </div>
                    <select
                        value={status}
                        onChange={(e) => {
                            setStatus(e.target.value);
                            setPage(1);
                        }}
                    >
                        <option value="">All statuses</option>
                        <option value="issued">Issued</option>
                        <option value="paid">Paid</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Challan</th>
                                <th>Member</th>
                                <th>Purpose</th>
                                <th>Due</th>
                                <th>Amount</th>
                                <th>SAP</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {result?.data.map((c) => (
                                <tr key={c.id}>
                                    <td>
                                        <b>{c.challan_no}</b>
                                        <small>{c.sports_complex?.code}</small>
                                    </td>
                                    <td>
                                        <b>{c.member?.full_name}</b>
                                        <small>{c.member?.customer_no}</small>
                                    </td>
                                    <td>{c.purpose}</td>
                                    <td>
                                        {new Date(
                                            c.due_on,
                                        ).toLocaleDateString()}
                                    </td>
                                    <td>
                                        <b>
                                            PKR{" "}
                                            {Number(c.amount).toLocaleString()}
                                        </b>
                                        <small>
                                            <span
                                                className={"status " + c.status}
                                            >
                                                {c.status}
                                            </span>
                                        </small>
                                    </td>
                                    <td>
                                        <span
                                            className={
                                                "sap-status " + c.sap_status
                                            }
                                        >
                                            {c.sap_status.replace("_", " ")}
                                        </span>
                                        {c.sap_document_no && (
                                            <small>{c.sap_document_no}</small>
                                        )}
                                    </td>
                                    <td className="actions">
                                        <a
                                            className="text-button"
                                            href={appPath(`/challans/${c.id}/print`)}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Print
                                        </a>
                                        {c.status === "issued" && (
                                            <button
                                                className="text-button"
                                                onClick={() => markPaid(c)}
                                            >
                                                Paid
                                            </button>
                                        )}
                                        {c.sap_status !== "posted" && (
                                            <button
                                                className="text-button"
                                                onClick={() => setSapModal(c)}
                                            >
                                                SAP ref
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {result && (
                    <div className="pagination">
                        <span>
                            Showing {result.from || 0}–{result.to || 0} of{" "}
                            {result.total.toLocaleString()}
                        </span>
                        <div>
                            <button
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                <ChevronLeft />
                            </button>
                            <b>
                                Page {result.current_page} of {result.last_page}
                            </b>
                            <button
                                disabled={page === result.last_page}
                                onClick={() => setPage(page + 1)}
                            >
                                <ChevronRight />
                            </button>
                        </div>
                    </div>
                )}
            </section>
            {modal && (
                <ChallanModal
                    onClose={() => setModal(false)}
                    onSaved={(challan) => {
                        setModal(false);
                        setCreated(challan);
                        load();
                    }}
                />
            )}
            {sapModal && (
                <div className="modal-backdrop">
                    <form className="modal sap-modal" onSubmit={recordSap}>
                        <div className="modal-head">
                            <div>
                                <span className="eyebrow">
                                    SAP RECONCILIATION
                                </span>
                                <h2>Record SAP posting</h2>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSapModal(null)}
                            >
                                <X />
                            </button>
                        </div>
                        <p className="modal-copy">
                            Record the document number returned by SAP for{" "}
                            <b>{sapModal.challan_no}</b>. This marks only the
                            SAP posting state; it does not mark the challan
                            paid.
                        </p>
                        <label>
                            SAP document number
                            <input
                                name="sap_document_no"
                                required
                                maxLength="40"
                                autoFocus
                                placeholder="e.g. 1400001234"
                            />
                        </label>
                        <div className="modal-actions">
                            <button
                                type="button"
                                className="secondary"
                                onClick={() => setSapModal(null)}
                            >
                                Cancel
                            </button>
                            <button className="primary">
                                Confirm SAP posting
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </>
    );
}

function App() {
    const [user, setUser] = useState(undefined);
    const [view, setView] = useState("dashboard");
    const [dashboard, setDashboard] = useState(null);
    const [reference, setReference] = useState({ complexes: [], plans: [], fee_types: [] });
    const [menu, setMenu] = useState(false);
    useEffect(() => {
        axios
            .get("/api/me")
            .then((r) => setUser(r.data))
            .catch(() => setUser(null));
    }, []);
    useEffect(() => {
        if (user && !user.must_change_password) {
            axios.get("/api/dashboard").then((r) => setDashboard(r.data));
            axios.get("/api/reference-data").then((r) => setReference(r.data));
        }
    }, [user]);
    async function logout() {
        await axios.post("/api/logout");
        setUser(null);setView("dashboard");setDashboard(null);setReference({complexes:[],plans:[],fee_types:[]});
    }
    if (user === undefined)
        return (
            <div className="splash">
                <Activity />
                Loading…
            </div>
        );
    if (!user) return <Login onLogin={setUser} />;
    if(user.must_change_password)return <StaffPasswordChange user={user} onChanged={setUser} onLogout={logout}/>;
    return (
        <div className="app-shell">
            <Sidebar
                view={view}
                setView={setView}
                user={user}
                onLogout={logout}
                open={menu}
                setOpen={setMenu}
            />
            <main className="content">
                <div className="bank-test-banner">LDA SPORTS COMPLEX - BANK TEST ENVIRONMENT</div>{user.sports_complex&&<div className="complex-scope-banner"><Building2 size={18}/><b>{user.sports_complex.name}</b><span>Assigned customer management</span></div>}
                <button className="menu-button" onClick={() => setMenu(true)}>
                    <Menu />
                </button>
                {view === "dashboard" ? (
                    <Dashboard
                        data={dashboard}
                        complexName={user.sports_complex?.name}
                        onMembers={() => setView("members")}
                    />
                ) : view === "staff-access" && user.role === "admin" && !user.sports_complex_id && !user.is_complex_admin ? (
                    <StaffAccess/>
                ) : view === "reconciliation" ? (
                    <ReconciliationAccounts user={user} onChanged={()=>axios.get("/api/reference-data").then(r=>setReference(r.data))}/>
                ) : view === "challans" ? (
                    <SapChallans reference={reference} user={user} />
                ) : view === "appointments" ? (
                    <AdminAppointments reference={reference} />
                ) : view === "bank-payments" ? (
                    <BankPayments user={user} />
                ) : view === "complex-images" ? (
                    <ComplexImages reference={reference} />
                ) : view === "facilities" ? (
                    <Facilities reference={reference} />
                ) : (
                    <Members reference={reference} user={user} />
                )}
            </main>
        </div>
    );
}

createRoot(document.getElementById("app")).render(
    appPathname().startsWith("/admin") ? <App /> : <PublicPortal />,
);
