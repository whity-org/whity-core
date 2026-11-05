import { useState, useCallback, useEffect } from 'react';
import { isForbiddenError } from '@/lib/forbidden';

export function useFetch<T>(
  fetchFn: () => Promise<T>,
  deps: React.DependencyList = []
): { data: T | null; loading: boolean; error: string | null; forbidden: boolean; refetch: () => void } {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // The request was refused (a `ForbiddenError`): shown as no access, not as a failure.
  const [forbidden, setForbidden] = useState(false);
  // Incrementing this causes the effect to re-run, updating data/loading/error.
  const [refreshKey, setRefreshKey] = useState(0);

  // eslint-disable-next-line react-hooks/exhaustive-deps, react-hooks/use-memo
  const stableFetch = useCallback(fetchFn, deps);

  const refetch = useCallback(() => setRefreshKey((k) => k + 1), []);

  useEffect(() => {
    let cancelled = false;

    const run = async () => {
      setLoading(true);
      setError(null);
      setForbidden(false);
      try {
        const result = await stableFetch();
        if (!cancelled) setData(result);
      } catch (err: unknown) {
        if (!cancelled) {
          setForbidden(isForbiddenError(err));
          setError(err instanceof Error ? err.message : 'An error occurred');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void run();

    return () => { cancelled = true; };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stableFetch, refreshKey]);

  return { data, loading, error, forbidden, refetch };
}
