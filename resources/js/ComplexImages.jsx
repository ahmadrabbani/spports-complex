import { appPath, appPathname } from "./paths";
import React, { useEffect, useState } from "react";
import axios from "axios";
import { ExternalLink, ImagePlus, Images, Save, Star, Trash2, Upload } from "lucide-react";

const categories = [
    ["main_exterior", "Main exterior"], ["swimming_pool", "Swimming pool"],
    ["gym_fitness", "Gym & fitness"], ["badminton", "Badminton"],
    ["tennis", "Tennis"], ["basketball", "Basketball"], ["cricket", "Cricket"],
    ["indoor_sports", "Indoor sports"], ["outdoor_grounds", "Outdoor grounds"],
    ["community_recreation", "Community & recreation"],
];

export default function ComplexImages({ reference }) {
    const [complexes, setComplexes] = useState(reference.complexes || []);
    const [selectedId, setSelectedId] = useState(reference.complexes?.[0]?.id || "");
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [drafts, setDrafts] = useState({});
    const selected = complexes.find((complex) => String(complex.id) === String(selectedId));

    useEffect(() => {
        setComplexes(reference.complexes || []);
        if (!selectedId && reference.complexes?.length) setSelectedId(reference.complexes[0].id);
    }, [reference]);

    useEffect(() => {
        setDrafts(Object.fromEntries((selected?.images || []).map((image) => [image.id, {
            title: image.title || "", caption: image.caption || "", alt_text: image.alt_text || "",
            facility_category: image.facility_category || "main_exterior", sort_order: image.sort_order ?? 0, replacement: null,
        }])));
    }, [selectedId, selected?.images]);

    async function refresh() {
        const { data } = await axios.get("/api/reference-data");
        setComplexes(data.complexes);
    }

    function message(exception, fallback) {
        const errors = exception.response?.data?.errors;
        return errors ? Object.values(errors)[0][0] : exception.response?.data?.message || fallback;
    }

    async function upload(event) {
        event.preventDefault(); setBusy(true); setError("");
        const form = event.currentTarget;
        try {
            await axios.post(`/api/accounting/sports-complexes/${selectedId}/images`, new FormData(form));
            form.reset(); await refresh();
        } catch (exception) { setError(message(exception, "Unable to upload the images.")); }
        finally { setBusy(false); }
    }

    function updateDraft(id, key, value) {
        setDrafts((current) => ({ ...current, [id]: { ...current[id], [key]: value } }));
    }

    async function save(image, feature = false) {
        setBusy(true); setError("");
        const draft = drafts[image.id] || {};
        const body = new FormData(); body.append("_method", "PUT");
        ["title", "caption", "alt_text", "facility_category", "sort_order"].forEach((key) => body.append(key, draft[key] ?? ""));
        if (draft.replacement) body.append("image", draft.replacement);
        if (feature) body.append("is_featured", "1");
        try {
            await axios.post(`/api/accounting/sports-complexes/${selectedId}/images/${image.id}`, body);
            await refresh();
        } catch (exception) { setError(message(exception, "Unable to update the image.")); }
        finally { setBusy(false); }
    }

    async function remove(image) {
        if (!window.confirm("Remove this image from the sports complex gallery?")) return;
        setBusy(true); setError("");
        try { await axios.delete(`/api/accounting/sports-complexes/${selectedId}/images/${image.id}`); await refresh(); }
        catch (exception) { setError(message(exception, "Unable to remove the image.")); }
        finally { setBusy(false); }
    }

    return <>
        <header className="page-header"><div><span className="eyebrow">PUBLIC PORTAL MEDIA</span><h1>Sports complex galleries</h1><p>Manage the real facility photography used by complex cards and the interactive 3D gallery.</p></div></header>
        {error && <div className="alert">{error}</div>}
        <section className="image-admin-layout">
            <aside className="complex-picker"><h3>Sports complexes</h3>{complexes.map((complex) => <button key={complex.id} className={String(complex.id) === String(selectedId) ? "active" : ""} onClick={() => setSelectedId(complex.id)}><span>{complex.image_url ? <img src={appPath(complex.image_url)} alt=""/> : <Images/>}</span><div><b>{complex.name}</b><small>{complex.images?.length || 0} image{complex.images?.length === 1 ? "" : "s"}</small></div></button>)}</aside>
            <div className="image-admin-main">
                <form className="image-upload gallery-upload" onSubmit={upload}>
                    <div><ImagePlus/><span><b>Add photos to {selected?.name}</b><small>Select up to 12 JPG, PNG, or WebP files (8 MB each)</small></span></div>
                    <input required multiple type="file" name="images[]" accept="image/jpeg,image/png,image/webp"/>
                    <input name="title" maxLength="120" placeholder="Shared title (optional)"/>
                    <input name="alt_text" maxLength="255" placeholder={`Alt text, e.g. ${selected?.name || "Sports complex"}`}/>
                    <select name="facility_category" defaultValue="main_exterior">{categories.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select>
                    <textarea name="caption" maxLength="1000" placeholder="Shared caption or short description"/>
                    <label><input type="checkbox" name="is_featured" value="1"/> Use first upload as featured image</label>
                    <button className="primary" disabled={busy || !selectedId}><Upload/> {busy ? "Saving…" : "Upload photos"}</button>
                </form>
                <div className="admin-image-grid">{selected?.images?.map((image) => {
                    const draft = drafts[image.id] || {};
                    return <article className={image.is_featured ? "featured" : ""} key={image.id}>
                        <div className="admin-image-preview"><img src={appPath(image.url)} alt={image.alt_text || selected.name}/>{image.is_featured && <span><Star/> Featured</span>}</div>
                        <div className="image-editor">
                            <input value={draft.title || ""} onChange={(event) => updateDraft(image.id, "title", event.target.value)} placeholder="Display title" maxLength="120"/>
                            <input value={draft.alt_text || ""} onChange={(event) => updateDraft(image.id, "alt_text", event.target.value)} placeholder="Accessible alt text" maxLength="255"/>
                            <select value={draft.facility_category || "main_exterior"} onChange={(event) => updateDraft(image.id, "facility_category", event.target.value)}>{categories.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select>
                            <textarea value={draft.caption || ""} onChange={(event) => updateDraft(image.id, "caption", event.target.value)} placeholder="Caption" maxLength="1000"/>
                            <label>Display order<input type="number" min="0" max="65535" value={draft.sort_order ?? 0} onChange={(event) => updateDraft(image.id, "sort_order", event.target.value)}/></label>
                            <label>Replace photo<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => updateDraft(image.id, "replacement", event.target.files?.[0] || null)}/></label>
                        </div>
                        {image.source_url && <a href={appPath(image.source_url)} target="_blank" rel="noreferrer">Official source <ExternalLink/></a>}
                        <footer><button className="secondary" disabled={busy} onClick={() => save(image)}><Save/> Save</button>{!image.is_featured && <button className="secondary" disabled={busy} onClick={() => save(image, true)}><Star/> Feature</button>}<button className="danger-button" disabled={busy} onClick={() => remove(image)}><Trash2/> Remove</button></footer>
                    </article>;
                })}{selected && !selected.images?.length && <div className="empty-state image-empty"><Images/><b>No images yet</b><span>Upload the first real photograph for this sports complex.</span></div>}</div>
            </div>
        </section>
    </>;
}
