export interface OU {
  id: number;
  tenant_id: number;
  parent_id: number | null;
  /**
   * On the units screens, the name in the reader's language (the page sets it
   * from `names`). From the API it is the original name, in `name_language`.
   */
  name: string;
  /** Language of the original name (#821). */
  name_language?: string;
  /** The name in every language it has, original included (#821). */
  names?: Record<string, string>;
  slug: string;
  description?: string;
  created_at: string;
  /** Active people directly in this unit. */
  member_count?: number;
  children?: { id: number }[];
}
