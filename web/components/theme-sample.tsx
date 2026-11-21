'use client';

import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Checkbox } from '@whity/ui/checkbox';
import { Input } from '@whity/ui/input';
import { Switch } from '@whity/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@whity/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import type { TranslateFn } from '@whity/features/i18n';

/**
 * What a screen looks like in a theme (TH.25): the real components, so every
 * shape family shows. Its values come from `previewThemeCss` with the same scope.
 */
export function ThemeSample({ mode, t, scope = '' }: { mode: 'light' | 'dark'; t: TranslateFn; scope?: string }) {
  const people = ['Amro Saleh', 'Awss Haddad', 'Ahmad Khalil'];
  return (
    <div
      data-theme-preview={`${scope}${mode}`}
      data-testid={`theme-editor-preview-${scope}${mode}`}
      // A picture of the theme, not part of the page: nothing in it can be used.
      inert
      className={`${mode === 'dark' ? 'dark ' : ''}grid gap-3 rounded-card border border-border bg-background p-3 font-sans text-foreground`}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="text-base font-semibold">Example Bakery</span>
        <Badge variant="success">{t('theme.editor.sample.paid', 'Paid')}</Badge>
      </div>
      <Tabs defaultValue="orders">
        <TabsList>
          <TabsTrigger value="orders">{t('theme.editor.sample.orders', 'Orders')}</TabsTrigger>
          <TabsTrigger value="customers">{t('theme.editor.sample.customers', 'Customers')}</TabsTrigger>
        </TabsList>
      </Tabs>
      <Card>
        <CardHeader>
          <CardTitle>{t('theme.editor.sample.order', 'New order')}</CardTitle>
          <CardDescription>{t('theme.editor.sample.orderHint', 'Fill in the order and save it.')}</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3">
          <Input label={t('theme.editor.sample.customer', 'Customer')} defaultValue="Example Bakery" />
          <div className="flex items-center gap-2 text-sm">
            <Checkbox defaultChecked aria-label={t('theme.editor.sample.deliver', 'Deliver')} />
            {t('theme.editor.sample.deliver', 'Deliver')}
            <Switch defaultChecked className="ms-auto" aria-label={t('theme.editor.sample.remind', 'Remind me')} />
          </div>
          <div className="flex flex-wrap gap-2">
            <Button>{t('theme.editor.sample.save', 'Save order')}</Button>
            <Button variant="outline">{t('theme.editor.sample.cancel', 'Cancel')}</Button>
          </div>
        </CardContent>
      </Card>
      <div data-slot="table-container" className="overflow-hidden rounded-card border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('theme.editor.sample.name', 'Name')}</TableHead>
              <TableHead>{t('theme.editor.sample.status', 'Status')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {people.map((person, i) => (
              <TableRow key={person}>
                <TableCell>{person}</TableCell>
                <TableCell>
                  <Badge variant={i === 0 ? 'default' : 'outline'}>
                    {i === 0 ? t('theme.editor.sample.new', 'New') : t('theme.editor.sample.done', 'Done')}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <div className="flex h-10 items-end gap-1.5" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((n) => (
          <span key={n} className="flex-1 rounded-sm" style={{ height: `${30 + n * 12}%`, background: `var(--chart-${n})` }} />
        ))}
      </div>
    </div>
  );
}
