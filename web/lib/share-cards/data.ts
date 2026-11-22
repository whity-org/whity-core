import { headers } from 'next/headers';
import { backendUrl } from '@/lib/backend-url';
import { trustedForwardedHostFrom } from '@/lib/trusted-client-ip';
import type { PaperCard, WorkspaceCard } from './card';
import { isArabic, logoData } from './card';

/**
 * What each public page's link preview and title say (WHIT-1041), read from
 * the same public endpoints the pages use, on the server, for this request's
 * host (a workspace's own address resolves to that workspace). A page the
 * server won't show gets a plain card, never an error: a preview is a hint.
 */

interface PublicBrand {
  name?: string;
  logo_square_url?: string | null;
  logo_wide_url?: string | null;
  main_color?: string | null;
}

// Never the browser's own X-Forwarded-Host (WHIT-1295, #2195).
async function host(): Promise<string | null> {
  return trustedForwardedHostFrom(await headers());
}

function hostHeader(h: string | null): Record<string, string> {
  return h !== null ? { 'X-Forwarded-Host': h } : {};
}

async function get<T>(path: string): Promise<T | null> {
  try {
    const res = await fetch(`${backendUrl()}${path}`, {
      headers: { Accept: 'application/json', ...hostHeader(await host()) },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });
    if (!res.ok) return null;
    return ((await res.json()) as { data?: T }).data ?? null;
  } catch {
    return null;
  }
}

/** The address as people see it: the host and path, without the scheme. */
async function address(path: string): Promise<string> {
  return `${(await host()) ?? ''}${path}`;
}

/** The brand this host shows when the page itself names none. */
async function hostBrand(): Promise<PublicBrand | null> {
  const d = await get<{ siteName?: string; logoSquareUrl?: string | null; theme?: { light?: Record<string, string> } }>('/api/v1/branding');
  if (!d) return null;
  return { name: d.siteName, logo_square_url: d.logoSquareUrl ?? null };
}

function text(label: unknown, ar: boolean): string {
  if (typeof label === 'string') return label;
  if (label && typeof label === 'object') {
    const l = label as { en?: string; ar?: string };
    return (ar ? l.ar || l.en : l.en || l.ar) ?? '';
  }
  return '';
}

/**
 * The preview's own words. A crawler sends no language, so a card follows the
 * workspace's content: Arabic names and titles get the Arabic words.
 */
function words(ar: boolean) {
  return ar
    ? {
        book: 'احجز موعدًا',
        bookWith: (n: string) => `احجز موعدًا مع ${n}.`,
        form: 'نموذج',
        formHint: 'نموذج تملؤه دون حساب.',
        join: (n: string) => `انضم إلى ${n}`,
        askJoin: 'اطلب الانضمام. يوافق أحدهم هناك على الأشخاص الجدد.',
        joinEmail: 'انضم ببريدك الإلكتروني.',
        document: (r: string) => `المستند ${r}`,
        aDocument: 'مستند',
        check: 'تحقق من مستند',
        verifyHint: 'افتحه لتراه، أو امسح رمز QR للتحقق من أنه أصلي.',
        shared: 'مشارك عبر Whity · رابط خاص',
      }
    : {
        book: 'Book a time',
        bookWith: (n: string) => `Book a time with ${n}.`,
        form: 'A form',
        formHint: 'A form to fill in, no account needed.',
        join: (n: string) => `Join ${n}`,
        askJoin: 'Ask to join. Someone there approves new people.',
        joinEmail: 'Join with your email address.',
        document: (r: string) => `Document ${r}`,
        aDocument: 'A document',
        check: 'Check a document',
        verifyHint: 'Open to see it, or scan its QR code to check it’s genuine.',
        shared: 'Shared through Whity · private link',
      };
}

export interface PageShare {
  title: string;
  description: string;
  card: WorkspaceCard | PaperCard;
}

export async function bookingShare(workspace: string): Promise<PageShare> {
  const page = await get<{ workspace?: { name?: string }; brand?: PublicBrand | null; services?: { name?: unknown }[] }>(
    `/api/v1/public/booking/${encodeURIComponent(workspace)}`
  );
  const name = page?.brand?.name ?? page?.workspace?.name ?? workspace;
  const ar = isArabic(name);
  const w = words(ar);
  const services = (page?.services ?? []).map((s) => text(s.name, ar)).filter(Boolean);
  const subtitle = services.slice(0, 3).join(' · ');
  return {
    title: `${w.book} · ${name}`,
    description: subtitle || w.bookWith(name),
    card: {
      kind: 'workspace',
      workspace: name,
      logo: await logoData(page?.brand?.logo_square_url, backendUrl()),
      color: page?.brand?.main_color ?? null,
      title: w.book,
      subtitle: subtitle || null,
      address: await address(`/book/${workspace}`),
    },
  };
}

export async function formShare(slug: string): Promise<PageShare> {
  const [form, brand] = await Promise.all([
    get<{ name?: string; title?: string | null; description?: string | null }>(`/api/v1/public/forms/${encodeURIComponent(slug)}`),
    hostBrand(),
  ]);
  const name = brand?.name ?? '';
  const w = words(isArabic(`${form?.title ?? ''} ${form?.name ?? ''} ${name}`));
  const title = form?.title || form?.name || w.form;
  return {
    title: name ? `${title} · ${name}` : title,
    description: form?.description || w.formHint,
    card: {
      kind: 'workspace',
      workspace: name,
      logo: await logoData(brand?.logo_square_url, backendUrl()),
      color: null,
      title,
      subtitle: form?.description || null,
      address: await address(`/f/${slug}`),
    },
  };
}

export async function joinShare(workspace: string): Promise<PageShare> {
  const page = await get<{ workspace_name?: string; approval_required?: boolean }>(
    `/api/v1/public/signup/workspaces/${encodeURIComponent(workspace)}`
  );
  const name = page?.workspace_name ?? workspace;
  const brand = await hostBrand();
  const w = words(isArabic(name));
  const hint = page?.approval_required ? w.askJoin : w.joinEmail;
  return {
    title: w.join(name),
    description: hint,
    card: {
      kind: 'workspace',
      workspace: name,
      logo: await logoData(brand?.name === name ? brand?.logo_square_url : null, backendUrl()),
      color: null,
      title: w.join(name),
      subtitle: hint,
      address: await address(`/join/${workspace}`),
    },
  };
}

/**
 * A shared document: who issued it and its reference, never its content
 * (SiteShareCards). A link that doesn't check out says only that.
 */
export async function verifyShare(token: string): Promise<PageShare> {
  const v = await get<{ verified?: boolean; reference?: string | null; issuer?: string | null }>(
    `/api/v1/document-verifications/${encodeURIComponent(token)}`
  );
  const issuer = v?.issuer ?? null;
  const w = words(isArabic(issuer ?? ''));
  const title = v?.verified ? (v.reference ? w.document(v.reference) : w.aDocument) : w.check;
  const subtitle = w.verifyHint;
  return {
    title: issuer ? `${title} · ${issuer}` : title,
    description: subtitle,
    card: {
      kind: 'paper',
      issuer,
      title,
      subtitle,
      tag: w.shared,
    },
  };
}

export async function signInShare(): Promise<PageShare> {
  const brand = await hostBrand();
  const name = brand?.name && brand.name !== 'Whity' ? brand.name : null;
  return {
    title: name ? `Sign in · ${name}` : 'Sign in to Whity',
    description: name ? `Sign in to ${name}.` : 'Documents, forms, approvals and your team, in one place.',
    card: {
      kind: 'paper',
      issuer: name,
      logo: await logoData(brand?.logo_square_url, backendUrl()),
      title: name ? `Sign in to ${name}` : 'Sign in to Whity',
      subtitle: name ? null : 'Documents, forms, approvals and your team, in one place.',
      tag: await address('/login'),
    },
  };
}
