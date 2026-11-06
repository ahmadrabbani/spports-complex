import React, { useEffect, useState } from "react";
import axios from "axios";
import { Activity, Building2, Copy, Dumbbell, Pencil, Plus, Trash2, X } from "lucide-react";

const templates = [
    {name: "Gymnasium", icon: "gym", description: "Fully equipped gymnasium for fitness and strength training."},
    {name: "Swimming Pools", icon: "swimming", description: "Swimming facilities with managed timings and coaching support."},
    {name: "Badminton Courts", icon: "badminton", description: "Indoor badminton courts for training and recreational play."},
    {name: "Squash Courts", icon: "squash", description: "Purpose-built squash courts for members."},
    {name: "Basketball Court", icon: "basketball", description: "Basketball court for practice and organized play."},
    {name: "Table Tennis", icon: "table-tennis", description: "Table tennis facility for members of all skill levels."},
    {name: "Snooker Club", icon: "snooker", description: "Managed snooker facility for members."},
    {name: "Jogging Track", icon: "running", description: "Outdoor jogging and walking track."},
];
const emptyForm = {name: "", slug: "", icon: "fitness", description: "", image_url: "", capacity: "", hourly_rate: "0", is_active: true, operational_status: "available", maintenance_notice: "", maintenance_starts_at: "", maintenance_ends_at: ""};
const emoji = (icon) => ({gym:"🏋️",swimming:"🏊",badminton:"🏸",squash:"🎾",basketball:"🏀","table-tennis":"🏓",snooker:"🎱",running:"🏃",fitness:"🧘"}[icon] || "⚡");

export default function Facilities({ reference }) {
    const [complexes, setComplexes] = useState(reference.complexes || []);
    const [selectedId, setSelectedId] = useState(reference.complexes?.[0]?.id || "");
    const [modal, setModal] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [targetIds, setTargetIds] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const selected = complexes.find((complex) => String(complex.id) === String(selectedId));

    useEffect(() => {
        setComplexes(reference.complexes || []);
        if (!selectedId && reference.complexes?.length) setSelectedId(reference.complexes[0].id);
    }, [reference]);

    async function refresh() {
        const { data } = await axios.get("/api/reference-data");
        setComplexes(data.complexes);
    }

    function openCreate(template = {}) {
        setEditing(null);
        setForm({...emptyForm, ...template});
        setTargetIds(selectedId ? [Number(selectedId)] : []);
        setError("");
        setModal(true);
    }

    function openEdit(facility) {
        setEditing(facility);
        setForm({...emptyForm, ...facility, capacity: facility.capacity || "", hourly_rate: facility.hourly_rate || "0", maintenance_starts_at: facility.maintenance_starts_at?.slice(0,16) || "", maintenance_ends_at: facility.maintenance_ends_at?.slice(0,16) || ""});
        setTargetIds([]);
        setError("");
        setModal(true);
    }

    function toggleTarget(id) {
        setTargetIds(targetIds.includes(id) ? targetIds.filter((item) => item !== id) : [...targetIds, id]);
    }

    async function save(event) {
        event.preventDefault();
        setBusy(true);
        setError("");
        const payload = {...form, capacity: form.capacity || null, hourly_rate: form.hourly_rate || 0, maintenance_starts_at: form.maintenance_starts_at || null, maintenance_ends_at: form.maintenance_ends_at || null};
        try {
            if (editing) await axios.put(`/api/admin/facilities/${editing.id}`, payload);
            else await axios.post("/api/admin/facilities/bulk", {...payload, sports_complex_ids: targetIds});
            await refresh();
            setModal(false);
        } catch (exception) {
            const errors = exception.response?.data?.errors;
            setError(errors ? Object.values(errors)[0][0] : exception.response?.data?.message || "Unable to save the facility.");
        } finally {
            setBusy(false);
        }
    }

    async function remove(facility) {
        if (!window.confirm(`Delete ${facility.name} from ${selected.name}?`)) return;
        setBusy(true);
        try {
            await axios.delete(`/api/admin/facilities/${facility.id}`);
            await refresh();
        } finally {
            setBusy(false);
        }
    }

    return <>
        <header className="page-header"><div><span className="eyebrow">FACILITY DIRECTORY</span><h1>Facilities</h1><p>Maintain complex facilities once, or assign common facilities to several locations together.</p></div><button className="primary" onClick={() => openCreate()}><Plus/> Add facility</button></header>
        <section className="facility-admin-layout">
            <aside className="complex-picker"><h3>Sports complexes</h3>{complexes.map((complex) => <button key={complex.id} className={String(complex.id) === String(selectedId) ? "active" : ""} onClick={() => setSelectedId(complex.id)}><span><Building2/></span><div><b>{complex.name}</b><small>{complex.facilities?.length || 0} facilities</small></div></button>)}</aside>
            <div className="facility-admin-main"><section className="facility-template-panel"><div><Copy/><span><b>Common facility templates</b><small>Select a template, then assign it to one or many complexes.</small></span></div><div>{templates.map((template) => <button key={template.name} onClick={() => openCreate(template)}><span>{emoji(template.icon)}</span>{template.name}</button>)}</div></section><div className="facility-admin-head"><div><h2>{selected?.name}</h2><p>{selected?.facilities?.length || 0} configured facilities</p></div><button className="secondary" onClick={() => openCreate()}><Plus/> Add here</button></div><div className="facility-admin-grid">{selected?.facilities?.map((facility) => <article className={!facility.is_active ? "inactive" : facility.operational_status || "available"} key={facility.id}><span className="facility-admin-icon">{emoji(facility.icon)}</span><div><h3>{facility.name}</h3><p>{facility.description || "No description provided."}</p><small>{facility.capacity ? `Capacity ${facility.capacity}` : "No capacity limit"} · Available to active members</small>{facility.operational_status === "maintenance" && <small className="maintenance-text">{facility.maintenance_notice}</small>}</div><em>{!facility.is_active ? "Inactive" : facility.operational_status?.replaceAll("_", " ")}</em><footer><button onClick={() => openEdit(facility)}><Pencil/> Edit</button><button className="danger" disabled={busy} onClick={() => remove(facility)}><Trash2/> Delete</button></footer></article>)}{selected && !selected.facilities?.length && <div className="empty-state facility-empty"><Dumbbell/><b>No facilities configured</b><span>Use a common template or add a custom facility.</span></div>}</div></div>
        </section>
        {modal && <div className="modal-backdrop"><form className="modal facility-modal" onSubmit={save}><div className="modal-head"><div><span className="eyebrow">{editing ? "EDIT FACILITY" : "ADD FACILITY"}</span><h2>{editing ? form.name : "Configure facility"}</h2></div><button type="button" onClick={() => setModal(false)}><X/></button></div>{error && <div className="alert">{error}</div>}<div className="form-grid"><label>Name<input required value={form.name} onChange={(event) => setForm({...form, name:event.target.value})}/></label><label>Icon<select value={form.icon || "fitness"} onChange={(event) => setForm({...form, icon:event.target.value})}>{["fitness","gym","swimming","badminton","squash","basketball","table-tennis","snooker","running"].map((icon) => <option key={icon} value={icon}>{emoji(icon)} {icon.replaceAll("-", " ")}</option>)}</select></label><label>Capacity<input type="number" min="1" value={form.capacity || ""} onChange={(event) => setForm({...form, capacity:event.target.value})}/></label><label>Hourly rate (PKR)<input type="number" min="0" step="0.01" value={form.hourly_rate || ""} onChange={(event) => setForm({...form, hourly_rate:event.target.value})}/></label><label>Operational status<select value={form.operational_status} onChange={(event) => setForm({...form, operational_status:event.target.value})}><option value="available">Available</option><option value="maintenance">Under maintenance</option><option value="closed">Closed</option></select></label><label>Maintenance notice<input required={form.operational_status === "maintenance"} value={form.maintenance_notice || ""} onChange={(event) => setForm({...form, maintenance_notice:event.target.value})}/></label><label>Maintenance starts<input type="datetime-local" value={form.maintenance_starts_at || ""} onChange={(event) => setForm({...form, maintenance_starts_at:event.target.value})}/></label><label>Expected reopening<input type="datetime-local" value={form.maintenance_ends_at || ""} onChange={(event) => setForm({...form, maintenance_ends_at:event.target.value})}/></label><label className="wide">Description<textarea maxLength="2000" value={form.description || ""} onChange={(event) => setForm({...form, description:event.target.value})}/></label><label className="wide">Image URL<input type="url" value={form.image_url || ""} onChange={(event) => setForm({...form, image_url:event.target.value})}/></label><label className="check-line wide"><input type="checkbox" checked={Boolean(form.is_active)} onChange={(event) => setForm({...form, is_active:event.target.checked})}/> Active and visible to members</label></div>{!editing && <section className="facility-targets"><div><b>Assign to sports complexes</b><button type="button" className="text-button" onClick={() => setTargetIds(complexes.map((complex) => complex.id))}>Select all</button></div><div>{complexes.map((complex) => <label key={complex.id}><input type="checkbox" checked={targetIds.includes(complex.id)} onChange={() => toggleTarget(complex.id)}/>{complex.name}</label>)}</div></section>}<div className="modal-actions"><button type="button" className="secondary" onClick={() => setModal(false)}>Cancel</button><button className="primary" disabled={busy || (!editing && !targetIds.length)}>{busy ? "Saving…" : editing ? "Save changes" : `Add to ${targetIds.length} complex${targetIds.length === 1 ? "" : "es"}`}</button></div></form></div>}
    </>;
}
