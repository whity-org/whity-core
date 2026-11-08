/**
 * Unit pickers show the reader's language, falling back to the original (#821).
 */

import { renderHook, waitFor } from '@testing-library/react';
import { useLocalizedUnits } from '@/hooks/useLocalizedUnits';
import { useOuOptions } from '@/app/(protected)/admin/users/use-ou-options';
import { createUsersAdapter } from '@/lib/users-adapter';

let mockLanguage: string | null = 'en';
jest.mock('../../packages/features/src/i18n/useLanguageCode', () => ({ useLanguageCode: () => mockLanguage }));

const mockApiGet = jest.fn();
jest.mock('@/lib/api/client', () => ({ api: { GET: (...args: unknown[]) => mockApiGet(...args) } }));
jest.mock('@/lib/toast-context', () => {
  const addToast = jest.fn();
  return { useToast: () => ({ addToast }) };
});

const ARABIC_ORIGINAL = {
  id: 1,
  name: 'الموارد البشرية',
  name_language: 'ar',
  names: { ar: 'الموارد البشرية', en: 'Human Resources' },
};
const ARABIC_ONLY = { id: 2, name: 'المالية', name_language: 'ar', names: { ar: 'المالية' } };

beforeEach(() => {
  mockLanguage = 'en';
  mockApiGet.mockReset();
});

describe('useLocalizedUnits', () => {
  it("shows the reader's language, and the original when there is no name in it", () => {
    const units = [ARABIC_ORIGINAL, ARABIC_ONLY];

    expect(renderHook(() => useLocalizedUnits(units)).result.current.map((u) => u.name)).toEqual([
      'Human Resources',
      'المالية',
    ]);

    mockLanguage = 'ar';
    expect(renderHook(() => useLocalizedUnits(units)).result.current.map((u) => u.name)).toEqual([
      'الموارد البشرية',
      'المالية',
    ]);
  });

  it('keeps showing a unit from a server without names', () => {
    const { result } = renderHook(() => useLocalizedUnits([{ id: 9, name: 'Registry' }]));
    expect(result.current[0]).toEqual({ id: 9, name: 'Registry' });
  });

  it('has nothing to name before the list loads', () => {
    expect(renderHook(() => useLocalizedUnits(null)).result.current).toEqual([]);
  });

  it('follows a language switch on the rows it already has', () => {
    const units = [ARABIC_ORIGINAL];
    const { result, rerender } = renderHook(() => useLocalizedUnits(units));
    expect(result.current[0].name).toBe('Human Resources');

    mockLanguage = 'ar';
    rerender();
    expect(result.current[0].name).toBe('الموارد البشرية');
    // The other names stay available and the input is untouched.
    expect(result.current[0].names.en).toBe('Human Resources');
    expect(units[0].name).toBe('الموارد البشرية');
  });
});

describe('the Users edit picker', () => {
  it('labels each option in the reader language without another request', async () => {
    mockApiGet.mockResolvedValue({
      data: { data: [ARABIC_ORIGINAL, ARABIC_ONLY], pagination: { page: 1, perPage: 100, total: 2, totalPages: 1 } },
    });

    const { result, rerender } = renderHook(() => useOuOptions(true));
    await waitFor(() => expect(result.current.ouOptions).toHaveLength(2));
    expect(result.current.ouOptions[0]).toEqual({ value: '1', label: 'Human Resources' });

    const requests = mockApiGet.mock.calls.length;
    mockLanguage = 'ar';
    rerender();
    expect(result.current.ouOptions[0]).toEqual({ value: '1', label: 'الموارد البشرية' });
    expect(mockApiGet).toHaveBeenCalledTimes(requests);
  });
});

describe('the users adapter', () => {
  it('passes the name in every language to the record screen', async () => {
    const adapter = createUsersAdapter({
      request: jest.fn().mockResolvedValue({
        status: 200,
        body: { data: [ARABIC_ORIGINAL], pagination: { total: 1 } },
      }),
    });

    const { options } = await adapter.listOus();
    expect(options[0]).toEqual({ id: 1, name: 'الموارد البشرية', names: ARABIC_ORIGINAL.names });
  });
});
