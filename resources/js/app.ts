import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';

configureEcho(
    import.meta.env.VITE_REVERB_APP_KEY
        ? { broadcaster: 'reverb' }
        : { broadcaster: 'null' },
);

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
});
