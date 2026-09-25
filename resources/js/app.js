import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// Browser title uses the client's brand ("Leads · ABC Realty | CRM"), never the platform name.
// Pages rendered outside the web middleware (e.g. 404 for unknown URLs) carry no shared props;
// the server-rendered <title> ("ABC Realty | CRM") still has the client's brand.
const serverBrand = document.title.replace(/\s*\|\s*CRM$/, '').trim() || 'Sales';
const brandOf = (props) => props?.app?.company || props?.app?.name || serverBrand;
let brand = (() => {
    try {
        return brandOf(JSON.parse(document.getElementById('app')?.dataset.page || '{}').props);
    } catch {
        return serverBrand;
    }
})();
router.on('navigate', (event) => (brand = brandOf(event.detail.page.props)));

createInertiaApp({
    title: (title) => (title ? `${title} · ${brand} | CRM` : `${brand} | CRM`),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#7C74FF',
    },
});

// Keep the tab icon in sync after an admin changes the favicon (URL carries a content version).
const syncFavicon = (event) => {
    const url = event.detail.page.props.app?.favicon_url;
    const link = document.getElementById('crm-favicon');
    if (url && link && link.getAttribute('href') !== url) link.setAttribute('href', url);
};
router.on('navigate', syncFavicon);
router.on('success', syncFavicon);
