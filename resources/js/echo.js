import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Lidhja realtime (Reverb) — task MHQ #52.
//
// SINGLETON DEMBEL, me qëllim: lidhja WebSocket hapet VETËM kur një faqe e
// kërkon (getEcho()), kurrë automatikisht — faqet publike të webit nuk i
// hapin vizitorët anonimë asnjë lidhje. wsHost = hosti aktual i hotelit
// (multi-tenant: çdo domain hoteli kalon te i njëjti Reverb pas nginx-it).
//
// Kur çelësi VITE_REVERB_APP_KEY mungon (server pa Reverb të ndezur),
// getEcho() kthen null — faqet bien vetiu te fallback-u i tyre me poll.

let instance = null;

export function getEcho() {
    if (instance) return instance;

    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) return null;

    window.Pusher = Pusher;

    instance = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return instance;
}

/** true kur lidhja WebSocket është realisht e hapur (për fallback-e poll). */
export function echoConnected() {
    return instance?.connector?.pusher?.connection?.state === 'connected';
}
