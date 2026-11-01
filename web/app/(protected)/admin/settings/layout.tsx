import { SettingsArea } from '@/components/settings/settings-area';

/** The workspace settings pages open inside the Settings area (WHIT-806, owner decision D1). */
export default function SettingsLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
