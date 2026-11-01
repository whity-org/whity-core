import { SettingsArea } from '@/components/settings/settings-area';

/** Languages open inside the Settings area (WHIT-806, owner decision D1). */
export default function LanguagesLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
