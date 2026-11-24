'use strict';

/**
 * READINESS CO-25, TM-21: a page being printed may load `data:` and `about:`
 * and nothing else, so content that escaped the HTML builder can't reach the
 * internal network, a metadata address or the internet.
 */

const { lockDownPage } = require('../src/renderer');

function fakePage() {
  const page = { intercepting: false, handler: null };
  page.setRequestInterception = async (on) => {
    page.intercepting = on;
  };
  page.on = (event, handler) => {
    if (event === 'request') page.handler = handler;
  };
  return page;
}

function request(url) {
  const r = { outcome: null };
  r.url = () => url;
  r.continue = () => {
    r.outcome = 'continue';
  };
  r.abort = (reason) => {
    r.outcome = `abort:${reason}`;
  };
  return r;
}

test('only data: and about: load; every network request is refused', async () => {
  const page = fakePage();
  await lockDownPage(page);
  expect(page.intercepting).toBe(true);

  const cases = {
    'data:image/png;base64,iVBORw0KGgo=': 'continue',
    'about:blank': 'continue',
    'DATA:font/woff2;base64,AAAA': 'continue',
    'http://169.254.169.254/latest/meta-data/': 'abort:blockedbyclient',
    'http://app:8100/api/users': 'abort:blockedbyclient',
    'https://example.com/x.png': 'abort:blockedbyclient',
    'file:///etc/passwd': 'abort:blockedbyclient',
    'ws://render:8130/': 'abort:blockedbyclient',
  };
  for (const [url, expected] of Object.entries(cases)) {
    const r = request(url);
    page.handler(r);
    expect([url, r.outcome]).toEqual([url, expected]);
  }
});
