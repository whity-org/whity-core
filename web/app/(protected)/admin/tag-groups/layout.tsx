import { SettingsArea } from '@/components/settings/settings-area';

/** Tag groups open inside the Settings area (WHIT-806, owner decision D1). */
export default function TagGroupsLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
