'use strict';
const { BASE, newBrowser } = require('./lib');
const { loadState, sql, providerLogin, bodyText } = require('./60-portal-lib');
(async () => {
  const st = loadState(); const o = st.transportOperator;
  console.log('operator row:', sql(`select username,status,portal_enabled,must_change_password,suspended_at is not null from provider_users where username='${o.username}'`), 'temp len', (o.temp||'').length, JSON.stringify(o.temp));
  const { browser, page } = await newBrowser();
  const u = await providerLogin(page, BASE, o.username, o.temp);
  console.log('url', u); console.log((await bodyText(page)).slice(0, 400));
  await browser.close();
})();
