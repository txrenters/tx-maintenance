import { configureEcho } from "@laravel/echo-vue";

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80, 
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') == 'https', 
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});


