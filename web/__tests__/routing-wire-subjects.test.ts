/**
 * WHIT-920 — an inbox item on a route whose subject has no document (a form
 * submission) still reads as a routing item, and opens the subject's review
 * page rather than a document that does not exist.
 */

import { routingHref, routingMeta, type InboxItem } from '@/components/documents/routing-wire';

function item(meta: Record<string, unknown>): InboxItem {
  return {
    id: '1',
    title: 'Supplier request',
    subtitle: '',
    timestamp: null,
    status: null,
    resource_type: null,
    resource_id: null,
    meta,
  };
}

const base = { route_id: 7, step_id: 3, open: true, arrived_by: 'issued' };

describe('routing items on subjects', () => {
  it('reads an item on a form submission, which has no document', () => {
    const meta = routingMeta(item({ ...base, document_id: null, subject_type: 'form_submission', subject_id: 42 }));
    expect(meta).not.toBeNull();
    expect(meta?.document_id).toBeNull();
    expect(meta?.subject_type).toBe('form_submission');
    expect(routingHref(meta!)).toBe('/admin/routes/form_submission/42');
  });

  it('keeps sending a document item to the document', () => {
    const meta = routingMeta(item({ ...base, document_id: 9, subject_type: 'document', subject_id: 9 }));
    expect(routingHref(meta!)).toBe('/admin/document-library/9?tab=routing');
  });

  it('reads an item from a server that sends no subject as a document item', () => {
    const meta = routingMeta(item({ ...base, document_id: 9 }));
    expect(meta?.subject_type).toBe('document');
    expect(routingHref(meta!)).toBe('/admin/document-library/9?tab=routing');
  });

  it('refuses a document item with no document', () => {
    expect(routingMeta(item({ ...base, document_id: null }))).toBeNull();
  });

  it('sends a submission routed before routes travelled on submissions to its review page', () => {
    const meta = routingMeta(item({ ...base, document_id: 9, subject_type: 'form_submission', subject_id: 42 }));
    expect(routingHref(meta!)).toBe('/admin/routes/form_submission/42');
  });
});
