import { appPath, appPathname } from "./paths";
import MemberDocuments from "./MemberDocuments";
import React, { useEffect, useState } from "react";
import axios from "axios";

export default function FamilyMembers({ memberId, portal = false, canUploadPhoto = false, canUploadDocuments = false, eligibilityKey = "" }) {
    const [revision,setRevision] = useState(0);
    useEffect(()=>{const refresh=e=>{if(e.detail?.memberId===memberId)setRevision(v=>v+1);};window.addEventListener("family-members-updated",refresh);return()=>window.removeEventListener("family-members-updated",refresh);},[memberId]);
    const [eligibility, setEligibility] = useState(null);
    const [members, setMembers] = useState([]);
    const [form, setForm] = useState({ full_name: "", relationship: "", date_of_birth: "" });
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const url = `${portal ? "/api/portal" : "/api"}/members/${memberId}/family-members`;
    useEffect(() => {
        const controller = new AbortController();
        setMembers([]); setEligibility(null); setError("");
        axios.get(url, { params: { include_eligibility: 1 }, signal: controller.signal }).then(({ data }) => {
            setMembers(data.members); setEligibility(data);
        }).catch(exception => { if (!axios.isCancel(exception)) setError("Unable to load family members."); });
        return () => controller.abort();
    }, [url, eligibilityKey, revision]);
    async function add(event) {
        event.preventDefault(); setBusy(true); setError("");
        try {
            const { data } = await axios.post(url, { ...form, date_of_birth: form.date_of_birth || null });
            setMembers(current => [...current, data]);
            setForm({ full_name: "", relationship: "", date_of_birth: "" });
        } catch (exception) {
            if (exception.response?.data?.code === "FAMILY_MEMBERS_NOT_ALLOWED") setEligibility({ can_add_family_members: false, restriction: exception.response.data.message });
            setError(Object.values(exception.response?.data?.errors || {}).flat()[0] || exception.response?.data?.message || "Unable to add family member."); }
        finally { setBusy(false); }
    }
    async function uploadPhoto(person, file) {
        if (!file) return;
        setBusy(true); setError('');
        try {
            const body = new FormData(); body.append('photo', file);
            const {data} = await axios.post(`/family-members/${person.id}/photo`, body);
            setMembers(current => current.map(p => p.id === person.id ? data.family_member : p));
        } catch (e) { setError(Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || 'Unable to save photograph.'); }
        finally { setBusy(false); }
    }
    return <section className="family-members"><h2>Family members</h2>{error && <p role="alert">{error}</p>}{canUploadPhoto && <p className="family-photo-help">Pictures: JPG, PNG or WebP, 240–6000 pixels, up to 8 MB. Saved as a square photo and included on the family card.</p>}
        {members.length ? <ul>{members.map(person => <li key={person.id} className="family-person">{person.photo_url && <img src={appPath(person.photo_url)} alt={`${person.full_name} photograph`} className="family-person-photo"/>}<b>{person.full_name}</b> — {person.relationship}{person.membership_validity && <small className="family-validity"> · {person.membership_validity === "lifetime" ? "Lifetime" : `Unmarried & below 25 · valid until ${person.membership_valid_until?.slice(0,10) || "Not recorded"}`}</small>}{person.parent_family_member_id && ` of ${members.find(parent => parent.id === person.parent_family_member_id)?.full_name || "family member"}`} <a href={appPath(`/family-members/${person.id}/card`)} target="_blank" rel="noreferrer">View card</a> · <a href={appPath(`/family-members/${person.id}/card.pdf`)}>Download digital card</a>{canUploadPhoto && <label className="family-photo-upload">{busy ? 'Saving…' : person.photo_url ? 'Change picture' : 'Add picture'}<input aria-label={`Picture for ${person.full_name}`} disabled={busy} type="file" accept="image/jpeg,image/png,image/webp" onChange={e => {uploadPhoto(person, e.target.files?.[0]); e.target.value = '';}}/></label>}{!portal && <MemberDocuments memberId={memberId} familyMemberId={person.id} canUpload={canUploadDocuments}/>}</li>)}</ul> : <p>No family members added yet.</p>}
        {eligibility && !eligibility.can_add_family_members && <p role="status">{eligibility.restriction}</p>}
        {eligibility?.can_add_family_members && <form onSubmit={add}><h3>Add family member</h3>
            <p>Linked to: <strong>Primary member</strong></p>
            <label>Full name<input required maxLength={255} value={form.full_name} onChange={e => setForm({ ...form, full_name: e.target.value })}/></label>
            <label>Relationship to primary member<select required value={form.relationship} onChange={e => setForm({ ...form, relationship: e.target.value })}><option value="">Select relationship</option><option value="Son">S/O — Son</option><option value="Wife">W/O — Wife</option><option value="Daughter">D/O — Daughter</option><option value="Son-in-law">Son-in-law</option><option value="Daughter-in-law">Daughter-in-law</option></select></label>
            <label>Date of birth (optional)<input type="date" value={form.date_of_birth} onChange={e => setForm({ ...form, date_of_birth: e.target.value })}/></label>
            <button className={portal ? "portal-primary" : "primary"} disabled={busy}>{busy ? "Adding…" : "Add family member"}</button>
        </form>}
    </section>;
}
