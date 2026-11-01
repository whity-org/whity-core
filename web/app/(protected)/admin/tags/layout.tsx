import { SettingsArea } from '@/components/settings/settings-area';

/** Tags open inside the Settings area (WHIT-806, owner decision D1). */
export default function TagsLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
