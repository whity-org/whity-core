/**
 * Workspace templates (WHIT-1195): where a new workspace's owner goes next.
 *
 * Only right after a workspace is created (sign-up, register, "Create
 * another workspace") is its owner offered the template picker: while the
 * workspace has no template (Blank counts as one) and is less than two weeks
 * old. Signing in never leads there, so existing and seeded workspaces land
 * on the dashboard; they can still start from a template (adopt mode) on
 * Settings › Plugins. Anyone who can't set the workspace up (no
 * `settings:write`) goes where they always did.
 */
import { api } from '@/lib/api/client';

export const TEMPLATE_PICKER_PATH = '/setup/template';

/** The picker when this workspace should be offered it, else null. */
export async function templatePickerPath(): Promise<string | null> {
  try {
    const { data, response } = await api.GET('/api/v1/workspace/templates');
    if (!response.ok || data?.data.offerPicker !== true) {
      return null;
    }

    return TEMPLATE_PICKER_PATH;
  } catch {
    return null;
  }
}

/** Where a template's guided setup is drawn: the plugins' setup stepper. */
export function templateSetupPath(key: string): string {
  return `/admin/setup/template-${encodeURIComponent(key)}`;
}
