import { SettingsArea } from '@/components/settings/settings-area';

/** Translations open inside the Settings area (WHIT-806, owner decision D1). */
export default function TranslationsLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
