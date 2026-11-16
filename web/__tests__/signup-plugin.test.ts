/**
 * WHIT-1156: a sign-up link with a plugin (/register?plan=free&plugin=salons)
 * starts that plugin's trial once the owner is signed in, and opens its setup.
 */
import { continueSignupPlugin, pendingSignupPlugin, rememberSignupPlugin, signupPluginFrom } from '@/lib/signup-plugin';
import { api } from '@/lib/api/client';

jest.mock('@/lib/api/client', () => ({
  api: { GET: jest.fn(), POST: jest.fn() },
}));

const mockGet = api.GET as jest.Mock;
const mockPost = api.POST as jest.Mock;

const SALONS = { name: 'Salons', product: 'plugin.salons', enabled: false, trialAvailable: true, mayTurnOn: false, onboarding: { steps: [] } };

describe('signup plugin', () => {
  beforeEach(() => {
    window.localStorage.clear();
    mockGet.mockReset();
    mockPost.mockReset();
  });

  it('reads only a well-formed slug from the link', () => {
    expect(signupPluginFrom('?plan=free&plugin=salons')).toBe('salons');
    expect(signupPluginFrom('?plugin=Front-Office')).toBe('front-office');
    expect(signupPluginFrom('?plugin=../admin')).toBeNull();
    expect(signupPluginFrom('?plan=free')).toBeNull();
  });

  it('starts the trial and opens the setup, once', async () => {
    rememberSignupPlugin('salons');
    mockGet.mockResolvedValue({ data: { data: [SALONS] } });
    mockPost.mockResolvedValue({ error: undefined });

    await expect(continueSignupPlugin()).resolves.toBe('/admin/setup/Salons');
    expect(mockPost).toHaveBeenCalledWith('/api/v1/workspace/code-plugins/{plugin}/trial', { params: { path: { plugin: 'Salons' } } });
    expect(pendingSignupPlugin()).toBeNull();
    await expect(continueSignupPlugin()).resolves.toBeNull();
  });

  it('goes to the Plugins page when the trial is refused or the plugin is missing', async () => {
    rememberSignupPlugin('salons');
    mockGet.mockResolvedValue({ data: { data: [SALONS] } });
    mockPost.mockResolvedValue({ error: { error: 'Not available' } });
    await expect(continueSignupPlugin()).resolves.toBe('/admin/plugins');

    rememberSignupPlugin('gardens');
    await expect(continueSignupPlugin()).resolves.toBe('/admin/plugins');
  });

  it('does nothing without a plugin link', async () => {
    await expect(continueSignupPlugin()).resolves.toBeNull();
    expect(mockGet).not.toHaveBeenCalled();
  });
});
