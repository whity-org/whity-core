'use client';

/**
 * Every setting findable by name (WHIT-1084; navigation proposal approved
 * 15 Nov 2026). Ctrl+K and the phone's search (the same palette) and the
 * phone settings menu's own search all read this one index, so "إشعارات",
 * "notifications", "push" and "alerts" all find the same page, whichever
 * language the screen is in.
 *
 * It is built from the Settings area's own list (`useSettingsGroups`), so it
 * only ever offers what this person may open, plus the main things on each
 * page (Text size, Two-step sign-in, Logo...), each pointing at its page.
 *
 * Keywords are written in both languages on purpose: people type the word
 * they know, not the word on the screen. Matching folds Arabic letter forms
 * and digits (`fold`, as record search does).
 */

import { useMemo } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { fold } from '@/lib/commands/parse';
import type { SettingScope } from '@/lib/settings/my-settings';
import { isPersonalGroup, type SettingsAreaGroup } from '@/components/settings/settings-area';

export interface SettingEntry {
  /** The page's link id, or `<page>.<item>` for something on it. */
  id: string;
  /** The page this entry opens (for an item, its page's id). */
  pageId: string;
  label: string;
  /** Where it sits: "Settings › My settings", "Settings › Workspace". */
  place: string;
  href: string;
  scope: SettingScope;
  /** Folded label, place and keywords in both languages, for matching. */
  haystack: string;
}

/** Words people type for each page, in English and Arabic, beside its name. */
export const SETTING_KEYWORDS: Readonly<Record<string, string>> = {
  profile: 'profile name email picture photo account الملف الشخصي اسم بريد صورة حساب',
  password: 'password two-step 2fa two-factor passkey security sign in كلمة المرور المصادقة الثنائية أمان تسجيل الدخول',
  display:
    'language arabic english theme dark light text size font bigger animations motion اللغة العربية الإنجليزية الوضع الداكن الفاتح حجم النص الخط الحركة',
  notifications: 'notifications push alerts email phone إشعارات الإشعارات تنبيهات بريد هاتف',
  'signed-in': 'sessions devices sign out log out signed in الجلسات الأجهزة تسجيل الخروج',
  role: 'role permissions access what can i do دور صلاحيات وصول',
  general: 'general workspace name address company contact currency dollars dinars عام مساحة العمل اسم عنوان شركة عملة العملة',
  branding: 'branding logo colour color brand icon الهوية البصرية الشعار اللون العلامة',
  'document-themes': 'document themes letterhead print printout stamp أنساق المستندات الترويسة طباعة ختم',
  billing: 'billing plan subscription invoices payment upgrade الباقة الفوترة الاشتراك فواتير دفع ترقية',
  plugins: 'plugins apps store extensions add-ons الإضافات المتجر تطبيقات',
  owners: 'owners owner co-owner hand over transfer ownership shareholders المالكون المالك شريك نقل الملكية تسليم',
  security: 'security password rules policy two-step required الأمان سياسة كلمة المرور إلزامي',
  sso: 'single sign-on sso google microsoft oidc saml الدخول الموحد',
  signup: 'sign-up sign up register registration join التسجيل تسجيل انضمام',
  'customer-signup': 'sign-up sign up customers register join invite التسجيل العملاء انضمام دعوة',
  devices: 'licensed devices licence license الأجهزة المرخصة ترخيص',
  'audit-logs': 'audit log history who changed activity سجل التدقيق السجل من غير',
  messages: 'messages chat web chat replies الرسائل المحادثة ردود',
  email: 'email smtp mail server البريد الإلكتروني خادم',
  'email-domains': 'email domains dns spf dkim sender نطاقات البريد المرسل',
  'notification-log': 'notification log sent delivery سجل الإشعارات المرسلة تسليم',
  fields: 'fields custom fields الحقول المخصصة',
  tags: 'tags labels tag groups الوسوم التصنيفات',
  languages: 'languages اللغات',
  translations: 'translations wording words الترجمات الصياغة',
  'public-forms': 'public forms website embed keys النماذج العامة الموقع تضمين',
  bookings: 'bookings booking rules reminders calendar الحجوزات الحجز تذكير التقويم',
  storage: 'storage files uploads التخزين الملفات',
  'feature-flags': 'feature flags switches ميزات التشغيل',
  'error-tracking': 'error tracking sentry errors تتبع الأخطاء',
};

interface ItemSpec {
  pageId: string;
  id: string;
  label: string;
  keywords: string;
  scope?: SettingScope;
}

function score(entry: SettingEntry, needle: string, label: string): number {
  const name = fold(label);
  if (name === needle) return 4;
  if (name.startsWith(needle)) return 3;
  if (name.includes(needle)) return 2;
  return entry.haystack.includes(needle) ? 1 : 0;
}

/** The entries matching what was typed, best first. Empty text matches nothing. */
export function searchSettings(entries: readonly SettingEntry[], text: string, limit = 8): SettingEntry[] {
  const needle = fold(text);
  if (needle === '') return [];
  return entries
    .map((entry, index) => ({ entry, index, score: score(entry, needle, entry.label) }))
    .filter((hit) => hit.score > 0)
    .sort((a, b) => b.score - a.score || a.index - b.index)
    .slice(0, limit)
    .map((hit) => hit.entry);
}

export function useSettingsIndex(groups: readonly SettingsAreaGroup[]): SettingEntry[] {
  const t = useTranslation('admin');
  const ta = useTranslation('auth');

  return useMemo(() => {
    const settings = t('settingsArea.title', 'Settings');
    // The main things on a page, so "text size" finds Language and display.
    const items: ItemSpec[] = [
      { pageId: 'display', id: 'language', label: ta('settings.language.title', 'Language'), keywords: 'language arabic english اللغة العربية الإنجليزية' },
      { pageId: 'display', id: 'theme', label: ta('settings.display.theme', 'Light or dark'), keywords: 'dark light theme mode الوضع الداكن الفاتح', scope: 'device' },
      { pageId: 'display', id: 'text-size', label: ta('settings.display.textSize', 'Text size'), keywords: 'text size font bigger larger حجم النص الخط أكبر' },
      { pageId: 'display', id: 'animations', label: ta('settings.display.animations', 'Animations'), keywords: 'animations motion الحركة' },
      { pageId: 'display', id: 'technical', label: ta('settings.display.technical', 'Show technical details'), keywords: 'technical details ids codes التفاصيل التقنية' },
      { pageId: 'notifications', id: 'push', label: ta('settings.index.push', 'Push notifications on this device'), keywords: 'push phone device إشعارات الهاتف الجهاز', scope: 'device' },
      { pageId: 'profile', id: 'emails', label: ta('settings.emails.title', 'Email addresses'), keywords: 'email addresses البريد الإلكتروني عناوين' },
      { pageId: 'password', id: 'two-step', label: ta('settings.index.twoStep', 'Two-step sign-in'), keywords: 'two-step 2fa two-factor code المصادقة الثنائية رمز' },
      { pageId: 'password', id: 'change-password', label: ta('settings.index.changePassword', 'Change your password'), keywords: 'password change كلمة المرور تغيير' },
      { pageId: 'signed-in', id: 'sign-out-others', label: ta('settings.index.signOutOthers', 'Sign out of other devices'), keywords: 'sign out log out other devices sessions تسجيل الخروج الأجهزة الأخرى' },
      { pageId: 'branding', id: 'logo', label: t('settingsIndex.logo', 'Logo'), keywords: 'logo icon الشعار' },
      { pageId: 'branding', id: 'colour', label: t('settingsIndex.brandColour', 'Brand colour'), keywords: 'colour color brand اللون العلامة' },
      { pageId: 'general', id: 'workspace-name', label: t('settingsIndex.workspaceName', 'Workspace name'), keywords: 'workspace name company اسم مساحة العمل الشركة' },
    ];

    const entries: SettingEntry[] = [];
    const pages = new Map<string, SettingEntry>();
    for (const group of groups) {
      const personal = isPersonalGroup(group.id);
      for (const link of group.links) {
        const scope: SettingScope = personal ? (group.id === 'mine' ? 'account' : 'workspace') : 'workspace';
        const place = `${settings} › ${group.label}`;
        const entry: SettingEntry = {
          id: link.id,
          pageId: link.id,
          label: link.label,
          place,
          href: link.href,
          scope,
          haystack: fold(`${link.label} ${place} ${SETTING_KEYWORDS[link.id] ?? ''}`),
        };
        entries.push(entry);
        pages.set(link.id, entry);
      }
    }
    for (const item of items) {
      const page = pages.get(item.pageId);
      if (!page) continue;
      entries.push({
        id: `${item.pageId}.${item.id}`,
        pageId: item.pageId,
        label: item.label,
        place: `${page.place} › ${page.label}`,
        href: page.href,
        scope: item.scope ?? page.scope,
        haystack: fold(`${item.label} ${page.label} ${item.keywords}`),
      });
    }
    return entries;
  }, [groups, t, ta]);
}
