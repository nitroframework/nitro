import { nitroVue } from 'nitro/vue';

nitroVue(import.meta.glob(['../views/vue/**/*.vue', './vue/**/*.vue']));
