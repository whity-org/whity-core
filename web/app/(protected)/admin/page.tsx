import { redirect } from 'next/navigation';

// One home (H.1): /admin lands on the dashboard. Server statistics are System, at /admin/stats.
export default function AdminDashboard() {
  redirect('/dashboard');
}
