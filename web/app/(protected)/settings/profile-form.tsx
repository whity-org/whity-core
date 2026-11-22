'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
} from '@whity/ui/form';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { PasswordStrengthIndicator } from '@/components/PasswordStrengthIndicator';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { PASSWORD_MIN_LENGTH } from '@/lib/password-policy';

/**
 * Self-service profile editor (WC-64).
 *
 * Backed by PATCH /api/me, which updates ONLY the currently authenticated user.
 * Two fields are editable here:
 *  - email: validated for format and (server-side) for per-tenant uniqueness.
 *  - password: optional; when set it requires confirmation and a minimum length,
 *    and the backend additionally verifies the current password.
 *
 * The current password is required to save ANY change (the backend rejects an
 * update without it). The display name is derived from the email local-part —
 * there is no `users.name` column — so it is shown read-only here and flagged as
 * a follow-up rather than edited.
 *
 * Validation mirrors the existing admin modals (zod + react-hook-form). On
 * success a toast is shown and the auth context is refreshed so the new email
 * propagates immediately (the backend re-issues the auth cookies).
 */
// READINESS CE-5: the server's minimum, from one place.
const MIN_PASSWORD_LENGTH = PASSWORD_MIN_LENGTH;

/**
 * The schema is BUILT from `t` rather than declared at module scope, because a
 * validation message is as user-facing as a label and there is nowhere else to
 * put it. It is memoised on `t` in the component, so the resolver is rebuilt
 * only when the language actually changes — never per keystroke.
 */
function makeProfileSchema(t: TranslateFn) {
  return z
    .object({
      email: z.string().email(t('profile.email.invalid', 'Invalid email address')),
      currentPassword: z
        .string()
        .min(1, t('profile.currentPassword.required', 'Current password is required')),
      newPassword: z
        .string()
        .min(
          MIN_PASSWORD_LENGTH,
          t('profile.newPassword.tooShort', 'Password must be at least {min} characters', {
            min: MIN_PASSWORD_LENGTH,
          })
        )
        .optional()
        .or(z.literal('')),
      confirmPassword: z.string().optional().or(z.literal('')),
    })
    .refine(
      (data) => (data.newPassword ?? '') === (data.confirmPassword ?? ''),
      {
        message: t('profile.confirmPassword.mismatch', 'Passwords do not match'),
        path: ['confirmPassword'],
      }
    );
}

type ProfileFormData = z.infer<ReturnType<typeof makeProfileSchema>>;

interface ProfilePayload {
  email: string;
  current_password: string;
  password?: string;
}

export function ProfileForm() {
  const { user, refreshAuth } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('auth');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const profileSchema = useMemo(() => makeProfileSchema(t), [t]);

  const currentEmail = user?.email ?? '';
  // The display name is derived from the email local-part (no users.name column).
  const derivedName = currentEmail.includes('@')
    ? currentEmail.slice(0, currentEmail.indexOf('@'))
    : currentEmail;

  const form = useForm<ProfileFormData>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      email: currentEmail,
      currentPassword: '',
      newPassword: '',
      confirmPassword: '',
    },
  });

  // Keep the email field in sync once the auth context resolves /api/me.
  // `form` is stable from react-hook-form, so listing it does not re-run the
  // sync on every render — only a changed email triggers a reset.
  useEffect(() => {
    if (currentEmail) {
      form.reset({
        email: currentEmail,
        currentPassword: '',
        newPassword: '',
        confirmPassword: '',
      });
    }
  }, [currentEmail, form]);

  const onSubmit = async (data: ProfileFormData) => {
    const emailChanged = data.email !== currentEmail;
    const passwordChanged = (data.newPassword ?? '') !== '';

    if (!emailChanged && !passwordChanged) {
      addToast(t('profile.nothingToUpdate', 'Nothing to update'), 'info');
      return;
    }

    try {
      setIsSubmitting(true);

      const payload: ProfilePayload = {
        email: data.email,
        current_password: data.currentPassword,
      };
      if (passwordChanged) {
        payload.password = data.newPassword;
      }

      const { data: updated, error: updateError, response } = await api.PATCH('/api/v1/me', {
        body: payload as never,
      });

      if (!response.ok) {
        const errorData = ((updateError ?? {}) as unknown) as {
          error?: string;
          message?: string;
        };
        throw new Error(
          errorData.error || errorData.message || t('profile.error.update', 'Failed to update profile')
        );
      }

      // Confirm the change actually persisted before claiming success: the
      // backend returns the updated user, so assert the email it reports back
      // matches what we asked for rather than trusting a bare 200.
      const result = ((updated ?? null) as unknown) as
        | { user?: { email?: string }; pending_email?: string | null }
        | null;
      // A new address is not taken on trust (WHIT-1246): the backend adds it
      // unconfirmed and sends it a link, and the main address stays until the
      // person confirms the new one and makes it their main address.
      const pendingEmail = result?.pending_email ?? null;
      const persistedEmail = result?.user?.email;
      if (pendingEmail === null && persistedEmail !== undefined && persistedEmail !== data.email) {
        throw new Error(
          t('profile.error.notPersisted', 'Profile update did not persist the new email')
        );
      }

      if (pendingEmail !== null) {
        addToast(
          t(
            'profile.email.confirmSent',
            'We sent a link to {email}. Open it to confirm the address, then make it your main address under Email addresses.',
            { email: pendingEmail }
          ),
          'success'
        );
      } else {
        addToast(t('profile.updated', 'Profile updated successfully'), 'success');
      }

      // Refresh the auth context; the backend re-issued the auth cookies.
      await refreshAuth();

      // Clear the credential fields. The email field shows the main address,
      // which only changes once a new one is confirmed and made main.
      form.reset({
        email: persistedEmail ?? data.email,
        currentPassword: '',
        newPassword: '',
        confirmPassword: '',
      });
    } catch (error) {
      const message =
        error instanceof Error ? error.message : t('profile.error.update', 'Failed to update profile');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
        <div className="space-y-2">
          <label
            htmlFor="profile-name"
            className="text-sm font-medium leading-none"
          >
            {t('profile.displayName.label', 'Display Name')}
          </label>
          <Input id="profile-name" value={derivedName} disabled />
          <p className="text-xs text-muted-foreground">
            {t(
              'profile.displayName.hint',
              'Name is derived from your email and cannot be changed yet.'
            )}
          </p>
        </div>

        <FormField
          control={form.control}
          name="email"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('profile.email.label', 'Email Address')}</FormLabel>
              <FormControl>
                <Input
                  type="email"
                  placeholder={t('profile.email.placeholder', 'you@example.com')}
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="newPassword"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('profile.newPassword.label', 'New Password')}</FormLabel>
              <FormControl>
                <Input
                  type="password"
                  placeholder="••••••••"
                  autoComplete="new-password"
                  {...field}
                />
              </FormControl>
              <PasswordStrengthIndicator password={field.value ?? ''} />
              <FormDescription>
                {t('profile.newPassword.hint', 'Leave blank to keep your current password.')}
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="confirmPassword"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('profile.confirmPassword.label', 'Confirm New Password')}</FormLabel>
              <FormControl>
                <Input
                  type="password"
                  placeholder="••••••••"
                  autoComplete="new-password"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="currentPassword"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('profile.currentPassword.label', 'Current Password')}</FormLabel>
              <FormControl>
                <Input
                  type="password"
                  placeholder="••••••••"
                  autoComplete="current-password"
                  {...field}
                />
              </FormControl>
              <FormDescription>
                {t(
                  'profile.currentPassword.hint',
                  'Required to confirm any change to your email or password.'
                )}
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting
            ? t('profile.submit.pending', 'Saving...')
            : t('profile.submit', 'Save Changes')}
        </Button>
      </form>
    </Form>
  );
}
