import { appPath, appPathname } from "./paths";
import React, { useEffect, useMemo, useRef, useState } from "react";
import { ArrowRight, ImageOff, MapPin } from "lucide-react";
import ashenPressSource from "../../src/shaders/ashen-press/sources/ashen-press.runtime.html?raw";

const palette = ["#155f85", "#347f54", "#b56b2f", "#5552a4", "#8e3d59", "#2c7180", "#93712e", "#3f668f", "#5f7650", "#7d526f"];
const categoryLabels = {
    main_exterior: "Main exterior",
    swimming_pool: "Swimming pool",
    gym_fitness: "Gym & fitness",
    badminton: "Badminton",
    tennis: "Tennis",
    basketball: "Basketball",
    cricket: "Cricket",
    indoor_sports: "Indoor sports",
    outdoor_grounds: "Outdoor grounds",
    community_recreation: "Community & recreation",
};

const isActualPhoto = (image) => !/ai[- ]enhanced|architectural view/i.test(`${image?.alt_text || ""} ${image?.url || ""}`);
const safeJson = (value) => JSON.stringify(value).replaceAll("<", "\\u003c");
const splitTitle = (title) => {
    const words = title.replace(/\s+Sports Complex$/i, "").split(/\s+/);
    const middle = Math.ceil(words.length / 2);
    return [words.slice(0, middle).join(" "), words.slice(middle).join(" ")];
};
const roman = (number) => ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X"][number] || String(number + 1);

function galleryItems(complexes, detailMode) {
    const selected = detailMode
        ? complexes.flatMap((complex) => {
            const photos = (complex.images || []).filter(isActualPhoto).slice(0, 10);
            return photos.length ? photos.map((image) => ({ complex, image })) : [{ complex, image: null }];
        })
        : complexes.slice(0, 10).map((complex) => ({
            complex,
            image: (complex.images || []).filter(isActualPhoto).find((item) => item.is_featured)
                || (complex.images || []).find(isActualPhoto)
                || null,
        }));

    return selected.slice(0, 10).map(({ complex, image }, index) => {
        const [t1, t2] = splitTitle(image?.title || complex.name);
        const facilities = (complex.facilities || []).map((facility) => facility.name);
        const category = categoryLabels[image?.facility_category] || (image ? "Complex gallery" : "Photo coming soon");
        const description = image?.caption
            || complex.description
            || (facilities.length ? `Facilities include ${facilities.slice(0, 4).join(", ")}.` : "Facility information will appear when configured by the complex.");
        return {
            id: `lda-${complex.id}-${image?.id || `placeholder-${index}`}`,
            title: image?.title || complex.name,
            t1: t1.toUpperCase(),
            t2: t2.toUpperCase(),
            sub: `${category.toUpperCase()} · ${complex.city || "LAHORE"}`,
            year: complex.code || "LDA",
            vol: roman(index),
            shelf: index < 5 ? 0 : 1,
            slot: index % 5,
            spine: palette[index % palette.length],
            cloth: "#111b24",
            edge: "#eee7d7",
            blurb: description,
            imageUrl: image?.url || null,
            viewPath: `/sports-complexes/${complex.slug}`,
            bookPath: `/book-appointment?complex=${complex.id}`,
        };
    });
}

async function photoDataUrl(url, signal) {
    const response = await fetch(appPath(url), { signal, credentials: "same-origin" });
    if (!response.ok) throw new Error(`Image request failed: ${response.status}`);
    const blob = await response.blob();
    let bitmap;
    let cleanup = () => {};
    if (typeof createImageBitmap === "function") {
        bitmap = await createImageBitmap(blob);
        cleanup = () => bitmap.close?.();
    } else {
        const objectUrl = URL.createObjectURL(blob);
        bitmap = await new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = reject;
            image.src = objectUrl;
        });
        cleanup = () => URL.revokeObjectURL(objectUrl);
    }
    const canvas = document.createElement("canvas");
    const width = 900;
    const height = 1350;
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext("2d");
    const sourceWidth = bitmap.width || bitmap.naturalWidth;
    const sourceHeight = bitmap.height || bitmap.naturalHeight;
    const scale = Math.max(width / sourceWidth, height / sourceHeight);
    const drawWidth = sourceWidth * scale;
    const drawHeight = sourceHeight * scale;
    context.drawImage(bitmap, (width - drawWidth) / 2, (height - drawHeight) / 2, drawWidth, drawHeight);
    cleanup();
    return canvas.toDataURL("image/webp", 0.82);
}

function buildDocument(items, plates) {
    let source = ashenPressSource;
    source = source.replace("<script type=\"importmap\">", `<script>\nconst reportGalleryError=(message)=>parent.postMessage({source:'lda-sports-gallery',action:'error',message:String(message)},'*');\naddEventListener('error',(event)=>reportGalleryError(event.message||event.error));\naddEventListener('unhandledrejection',(event)=>reportGalleryError(event.reason));\n</script>\n<script type=\"importmap\">`);
    source = source.replace("Ashen Press — The Art Book Shelf", "LDA Sports Complex Gallery");
    source = source.replace("https://unpkg.com/three@0.181.0/build/three.module.js", appPath("/api/public/gallery/three.module.js"));
    source = source.replace("import * as THREE from 'three';", `import * as THREE from '${appPath("/api/public/gallery/three.module.js")}';`);
    source = source.replace('<span class="brandname rise" data-d="70">Ashen Press</span>', '<span class="brandname rise" data-d="70">LDA Sports Gallery</span>');
    source = source.replace('<a class="pill rise" data-d="285" href="#" onclick="return false">Join the press</a>', '<button class="pill rise" data-d="285" id="explore-all">Explore all complexes</button>');
    source = source.replace('<button id="detail-look">Look inside</button>\n    <button id="detail-close">Close</button>', '<button id="detail-look">View Complex Details</button>\n    <button id="detail-book">Book Appointment</button>\n    <button id="detail-close">Close</button>');
    source = source.replace('<div class="hint" id="hint">Drag to turn</div>', '<div class="hint" id="hint">Select a complex</div>');
    source = source.replace('<button class="solid" id="mbar-look">Look inside</button>', '<button class="solid" id="mbar-look">Book</button>');
    source = source.replace(/const STUDIO = 'FromSoftware';\nconst BOOKS = \[[\s\S]*?\n\];/, `const STUDIO = 'LDA Sports';\nconst BOOKS = ${safeJson(items.map(({ imageUrl, viewPath, bookPath, ...item }) => ({ ...item, viewPath, bookPath })))};\nconst DYNAMIC_PLATES = ${safeJson(plates)};`);
    source = source.replace("function loadPlates(){", "Object.assign(PLATE_SRC,DYNAMIC_PLATES);\nfunction loadPlates(){");
    source = source.replace("const dLook=document.getElementById('detail-look'), dClose=document.getElementById('detail-close');", "const dLook=document.getElementById('detail-look'), dBook=document.getElementById('detail-book'), dClose=document.getElementById('detail-close');\nconst emitAction=(action,data)=>parent.postMessage({source:'lda-sports-gallery',action,path:action==='book'?data.bookPath:data.viewPath},'*');\ndocument.getElementById('explore-all').addEventListener('click',()=>parent.postMessage({source:'lda-sports-gallery',action:'explore',path:'/sports-complexes'},'*'));");
    source = source.replace("dLook.addEventListener('click',e=>{e.stopPropagation();flipActive();});", "dLook.addEventListener('click',e=>{e.stopPropagation();if(S.active)emitAction('view',S.active.data);});\ndBook.addEventListener('click',e=>{e.stopPropagation();if(S.active)emitAction('book',S.active.data);});");
    source = source.replace("if(B)hoverIn(B);", "if(B){hoverIn(B);hintEl.textContent=B.data.title+' — '+B.data.blurb;hintEl.classList.add('on');}else{hintEl.classList.remove('on');}");
    source = source.replace("mLook.addEventListener('click',e=>{e.stopPropagation();lookInside(books[M.index]);});", "mLook.addEventListener('click',e=>{e.stopPropagation();emitAction('book',BOOKS[M.index]);});");
    source = source.replace("M.lastY=e.touches[0].clientY", "M.lastY=e.touches[0].clientX");
    source = source.replace("const y=e.touches[0].clientY; mobileScrollBy((M.lastY-y)/190); M.lastY=y;", "const x=e.touches[0].clientX; mobileScrollBy((M.lastY-x)/150); M.lastY=x;");
    source = source.replace("</style>", `.detail .acts{max-width:520px;flex-wrap:wrap}.detail .acts button{white-space:nowrap}.hint{max-width:310px;line-height:1.35}.pill{font-family:Inter,sans-serif}body.mobile .mbar-acts button{white-space:nowrap}@media(max-width:767px){.mbar{touch-action:pan-y}.mbar-txt{max-width:48vw}}\n</style>`);
    return source;
}

function supportsWebGl() {
    try {
        const canvas = document.createElement("canvas");
        return Boolean(window.WebGLRenderingContext && (canvas.getContext("webgl2") || canvas.getContext("webgl")));
    } catch {
        return false;
    }
}

export default function SportsComplexGallery({ complexes = [], detailMode = false, onNavigate }) {
    const hostRef = useRef(null);
    const frameRef = useRef(null);
    const [visible, setVisible] = useState(false);
    const [plates, setPlates] = useState({});
    const [ready, setReady] = useState(false);
    const [failure, setFailure] = useState("");
    const items = useMemo(() => galleryItems(complexes, detailMode), [complexes, detailMode]);
    const webGl = useMemo(() => supportsWebGl(), []);

    useEffect(() => {
        const host = hostRef.current;
        if (!host || typeof IntersectionObserver === "undefined") {
            setVisible(true);
            return undefined;
        }
        const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting), { rootMargin: "240px" });
        observer.observe(host);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (!visible || !webGl) return undefined;
        const controller = new AbortController();
        Promise.all(items.filter((item) => item.imageUrl).map(async (item) => {
            try {
                return [item.id, await photoDataUrl(item.imageUrl, controller.signal)];
            } catch {
                return [item.id, null];
            }
        })).then((values) => {
            if (!controller.signal.aborted) setPlates(Object.fromEntries(values.filter(([, value]) => value)));
        });
        return () => controller.abort();
    }, [items, visible, webGl]);

    useEffect(() => {
        const receive = (event) => {
            if (event.source !== frameRef.current?.contentWindow || event.data?.source !== "lda-sports-gallery") return;
            if (event.data.action === "error") {
                setFailure(event.data.message || "The 3D renderer could not start.");
                return;
            }
            onNavigate?.(event.data.path);
        };
        window.addEventListener("message", receive);
        return () => window.removeEventListener("message", receive);
    }, [onNavigate]);

    const source = useMemo(() => buildDocument(items, plates), [items, plates]);
    useEffect(() => setReady(false), [source]);
    const fallback = !webGl || Boolean(failure) || window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;

    return <section ref={hostRef} className={`sports-3d-gallery ${detailMode ? "detail-gallery" : ""}`} aria-label="Interactive sports complex gallery" data-render-error={failure || undefined}>
        {fallback ? <div className="sports-gallery-fallback">{items.map((item) => <article key={item.id}>{item.imageUrl ? <img src={appPath(item.imageUrl)} alt={item.title} loading="lazy"/> : <div className="gallery-placeholder"><ImageOff/><span>Photo coming soon</span></div>}<div><span><MapPin/> {item.year}</span><h3>{item.title}</h3><p>{item.blurb}</p><button onClick={() => onNavigate?.(item.viewPath)}>View Complex Details <ArrowRight/></button></div></article>)}</div> : visible ? <>
            {!ready && <div className="sports-gallery-loading">Preparing interactive gallery…</div>}
            <iframe ref={frameRef} title="Interactive 3D LDA sports complex gallery" srcDoc={source} sandbox="allow-scripts" loading="lazy" onLoad={() => setReady(true)}/>
        </> : <div className="sports-gallery-loading">Interactive gallery loads when visible.</div>}
    </section>;
}
