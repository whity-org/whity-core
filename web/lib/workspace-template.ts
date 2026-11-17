/**
 * Workspace templates (WHIT-1195): where a new workspace's owner goes next.
 *
 * Right after a workspace is created, or after the first sign-in into one,
 * its owner is offered the template picker, once: while the workspace has
 * no template (Blank counts as one) and is less than two weeks old. Anyone
 * who can't set the workspace up (no `settings:write`), and every workspace
 * that has been running for longer, goes where it always did.
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
