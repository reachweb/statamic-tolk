import { PipelineStopped } from '@statamic/cms/save-pipeline';
import EditorBanner from './components/EditorBanner.vue';
import { BANNER, banners } from './editor/banner.js';
import { fieldActions } from './editor/fieldActions.js';
import { offers } from './editor/offer.js';
import { registry } from './editor/registry.js';
import { createSaveHooks } from './editor/saveHooks.js';
import ModelSelect from './fieldtypes/ModelSelect.vue';
import ProvidersStatus from './fieldtypes/ProvidersStatus.vue';
import SiteProfile from './fieldtypes/SiteProfile.vue';
import Overview from './pages/Overview.vue';
import RunsIndex from './pages/Runs/Index.vue';
import RunsShow from './pages/Runs/Show.vue';

Statamic.booting(() => {
    Statamic.$components.register(BANNER, EditorBanner);
    Statamic.$components.register('tolk_model-fieldtype', ModelSelect);
    Statamic.$components.register('tolk_providers-fieldtype', ProvidersStatus);
    Statamic.$components.register('tolk_site_profile-fieldtype', SiteProfile);
    Statamic.$inertia.register('tolk::Overview', Overview);
    Statamic.$inertia.register('tolk::Runs/Index', RunsIndex);
    Statamic.$inertia.register('tolk::Runs/Show', RunsShow);

    registry.listen();
    banners.listen();
    offers.listen();
    createSaveHooks({ PipelineStopped }).listen();
    fieldActions.register();
});
