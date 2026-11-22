import { SettingsArea } from '@/components/settings/settings-area';

/** Company stamps (#1729) are a workspace setting, beside document themes. */
export default function DocumentStampsLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
