import { redirect } from 'next/navigation';
import { getBranding } from '@/lib/branding';
import { safeHomePath } from '@/lib/home-path';

// One home (H.1): /admin lands on the instance's home page, the dashboard unless
// the operator chose another (#2386). Server statistics are System, at /admin/stats.
export default async function AdminDashboard() {
  redirect(safeHomePath((await getBranding()).homePath));
}
