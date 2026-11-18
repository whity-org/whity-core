'use client';

/**
 * The flow editor's inspector for the two rule kinds that had none (#1034).
 *
 * - `explicit` names PEOPLE, one by one: `{ profile_ids: [...] }`. It is the one
 *   kind that names individuals and therefore the one that goes stale, so the
 *   control says so above the picker, where the choice is made. The picker is
 *   #1015's `AudiencePeoplePicker`, the same one the linear route composer uses.
 * - A kind a PLUGIN contributed has a config only that plugin understands. Until
 *   rule contributions declare a config schema, the host does not guess at a
 *   form: it says which plugin the kind comes from and shows what is saved, read
 *   only, and the save keeps it exactly as it was.
 */

import { useTranslation } from '@whity/features/i18n';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { AudiencePeoplePicker, type AudiencePersonOption } from '@whity/ui/audience-people-picker';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { fetchAllPagesTyped } from '@/lib/api/fetch-all-pages';
import { configuredProfileIds } from '@/components/documents/routing-wire';

type T = ReturnType<typeof useTranslation>;

interface PeopleCatalogue {
  items: AudiencePersonOption[];
  unavailable: string | null;
  incomplete: string | null;
}

/**
 * The people an `explicit` stage may name. Fetched here, when an explicit stage
 * is open in the inspector, rather than with the rest of the editor's lookups:
 * most designs never name a person, and listing everybody up front would be a
 * cost every one of them paid for nothing.
 *
 * A 403 is expected (designing a flow does not imply reading people) and fails
 * soft into a reason, never an empty search box.
 */
function usePeople(t: T): { data: PeopleCatalogue | null } {
  return useFetch<PeopleCatalogue>(async () => {
    const { error: probeError, response: probe } = await api.GET('/api/v1/users', {
      params: { query: { page: 1 } },
    });
    if (!probe.ok) {
      const body = (probeError ?? null) as { error?: string } | null;
      return {
        items: [],
        incomplete: null,
        unavailable:
          probe.status === 403
            ? t(
                'routeTemplates.inspector.peopleForbidden',
                'You cannot list people here, so this stage cannot name anyone. An administrator would need to give you permission to read people.'
              )
            : (body?.error ?? t('routeTemplates.inspector.peopleError', 'People could not be loaded.')),
      };
    }
    const all = await fetchAllPagesTyped<{ id: number; name: string; email?: string | null }>(
      (query) => api.GET('/api/v1/users', { params: { query } }) as never
    );
    return {
      items: all.items.map((person) => ({
        id: person.id,
        name: typeof person.name === 'string' && person.name !== '' ? person.name : String(person.id),
        secondary: person.email ?? null,
      })),
      unavailable: null,
      incomplete: all.complete
        ? null
        : t('routeTemplates.inspector.peoplePartial', 'Only some people could be loaded, so this list may be incomplete.'),
    };
  }, []);
}

export function ExplicitPeopleInspector({
  ruleConfig,
  readOnly,
  onChange,
}: {
  ruleConfig: Record<string, unknown>;
  readOnly: boolean;
  onChange: (ruleConfig: Record<string, unknown>) => void;
}) {
  const t = useTranslation('admin');
  const people = usePeople(t);

  return (
    <div className="space-y-1" data-slot="route-flow-explicit">
      <label className="text-sm font-medium" htmlFor="route-flow-people">
        {t('routeTemplates.inspector.people', 'People')}
      </label>
      <p className="text-xs text-muted-foreground">
        {t(
          'routeTemplates.inspector.peopleHelp',
          'This stage means exactly these people and nobody else. Someone who joins later is not added, and someone who leaves stays named until you change it. For a set that keeps up with the organisation, choose a role or a user group instead.'
        )}
      </p>
      {people.data === null ? (
        <p className="text-xs text-muted-foreground">{t('routeTemplates.loading', 'Loading…')}</p>
      ) : (
        <AudiencePeoplePicker
          id="route-flow-people"
          people={people.data.items}
          value={configuredProfileIds(ruleConfig)}
          onChange={(profileIds) => onChange(profileIds.length === 0 ? {} : { profile_ids: profileIds })}
          disabled={readOnly}
          unavailableReason={people.data.unavailable}
          incompleteReason={people.data.incomplete}
          searchPlaceholder={t('routeTemplates.inspector.peopleSearch', 'Search people by name')}
          emptyLabel={t('routeTemplates.inspector.peopleEmpty', 'There is nobody here to name.')}
          nothingSelectedLabel={t('routeTemplates.inspector.peopleNone', 'Nobody chosen yet.')}
          noMatchesLabel={t('routeTemplates.inspector.peopleNoMatches', 'Nobody matches that.')}
          moreMatchesLabel={(shown, total) =>
            t('routeTemplates.inspector.peopleMore', 'Showing {shown} of {total} matches. Keep typing to narrow it down.', {
              shown,
              total,
            })
          }
          removeLabel={(name) => t('routeTemplates.inspector.peopleRemove', 'Remove {name}', { name })}
          unknownPersonLabel={() => t('routeTemplates.inspector.peopleUnknown', 'Someone in this workspace')}
        />
      )}
    </div>
  );
}

/** One saved setting, written for reading: lists joined, objects as JSON. */
function settingText(value: unknown): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value) && value.every((v) => typeof v === 'string' || typeof v === 'number')) {
    return value.join(', ');
  }
  return JSON.stringify(value);
}

export function PluginRuleSummary({
  known,
  source,
  ruleConfig,
}: {
  /** Whether `GET /routing-rules` lists the kind at all. */
  known: boolean;
  /** The plugin slug that contributed the kind (`RoutingRule.source`), or null for core's own. */
  source: string | null;
  ruleConfig: Record<string, unknown>;
}) {
  const t = useTranslation('admin');
  const entries = Object.entries(ruleConfig);

  return (
    <div className="space-y-2" data-slot="route-flow-plugin-rule">
      <Alert>
        <AlertDescription className="text-xs">
          {!known
            ? t(
                'routeTemplates.inspector.unknownKind',
                'This rule is not one this workspace offers any more. Its settings are kept exactly as they were saved.'
              )
            : source !== null
              ? t(
                  'routeTemplates.inspector.pluginKind',
                  'This rule comes from the {plugin} plugin. Its settings can be changed only through that plugin for now. Saving keeps them exactly as they are.',
                  { plugin: source }
                )
              : t(
                  'routeTemplates.inspector.unsupportedKind',
                  'This rule kind has no editor here yet. Its configuration is kept exactly as it was saved.'
                )}
        </AlertDescription>
      </Alert>
      <p className="text-sm font-medium">{t('routeTemplates.inspector.savedSettings', 'Saved settings')}</p>
      {entries.length === 0 ? (
        <p className="text-xs text-muted-foreground">
          {t('routeTemplates.inspector.noSettings', 'None. This rule needs no settings, or none have been saved.')}
        </p>
      ) : (
        <dl className="space-y-1 text-xs" data-slot="route-flow-plugin-settings">
          {entries.map(([key, value]) => (
            <div key={key} className="flex gap-2">
              <dt className="shrink-0 text-muted-foreground">{key}</dt>
              <dd className="min-w-0 break-words" dir="auto">
                {settingText(value)}
              </dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  );
}
