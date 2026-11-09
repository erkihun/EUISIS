import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

// A session ended by the server (idle timeout) answers any request with 401 +
// `redirect`; follow it to sign-in, where the reason is shown. Other 401/403/419
// responses are left to the caller — they are not "session expired".
window.axios.interceptors.response.use(undefined, (error) => {
    const data = error?.response?.data as { reason?: string; redirect?: string } | undefined;
    if (error?.response?.status === 401 && data?.reason === 'idle_timeout' && data.redirect) {
        window.location.assign(data.redirect);
    }
    return Promise.reject(error);
});

// Use the XSRF-TOKEN cookie (set by Laravel on every response) rather than
// the meta-tag token. The meta tag is only rendered on a full page load, so
// it goes stale after Inertia SPA navigations that regenerate the session.
// Axios reads XSRF-TOKEN automatically via xsrfCookieName / xsrfHeaderName.
