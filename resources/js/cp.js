import { PipelineStopped } from '@statamic/cms/save-pipeline';
import EditorBanner from './components/EditorBanner.vue';
import { BANNER, banners } from './editor/banner.js';
import { offers } from './editor/offer.js';
import { registry } from './editor/registry.js';
import { createSaveHooks } from './editor/saveHooks.js';

Statamic.booting(() => {
    Statamic.$components.register(BANNER, EditorBanner);

    registry.listen();
    banners.listen();
    offers.listen();
    createSaveHooks({ PipelineStopped }).listen();
});
