/**
 * A sign-up link that comes with a plugin (WHIT-1156): for example
 * `/register?plan=free&plugin=salons` is the link shared with salons. The
 * workspace is created on the Free plan as usual; once its owner is signed
 * in, the plugin's free trial starts (or it is turned on, if it needs no
 * trial) and its guided setup opens.
 *
 * The slug is remembered between the register page and the first sign-in,
 * because email verification can put a day between them. Storage can fail
 * (private windows, blocked site data); then the link still creates the
 * workspace, and the plugin waits on Settings › Plugins.
 *
 * Nothing here can grant access the workspace may not have: the trial and
 * enable routes apply every rule the Plugins page applies.
 */
import { api } from '@/lib/api/client';

const STORAGE_KEY = 'whity.signupPlugin';

/** The query parameter a plugin sign-up link carries. */
export const SIGNUP_PLUGIN_PARAM = 'plugin';

/** A plugin slug as links write it: `salons`, `front-office`. */
const SLUG = /^[a-z][a-z0-9-]{0,39}$/;

/** The slug from a sign-up link, or null when there is none or it is malformed. */
export function signupPluginFrom(search: string): string | null {
  const value = new URLSearchParams(search).get(SIGNUP_PLUGIN_PARAM)?.trim().toLowerCase() ?? '';

  return SLUG.test(value) ? value : null;
}

export function rememberSignupPlugin(slug: string): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, slug);
  } catch {
    // Storage blocked: the plugin waits on Settings › Plugins instead.
  }
}

export function pendingSignupPlugin(): string | null {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY);

    return value !== null && SLUG.test(value) ? value : null;
  } catch {
    return null;
  }
}

function forgetSignupPlugin(): void {
  try {
    window.localStorage.removeItem(STORAGE_KEY);
  } catch {
    // Nothing to forget.
  }
}

/**
 * Right after the owner's first sign-in: start the remembered plugin's trial
 * (or turn it on) and say where to go next, its setup or the Plugins page.
 * Null when there was no plugin link, so the caller goes where it always did.
 * Forgotten whatever happens, so a later sign-in never tries again.
 */
export async function continueSignupPlugin(): Promise<string | null> {
  const slug = pendingSignupPlugin();
  if (slug === null) {
    return null;
  }
  forgetSignupPlugin();

  try {
    const { data } = await api.GET('/api/v1/workspace/code-plugins');
    const plugin = (data?.data ?? []).find(
      (p) => p.product === `plugin.${slug}` || p.name.toLowerCase() === slug.replace(/-/g, ''),
    );
    if (plugin === undefined) {
      return '/admin/plugins';
    }

    let on = plugin.enabled === true;
    if (!on && plugin.trialAvailable === true) {
      const { error } = await api.POST('/api/v1/workspace/code-plugins/{plugin}/trial', {
        params: { path: { plugin: plugin.name } },
      });
      on = error === undefined;
    } else if (!on && plugin.mayTurnOn === true) {
      const { error } = await api.POST('/api/v1/workspace/code-plugins/{plugin}/enable', {
        params: { path: { plugin: plugin.name } },
      });
      on = error === undefined;
    }

    return on && plugin.onboarding ? `/admin/setup/${encodeURIComponent(plugin.name)}` : '/admin/plugins';
  } catch {
    return '/admin/plugins';
  }
}
