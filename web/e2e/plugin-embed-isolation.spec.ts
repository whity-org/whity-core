import { test, expect } from './support/fixtures';

/**
 * WHIT-1217 — an embedded plugin screen cannot reach the app (finding 4 of
 * docs/design/VERIFIED-PLUGINS-SECURITY.md).
 *
 * The frame's sandbox used to allow both `allow-scripts` and
 * `allow-same-origin`, which together hand a same-origin page the app's own
 * origin. Only a real browser can prove the frame is now opaque, so this loads
 * a probe page into the real `EmbedScreen` and lets the probe try the three
 * things section 8 lists: reading the parent page, reading the app's cookies or
 * storage, and calling the API with the person's session. The probe reports
 * back by `postMessage`, the one channel an opaque frame keeps.
 *
 * The feature list is extended in the browser with one embed feature, and the
 * probe page is served by the test, because no first-party plugin declares an
 * embed screen. The API call the probe makes is NOT routed: it goes to the
 * real backend, so a pass means the real CORS and cookie rules refused it.
 */

const FEATURE_ID = 'embed-isolation-probe';
const PROBE_PATH = '/api/v1/embed-isolation-probe/page';

const PROBE_HTML = `<!doctype html>
<html><body><p>probe</p><script>
(async () => {
  const r = {};
  try { r.parent = typeof window.parent.document.title; } catch (e) { r.parent = 'blocked'; }
  try { r.cookie = document.cookie === '' ? 'empty' : 'readable'; } catch (e) { r.cookie = 'blocked'; }
  try { window.localStorage.length; r.storage = 'open'; } catch (e) { r.storage = 'blocked'; }
  try {
    const res = await fetch('/api/v1/auth/me', {
      credentials: 'include',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    r.api = String(res.status);
  } catch (e) { r.api = 'blocked'; }
  window.parent.postMessage({ whityEmbedProbe: r }, '*');
})();
</script></body></html>`;

test.describe('Plugin embed screen isolation (WHIT-1217)', () => {
  test('the framed page cannot read the parent, cookies or storage, or call the API as the person', async ({
    adminPage,
    page,
  }) => {
    void adminPage;

    await page.addInitScript(() => {
      window.addEventListener('message', (event) => {
        const data = event.data as { whityEmbedProbe?: Record<string, string> } | null;
        if (data !== null && typeof data === 'object' && data.whityEmbedProbe !== undefined) {
          (window as unknown as { __embedProbe: Record<string, string> }).__embedProbe = data.whityEmbedProbe;
        }
      });
    });

    await page.route('**/api/v1/frontend/features**', async (route) => {
      const response = await route.fetch();
      const body = (await response.json()) as { data: unknown[] };
      body.data.push({
        id: FEATURE_ID,
        plugin: 'EmbedProbe',
        label: 'Embed probe',
        icon: null,
        group: 'plugins',
        order: 999,
        screen: 'embed',
        resource: null,
        action: null,
        embed: { path: PROBE_PATH },
        requiredPermission: 'embed_probe:view',
        capabilities: {},
        capabilityReasons: {},
      });
      await route.fulfill({ response, json: body });
    });

    await page.route(`**${PROBE_PATH}`, (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: PROBE_HTML })
    );

    await page.goto(`/admin/x/${FEATURE_ID}`);

    const frame = page.locator('iframe[title="Embed probe"]');
    await expect(frame).toBeVisible();
    const sandbox = (await frame.getAttribute('sandbox')) ?? '';
    expect(sandbox.split(/\s+/)).not.toContain('allow-same-origin');
    expect(sandbox.split(/\s+/)).toContain('allow-scripts');

    await expect
      .poll(() => page.evaluate(() => (window as unknown as { __embedProbe?: Record<string, string> }).__embedProbe ?? null), {
        timeout: 20_000,
      })
      .not.toBeNull();
    const probe = await page.evaluate(
      () => (window as unknown as { __embedProbe: Record<string, string> }).__embedProbe
    );

    expect(probe.parent).toBe('blocked');
    // An opaque origin has no cookie jar or storage: reading either throws.
    expect(probe.cookie).toBe('blocked');
    expect(probe.storage).toBe('blocked');
    // A cross-origin call with a custom header needs a preflight the API's
    // allowlist refuses, so it never reaches the handler with a session.
    expect(probe.api).toBe('blocked');
  });
});
