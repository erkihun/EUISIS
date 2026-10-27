import { PageProps as InertiaPageProps } from '@inertiajs/core';
import { AxiosInstance } from 'axios';
import { route as ziggyRoute, type Config as ZiggyConfig } from 'ziggy-js';
import { PageProps as AppPageProps } from './';

declare global {
    interface Window {
        axios: AxiosInstance;
    }

    /* eslint-disable no-var */
    var route: typeof ziggyRoute;
    /** Route list and origin that route() reads (set once in app.tsx). */
    var Ziggy: ZiggyConfig;
}

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps, AppPageProps {}
}
