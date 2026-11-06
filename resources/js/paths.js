import axios from "axios";

export const appBase = (document.querySelector('meta[name="app-base"]')?.content || "").replace(/\/$/, "");
export function appPath(path) {
    if (!appBase || typeof path !== "string" || !path.startsWith("/") || path.startsWith("//") || path === appBase || path.startsWith(`${appBase}/`)) return path;
    return `${appBase}${path}`;
}
export function appPathname() {
    const path = window.location.pathname;
    return appBase && (path === appBase || path.startsWith(`${appBase}/`)) ? path.slice(appBase.length) || "/" : path;
}
axios.interceptors.request.use(config => { config.url = appPath(config.url); return config; });
