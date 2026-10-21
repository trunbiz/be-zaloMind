import { Modal, Offcanvas, Toast, Tooltip } from 'bootstrap';

const root = document.documentElement;
const compact = () => matchMedia('(min-width: 992px)').matches && root.dataset.sidebarCollapsed === 'true';
const groups = [...document.querySelectorAll('[data-nav-group]')];
const toggles = [...document.querySelectorAll('[data-sidebar-toggle]')];

function setGroup(group, open) {
    group.querySelector('[data-nav-toggle]').setAttribute('aria-expanded', String(open));
    group.querySelector('.sidebar-submenu').hidden = !open;
}

function resetGroups() {
    groups.forEach(group => setGroup(group, !compact() && group.classList.contains('is-active')));
    toggles.forEach(button => button.setAttribute('aria-expanded', String(root.dataset.sidebarCollapsed !== 'true')));
}

function openFlyout(group) {
    groups.forEach(item => setGroup(item, item === group));
    const menu = group.querySelector('.sidebar-submenu');
    const top = group.getBoundingClientRect().top;
    menu.style.setProperty('--flyout-top', `${Math.max(12, Math.min(top, innerHeight - menu.scrollHeight - 12))}px`);
}

groups.forEach(group => {
    const button = group.querySelector('[data-nav-toggle]');
    button.addEventListener('click', () => {
        if (compact()) {
            const wasOpen = button.getAttribute('aria-expanded') === 'true';
            if (wasOpen) setGroup(group, false);
            else openFlyout(group);
            return;
        }
        const open = button.getAttribute('aria-expanded') !== 'true';
        groups.forEach(item => setGroup(item, item === group && open));
    });
    group.addEventListener('pointerenter', () => { if (compact()) openFlyout(group); });
    group.addEventListener('pointerleave', () => { if (compact() && !group.contains(document.activeElement)) setGroup(group, false); });
    group.addEventListener('focusin', () => { if (compact()) openFlyout(group); });
    group.addEventListener('focusout', event => { if (compact() && !group.contains(event.relatedTarget)) setGroup(group, false); });
    group.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            button.focus();
            setGroup(group, false);
        }
    });
});
toggles.forEach(button => button.addEventListener('click', () => {
    root.dataset.sidebarCollapsed = String(root.dataset.sidebarCollapsed !== 'true');
    try { localStorage.setItem('zalomind.sidebarCollapsed', root.dataset.sidebarCollapsed); } catch {}
    resetGroups();
}));
matchMedia('(min-width: 992px)').addEventListener('change', resetGroups);
document.querySelector('.sidebar-nav-wrap')?.addEventListener('scroll', () => {
    if (compact()) groups.forEach(group => setGroup(group, false));
});
resetGroups();

const palette = document.querySelector('#command-palette');
const normalize = value => value.toLocaleLowerCase('vi').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');
if (palette) {
    const input = palette.querySelector('[data-command-search]');
    const items = [...palette.querySelectorAll('[data-command-item]')];
    const modal = Modal.getOrCreateInstance(palette);
    let selected = 0;
    const visible = () => items.filter(item => !item.hidden);
    function highlight() {
        items.forEach(item => item.classList.remove('selected'));
        const current = visible()[selected];
        current?.classList.add('selected');
        current?.scrollIntoView({ block: 'nearest' });
    }
    function filter() {
        const terms = normalize(input.value).trim().split(/\s+/).filter(Boolean);
        items.forEach(item => { item.hidden = !terms.every(term => normalize(item.textContent).includes(term)); });
        palette.querySelector('[data-command-empty]').hidden = visible().length !== 0;
        selected = 0;
        highlight();
    }
    input.addEventListener('input', filter);
    palette.addEventListener('shown.bs.modal', () => { input.value = ''; filter(); input.focus(); });
    input.addEventListener('keydown', event => {
        if (event.isComposing || !visible().length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            selected = (selected + (event.key === 'ArrowDown' ? 1 : -1) + visible().length) % visible().length;
            highlight();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            visible()[selected]?.click();
        }
    });
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            if (palette.classList.contains('show')) { modal.hide(); return; }
            if (document.querySelector('.modal.show')) return;
            const sidebar = document.querySelector('#workspace-sidebar.show');
            if (sidebar) {
                sidebar.addEventListener('hidden.bs.offcanvas', () => modal.show(), { once: true });
                Offcanvas.getOrCreateInstance(sidebar).hide();
            } else modal.show();
        }
    });
}

function notify(message, error = false) {
    const toast = document.querySelector('#app-toast');
    if (!toast || !message) return;
    toast.querySelector('.toast-body').textContent = message;
    toast.querySelector('.toast-header i').className = error ? 'bi bi-exclamation-circle text-danger me-2' : 'bi bi-check-circle text-success me-2';
    toast.setAttribute('role', error ? 'alert' : 'status');
    Toast.getOrCreateInstance(toast).show();
}
window.zaloMind = { ...(window.zaloMind || {}), notify };
const flash = document.querySelector('[data-flash-message]');
if (flash) notify(flash.dataset.flashMessage, flash.dataset.flashError === 'true');
document.addEventListener('zalomind:notify', event => notify(event.detail?.message, event.detail?.error));
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(element => new Tooltip(element));

const confirmation = document.querySelector('#confirm-action');
let pendingForm = null;
let pendingSubmitter = null;
if (confirmation) {
    confirmation.querySelector('[data-confirm-accept]').addEventListener('click', () => {
        const form = pendingForm;
        const submitter = pendingSubmitter;
        pendingForm = null;
        Modal.getOrCreateInstance(confirmation).hide();
        if (form) {
            form.dataset.confirmed = 'true';
            if (submitter) form.requestSubmit(submitter);
            else form.requestSubmit();
        }
    });
    confirmation.addEventListener('hidden.bs.modal', () => { pendingForm = null; pendingSubmitter = null; });
}

document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
        if (form.dataset.confirm && form.dataset.confirmed !== 'true') {
            if (confirmation) {
                event.preventDefault();
                pendingForm = form;
                pendingSubmitter = event.submitter;
                confirmation.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
                Modal.getOrCreateInstance(confirmation).show();
                return;
            }
            if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
        }
        form.dataset.submitting = 'true';
        form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => {
            if (button.disabled) return;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-2';
            spinner.setAttribute('aria-hidden', 'true');
            button.prepend(spinner);
        });
    });
});
window.addEventListener('pageshow', () => {
    root.classList.remove('is-navigating');
    document.querySelectorAll('form[data-submitting]').forEach(form => { delete form.dataset.submitting; delete form.dataset.confirmed; });
    document.querySelectorAll('button[aria-busy]').forEach(button => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.querySelector('.spinner-border')?.remove();
    });
});
document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0 || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
    const url = new URL(link.href, location.href);
    if (url.origin === location.origin && !link.getAttribute('href').startsWith('#') && !(url.pathname === location.pathname && url.hash)) root.classList.add('is-navigating');
});

if (!matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        const value = Number(entry.target.dataset.counter);
        const started = performance.now();
        function frame(now) {
            const fraction = Math.min(1, (now - started) / 650);
            entry.target.textContent = new Intl.NumberFormat('vi-VN').format(Math.round(value * (1 - (1 - fraction) ** 3)));
            if (fraction < 1) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    }), { threshold: .2 });
    document.querySelectorAll('[data-counter]').forEach(element => {
        if (element.dataset.counter !== '' && Number.isFinite(Number(element.dataset.counter))) observer.observe(element);
    });
}
