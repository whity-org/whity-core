export interface OU {
  id: number;
  tenant_id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  description?: string;
  created_at: string;
  /** Active people directly in this unit. */
  member_count?: number;
  children?: { id: number }[];
}
