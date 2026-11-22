'use client';

import { forwardRef, useEffect, useState, type ComponentType, type RefAttributes } from 'react';
import type { Icon, IconProps } from '@tabler/icons-react';
import { ICONS } from '@whity/ui/icon-map';

/**
 * An icon by NAME — a nav item's, a plugin block's, a search result's — without
 * shipping every Tabler icon to every page (WHIT-1245).
 *
 * The three places that turn a name into an icon used to `import * as` the
 * whole `@tabler/icons-react` set and look the name up at runtime. The bundler
 * cannot tell which icons a string will name, so it kept all 6,288 of them:
 * 2.9 MB (594 KB gzipped) in a chunk every signed-in page downloaded, for the
 * few dozen icons a screen actually shows.
 *
 * Now a name resolves in two steps:
 *  1. The curated set ({@see ICONS}, decision 5.1, ~320 icons the design system
 *     already ships): by Whity's own name (`comment`, `customer`) or by the
 *     Tabler name of an icon in that set (`users`, `IconUsers`). Synchronous,
 *     as before — every core nav icon is here.
 *  2. Any other Tabler name: a stable placeholder component that renders the
 *     caller's fallback, loads that ONE icon's module (a few hundred bytes) and
 *     re-renders with it. Each icon is its own lazy chunk, so a plugin can still
 *     name any Tabler icon; it costs a moment's fallback the first time.
 * An unknown name keeps the caller's fallback, as before.
 */

/** `building-community` / `building_community` / `IconBuildingCommunity` → `IconBuildingCommunity`. */
export function tablerComponentName(name: string): string {
  const pascal = name
    .trim()
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');
  return pascal.startsWith('Icon') ? pascal : `Icon${pascal}`;
}

const curatedByName = ICONS as Record<string, { icon: Icon } | undefined>;

/** Curated icons by their Tabler export name (Tabler sets `displayName` to the name without `Icon`). */
const curatedByComponent: Map<string, Icon> = (() => {
  const map = new Map<string, Icon>();
  for (const entry of Object.values(curatedByName)) {
    const icon = entry?.icon;
    if (icon?.displayName) map.set(`Icon${icon.displayName}`, icon);
  }
  return map;
})();

/** The curated icon a name means, or null when it is outside the curated set. */
export function curatedIcon(name: string): Icon | null {
  const trimmed = name.trim();
  return curatedByName[trimmed]?.icon ?? curatedByComponent.get(tablerComponentName(trimmed)) ?? null;
}

/** Only real export names reach the dynamic import: letters and digits after `Icon`. */
const COMPONENT_NAME = /^Icon[A-Za-z0-9]+$/;

/** Loaded icons by component name; `null` once a name is known not to exist. */
const loaded = new Map<string, Icon | null>();
const loading = new Map<string, Promise<Icon | null>>();

function loadTablerIcon(componentName: string): Promise<Icon | null> {
  const pending = loading.get(componentName);
  if (pending) return pending;
  const promise = import(`@tabler/icons-react/dist/esm/icons/${componentName}.mjs`)
    .then((mod: { default?: Icon }) => mod.default ?? null)
    .catch(() => null)
    .then((icon) => {
      loaded.set(componentName, icon);
      return icon;
    });
  loading.set(componentName, promise);
  return promise;
}

/** One placeholder component per (name, fallback), so a name keeps its component identity. */
const placeholders = new Map<string, Map<Icon, Icon>>();

function lazyIcon(componentName: string, fallback: Icon): Icon {
  let byFallback = placeholders.get(componentName);
  if (!byFallback) {
    byFallback = new Map();
    placeholders.set(componentName, byFallback);
  }
  const existing = byFallback.get(fallback);
  if (existing) return existing;

  const Lazy = forwardRef<SVGSVGElement, IconProps>(function LazyTablerIcon(props, ref) {
    const [icon, setIcon] = useState<Icon | null | undefined>(() => loaded.get(componentName));
    useEffect(() => {
      if (icon !== undefined) return;
      let live = true;
      void loadTablerIcon(componentName).then((found) => {
        if (live) setIcon(found);
      });
      return () => {
        live = false;
      };
    }, [icon]);
    // Tabler's components forward the ref to the <svg>; its `Icon` type just doesn't say so.
    const Shown = (icon ?? fallback) as unknown as ComponentType<IconProps & RefAttributes<SVGSVGElement>>;
    return <Shown ref={ref} {...props} />;
  });
  Lazy.displayName = componentName.slice(4);
  const component = Lazy as unknown as Icon;
  byFallback.set(fallback, component);
  return component;
}

/**
 * The icon a name means: curated (synchronously), any other Tabler icon (loaded
 * on first render), or `fallback` for an empty or unknown name.
 */
export function namedIcon(name: string | undefined | null, fallback: Icon): Icon {
  if (!name || name.trim() === '') return fallback;
  const curated = curatedIcon(name);
  if (curated) return curated;
  const componentName = tablerComponentName(name);
  if (!COMPONENT_NAME.test(componentName)) return fallback;
  const known = loaded.get(componentName);
  if (known) return known;
  if (known === null) return fallback;
  return lazyIcon(componentName, fallback);
}
