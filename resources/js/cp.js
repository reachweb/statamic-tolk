import EditorBanner from './components/EditorBanner.vue';
import { banners } from './editor/banner.js';
import { registry } from './editor/registry.js';

Statamic.booting(() => {
    Statamic.$components.register('tolk-banner', EditorBanner);

    registry.listen();
    banners.listen();
});
