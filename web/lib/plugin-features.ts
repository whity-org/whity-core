/**
 * Plugin feature descriptors (WC-169).
 *
 * The backend exposes `GET /api/frontend/features` — an authenticated,
 * server-side permission-filtered list of UI features contributed by installed
 * plugins. The caller only ever receives features it is allowed to use, so the
 * frontend never re-implements the permission check; it just renders what it
 * is given.
 */

import { api } from '@/lib/api/client';
import type { DroppedPluginFeature, PluginFeature, PluginFeatureList } from '@whity/plugin-sdk';

/*
 * The block and feature types live in @whity/plugin-sdk (SDK 1.165, decision
 * D8), which checks them against the contract generated from the PHP SDK. They
 * are re-exported here so the app keeps importing them from this module.
 */
export type * from '@whity/plugin-sdk';
export { FLOW_MAX_NODES, OU_SCOPE_KINDS, isOuScopeValue } from '@whity/plugin-sdk';

/** Narrow an unknown payload to the `{ data: PluginFeature[] }` envelope. */
function isFeatureListResponse(body: unknown): body is { data: PluginFeature[] } {
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    return false;
  }
  return Array.isArray((body as { data: unknown }).data);
}

/**
 * Read the optional `dropped` array off the envelope (issue #953).
 *
 * Defensive per entry rather than all-or-nothing: this is diagnostic data, and
 * one malformed row must not cost the administrator the other reasons.
 */
function readDropped(body: unknown): DroppedPluginFeature[] {
  if (typeof body !== 'object' || body === null || !('dropped' in body)) {
    return [];
  }
  const raw = (body as { dropped: unknown }).dropped;
  if (!Array.isArray(raw)) {
    return [];
  }

  const dropped: DroppedPluginFeature[] = [];
  for (const entry of raw) {
    if (typeof entry !== 'object' || entry === null) {
      continue;
    }
    const record = entry as Record<string, unknown>;
    if (typeof record.plugin !== 'string' || typeof record.reason !== 'string') {
      continue;
    }
    dropped.push({
      plugin: record.plugin,
      featureId: typeof record.featureId === 'string' ? record.featureId : null,
      reason: record.reason,
    });
  }
  return dropped;
}

/** The all-failures fallback, so every failure branch returns the same shape. */
const EMPTY_FEATURE_LIST: PluginFeatureList = { features: [], dropped: [] };

/**
 * Fetch the permission-filtered feature list for the current user, plus the
 * descriptors the host refused (issue #953, empty unless the caller holds
 * `plugins:read`).
 *
 * Resolves to an empty list on any failure (non-ok status, malformed body,
 * network error) — callers render "no plugin features" rather than crash.
 */
export async function fetchPluginFeatures(): Promise<PluginFeatureList> {
  // Bounded for the same reason as NavigationProvider's fetch (see
  // navigation-context.tsx): this provider also wraps the whole
  // authenticated app, so an unbounded hang here blocks every admin page. A
  // plain setTimeout+abort rather than AbortSignal.timeout(), which is
  // unsupported in the jsdom test environment this is exercised under.
  const controller = new AbortController();
  const hangGuard = setTimeout(() => controller.abort(), 15_000);
  try {
    const { data: body, response } = await api.GET('/api/v1/frontend/features', {
      signal: controller.signal,
    });
    if (!response.ok) {
      return EMPTY_FEATURE_LIST;
    }
    if (!isFeatureListResponse(body)) {
      return EMPTY_FEATURE_LIST;
    }
    return { features: body.data, dropped: readDropped(body) };
  } catch {
    return EMPTY_FEATURE_LIST;
  } finally {
    clearTimeout(hangGuard);
  }
}
