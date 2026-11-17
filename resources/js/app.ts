import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { getBrowserWebSocketOptions } from '@/lib/browser-websocket-options';

configureEcho(
    import.meta.env.VITE_REVERB_APP_KEY
        ? {
              broadcaster: 'reverb',
              ...getBrowserWebSocketOptions(window.location),
          }
        : { broadcaster: 'null' },
);

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
});
