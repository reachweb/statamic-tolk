import EditorBanner from './components/EditorBanner.vue';
import { BANNER, banners } from './editor/banner.js';
import { registry } from './editor/registry.js';

Statamic.booting(() => {
    Statamic.$components.register(BANNER, EditorBanner);

    registry.listen();
    banners.listen();
});
