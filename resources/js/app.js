import './workspace';
import './data-table';
import './bootstrap';
import * as bootstrap from 'bootstrap';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.bootstrap = bootstrap;
window.Pusher = Pusher;

if (import.meta.env.VITE_PUSHER_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
        wsHost: import.meta.env.VITE_PUSHER_HOST || undefined,
        wsPort: Number(import.meta.env.VITE_PUSHER_PORT || 80),
        wssPort: Number(import.meta.env.VITE_PUSHER_PORT || 443),
        forceTLS: (import.meta.env.VITE_PUSHER_SCHEME || 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

document.querySelectorAll('[data-theme-toggle]').forEach(button => {
    button.addEventListener('click', () => {
        const theme = document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.bsTheme = theme;
        try { localStorage.setItem('zalomind.theme', theme); } catch {}
        document.querySelectorAll('[data-logo-light]').forEach(logo => {
            logo.src = theme === 'dark' ? logo.dataset.logoDark : logo.dataset.logoLight;
        });
        document.querySelectorAll('[data-theme-toggle]').forEach(toggle => toggle.setAttribute('aria-pressed', String(theme === 'dark')));
    });
});
document.querySelectorAll('[data-logo-light]').forEach(logo => {
    logo.src = document.documentElement.dataset.bsTheme === 'dark' ? logo.dataset.logoDark : logo.dataset.logoLight;
});
document.querySelectorAll('[data-theme-toggle]').forEach(toggle => toggle.setAttribute('aria-pressed', String(document.documentElement.dataset.bsTheme === 'dark')));
