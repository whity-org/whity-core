'use client';

import { useState } from 'react';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@whity/features/i18n';
import { useRecordResource } from '@whity/features/record';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity/ui/select';

/**
 * ADD-ON PACKS a workspace holds beside its plan (WHIT-932).
 *
 * Each pack's numbers are added to the plan's: two packs of "10 extra staff" on
 * a 25-staff plan is 45 staff. Setting a count to 0 removes the pack. Lowering
 * it removes nobody, because limits only warn.
 *
 * Operator-only, like applying a plan: the server answers 403 to anyone else and
 * the panel is then absent rather than explaining a capability nobody gave them.
 */

interface HeldPack {
  plan_id: number;
  plan_key: string;
  name: string;
  quantity: number;
}

interface PlanRow {
  id: number;
  name: string;
  is_addon?: boolean;
  is_active: boolean;
}

interface Loaded {
  held: HeldPack[];
  packs: PlanRow[];
}

export function AddonPacksPanel({ tenantId }: { tenantId: number }) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const [version, setVersion] = useState(0);
  const [adding, setAdding] = useState<string>('');
  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const [saving, setSaving] = useState(false);

  const resource = useRecordResource<Loaded>(
    async () => {
      const [heldRes, plansRes] = await Promise.all([
        apiClient(`/api/v1/tenants/${tenantId}/addons`),
        apiClient('/api/v1/plans'),
      ]);
      if (heldRes.status === 403 || plansRes.status === 403) return 'forbidden';
      if (!heldRes.ok || !plansRes.ok) {
        throw new Error(t('tenants.record.addons.error', "Failed to load this workspace's add-on packs"));
      }
      const held = ((await heldRes.json()).data ?? []) as HeldPack[];
      const plans = ((await plansRes.json()).data ?? []) as PlanRow[];
      return { held, packs: plans.filter((plan) => plan.is_addon === true) };
    },
    [tenantId, version],
    t('tenants.record.addons.error', "Failed to load this workspace's add-on packs")
  );

  if (resource.status === 'forbidden') {
    return null;
  }

  const save = async (planId: number, quantity: number) => {
    setSaving(true);
    try {
      const res = await apiClient(`/api/v1/tenants/${tenantId}/addons`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ plan_id: planId, quantity }),
      });
      if (!res.ok) {
        addToast(t('tenants.record.addons.saveError', 'The add-on packs were not changed'), 'error');
        return;
      }
      addToast(
        quantity === 0
          ? t('tenants.record.addons.removed', 'Add-on pack removed')
          : t('tenants.record.addons.saved', 'Add-on packs saved'),
        'success'
      );
      setAdding('');
      setQuantities({});
      setVersion((v) => v + 1);
    } finally {
      setSaving(false);
    }
  };

  const heldIds = resource.status === 'ready' ? new Set(resource.value.held.map((p) => p.plan_id)) : new Set<number>();
  const offer = resource.status === 'ready' ? resource.value.packs.filter((p) => !heldIds.has(p.id)) : [];

  return (
    <Card data-testid="tenant-record-addons">
      <CardHeader>
        <CardTitle>{t('tenants.record.addons.title', 'Add-on packs')}</CardTitle>
        <CardDescription>
          {t(
            'tenants.record.addons.subtitle',
            'Packs held beside the plan. Each pack adds its limits to the plan’s.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {resource.status === 'error' ? (
          <p className="text-sm text-destructive">{resource.message}</p>
        ) : resource.status === 'loading' ? null : (
          <>
            {resource.value.held.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {t('tenants.record.addons.empty', 'This workspace holds no add-on packs.')}
              </p>
            ) : (
              <ul className="divide-y divide-border/60">
                {resource.value.held.map((pack) => {
                  const draft = quantities[pack.plan_id] ?? String(pack.quantity);
                  const parsed = Number.parseInt(draft, 10);
                  const valid = /^\d+$/.test(draft) && parsed !== pack.quantity;
                  return (
                    <li key={pack.plan_id} className="flex flex-wrap items-center gap-2 py-2">
                      <span className="flex-1 text-sm font-medium">{pack.name}</span>
                      <Input
                        type="number"
                        min={0}
                        dir="ltr"
                        className="w-20"
                        aria-label={t('tenants.record.addons.quantity', 'How many packs')}
                        value={draft}
                        onChange={(e) => setQuantities((q) => ({ ...q, [pack.plan_id]: e.target.value }))}
                      />
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={saving || !valid}
                        onClick={() => void save(pack.plan_id, parsed)}
                      >
                        {t('tenants.record.addons.update', 'Save')}
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={saving}
                        onClick={() => void save(pack.plan_id, 0)}
                      >
                        {t('tenants.record.addons.remove', 'Remove')}
                      </Button>
                    </li>
                  );
                })}
              </ul>
            )}

            {offer.length > 0 && (
              <div className="flex flex-wrap items-center gap-2">
                <Select value={adding} onValueChange={setAdding}>
                  <SelectTrigger className="min-w-48 flex-1" data-testid="tenant-addon-pick">
                    <SelectValue placeholder={t('tenants.record.addons.pick', 'Choose a pack')} />
                  </SelectTrigger>
                  <SelectContent>
                    {offer.map((pack) => (
                      <SelectItem key={pack.id} value={String(pack.id)}>
                        {pack.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Button
                  variant="outline"
                  disabled={saving || adding === ''}
                  onClick={() => void save(Number(adding), 1)}
                >
                  {t('tenants.record.addons.add', 'Add pack')}
                </Button>
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}
