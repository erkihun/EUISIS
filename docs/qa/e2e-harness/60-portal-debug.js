'use strict';
const { newBrowser, login, visit } = require('./lib');
(async () => {
  const { browser, page } = await newBrowser();
  await login(page, 'qa.sysadmin4@demo.local', 'password', {});
  await visit(page, '/provider-users/create');
  const labels = await page.locator('label').evaluateAll(ls => ls.slice(0, 8).map(l => ({ html: l.outerHTML.slice(0, 300), text: l.innerText })));
  console.log(JSON.stringify(labels, null, 1));
  await browser.close();
})();
