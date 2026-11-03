import { SettingsArea } from '@/components/settings/settings-area';

/** Licensed devices sit in the Settings area's Devices group (W.1). */
export default function LicensingLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
