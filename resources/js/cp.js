import { PipelineStopped } from '@statamic/cms/save-pipeline';
import EditorBanner from './components/EditorBanner.vue';
import { BANNER, banners } from './editor/banner.js';
import { registry } from './editor/registry.js';
import { createSaveHooks } from './editor/saveHooks.js';

Statamic.booting(() => {
    Statamic.$components.register(BANNER, EditorBanner);

    registry.listen();
    banners.listen();
    createSaveHooks({ PipelineStopped }).listen();
});
