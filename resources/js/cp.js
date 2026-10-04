import { registry } from './editor/registry.js';

Statamic.booting(() => {
    registry.listen();
});
