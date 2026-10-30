// Notch Toast (notch-toast, MIT) for Stackless's wp-admin screens, built into
// stackless/assets/js/toast.js by tools/build/toast.mjs. React comes from WordPress (the react,
// react-dom and react-jsx-runtime script handles), so only notch-toast and motion are bundled.
//
// One toaster per page, latest toast wins. Other scripts call window.StacklessToast instead of
// importing anything, and keep their inline notices when it is missing.
import { createElement } from "react";
import { createRoot } from "react-dom/client";
import { NotchToaster, toast } from "notch-toast";

const PREFIX = "stackless";
const GLOBAL = "StacklessToast";

let current = null;

function mount() {
    if (document.querySelector(`.${PREFIX}-toaster`)) return;
    const host = document.createElement("div");
    host.className = `${PREFIX}-toaster`;
    document.body.append(host);
    createRoot(host).render(createElement(NotchToaster, { position: "top-center", mode: "attached", animation: "smooth" }));
}

/**
 * Shows a toast. type: success, error, warning, info, loading or default. Errors and warnings stay
 * until dismissed; the rest close on their own. action: { label, onClick }.
 */
function show(message, { type = "default", duration, action } = {}) {
    if (!message) return "";
    const options = { duration, action };
    const id = type === "default" ? toast(message, options) : toast[type](message, options);
    current = { id, type };
    return id;
}

const api = {
    show,
    success: (message, options = {}) => show(message, { ...options, type: "success" }),
    error: (message, options = {}) => show(message, { ...options, type: "error" }),
    warning: (message, options = {}) => show(message, { ...options, type: "warning" }),
    info: (message, options = {}) => show(message, { ...options, type: "info" }),
    loading: (message, options = {}) => show(message, { ...options, type: "loading" }),
    promise: (promise, messages) => toast.promise(promise, messages),
    update: (id, patch) => toast.update(id, patch),
    /** Closes the toast; with onlyErrors, only an error or warning still waiting to be read. */
    dismiss(onlyErrors = false) {
        if (onlyErrors && !(current && (current.type === "error" || current.type === "warning"))) return;
        toast.dismiss();
        current = null;
    },
};
window[GLOBAL] = api;

// "Settings saved." and similar confirmations: the server prints them inline for pages without
// JavaScript; here they become a toast and the inline copy goes away.
function flashes() {
    const attribute = `data-${PREFIX}-toast`;
    const alerts = document.querySelectorAll(`[${attribute}]`);
    for (const alert of alerts) {
        api.show(alert.getAttribute(attribute), { type: alert.getAttribute(`${attribute}-type`) || "success" });
        alert.remove();
    }
    // A reload should not show the same confirmation again.
    const url = new URL(window.location.href);
    const keys = ["saved", "updated", "notice", "message", `${PREFIX}_message`, `${PREFIX}_error`].filter((key) => url.searchParams.has(key));
    if (alerts.length && keys.length) {
        keys.forEach((key) => url.searchParams.delete(key));
        window.history.replaceState(window.history.state, "", url);
    }
}

function start() {
    mount();
    flashes();
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
else start();
