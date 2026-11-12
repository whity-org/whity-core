import { ReactNode } from 'react';

interface AdminHeaderProps {
  title: string;
  description?: string;
  action?: ReactNode;
  breadcrumb?: ReactNode;
}

/**
 * The header of a list or settings page. On a phone the actions move under
 * the title and wrap, so neither the description is squeezed into a narrow
 * column nor the buttons pushed off the screen; from 640px they sit at the end.
 */
export function AdminHeader({ title, description, action, breadcrumb }: AdminHeaderProps) {
  return (
    <div className="mb-6 border-b border-border pb-5 sm:mb-8 sm:pb-6">
      {breadcrumb && (
        <div className="mb-2 text-sm text-muted-foreground">{breadcrumb}</div>
      )}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-semibold break-words text-foreground sm:text-3xl">
            {title}
          </h1>
          {description && (
            <p className="mt-2 text-sm text-muted-foreground">
              {description}
            </p>
          )}
        </div>
        {action && <div className="flex min-w-0 flex-wrap items-center gap-2 sm:ms-6 sm:shrink-0">{action}</div>}
      </div>
    </div>
  );
}
