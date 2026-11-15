'use client';

/**
 * Settings › Preferences › Notifications (WHIT-1075): push on this device, the
 * person's other devices, and which kinds come as a push.
 *
 * Per workspace: a person in two workspaces chooses for each (TmPreferences).
 * The kinds are a person's own (type, `push`) choices on the notification
 * preferences API, opt-out like email: on until turned off. Sign-in and
 * security notifications can't be turned off (they protect the account).
 *
 * There is no web card for this screen yet (brand ask WHIT-1075); it follows
 * the phone app's `TmPreferences` and the Display card next to it.
 */

import { useCallback, useEffect, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Switch } from '@whity/ui/switch';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { api } from '@/lib/api/client';
import { deviceLabel, NOTIFICATIONS_ANCHOR, usePush } from '@/lib/pwa/push';

const CHANNEL = 'push';

type Kind = { id: string; types: string[]; label: string };

type Preference = { type: string; channel: string; enabled: boolean };

/** On unless the person turned the kind, or every kind, off on push. */
function isOn(prefs: Preference[], types: string[]): boolean {
  const every = prefs.find((p) => p.type === '*' && p.channel === CHANNEL);
  return types.every((type) => {
    const exact = prefs.find((p) => p.type === type && p.channel === CHANNEL);
    return exact ? exact.enabled : every ? every.enabled : true;
  });
}

export function PushSettings() {
  const t = useTranslation('auth');
  const language = useLanguageCode();
  const dates = useDateDisplay();
  const push = usePush();
  const [prefs, setPrefs] = useState<Preference[]>([]);
  const [pluginKinds, setPluginKinds] = useState<Kind[]>([]);
  const [saving, setSaving] = useState<string | null>(null);

  // Reached from More, the account menu or Ctrl+K (WHIT-1078): the card is
  // below Display, so bring it into view.
  useEffect(() => {
    if (window.location.hash !== `#${NOTIFICATIONS_ANCHOR}`) return;
    const frame = window.requestAnimationFrame(() => {
      document.getElementById(NOTIFICATIONS_ANCHOR)?.scrollIntoView({ block: 'start' });
    });
    return () => window.cancelAnimationFrame(frame);
  }, []);

  useEffect(() => {
    let alive = true;
    void api.GET('/api/v1/me/notification-preferences').then(({ data }) => {
      if (!alive || !data) return;
      setPrefs(data.data as Preference[]);
      setPluginKinds(
        data.plugin_types.map((p) => ({ id: p.type, types: [p.type], label: language === 'ar' ? p.label.ar : p.label.en }))
      );
    });
    return () => {
      alive = false;
    };
  }, [language]);

  // Chat first (WHIT-1077): on by default, like every kind, until turned off.
  const kinds: Kind[] = [
    {
      id: 'chat-messages',
      types: ['chat.message'],
      label: t('settings.push.kind.chat', 'Chat messages'),
    },
    {
      id: 'chat-mentions',
      types: ['chat.mention'],
      label: t('settings.push.kind.chatMention', 'Mentions in chat'),
    },
    {
      id: 'documents-waiting',
      types: ['document.routing.awaiting'],
      label: t('settings.push.kind.awaiting', 'Documents waiting for you'),
    },
    {
      id: 'documents-sent',
      types: ['document.routing.delivered'],
      label: t('settings.push.kind.delivered', 'Documents sent to you'),
    },
    {
      id: 'mentions',
      types: ['document.comment.mention'],
      label: t('settings.push.kind.mention', 'Mentions in comments'),
    },
    {
      id: 'meetings',
      types: ['convening.meeting.invited', 'convening.meeting.updated'],
      label: t('settings.push.kind.meetings', 'Meeting invitations and changes'),
    },
    ...pluginKinds,
  ];

  const setKind = useCallback(async (kind: Kind, enabled: boolean) => {
    setSaving(kind.id);
    const { data } = await api.PUT('/api/v1/me/notification-preferences', {
      body: { preferences: kind.types.map((type) => ({ type, channel: CHANNEL, enabled })) },
    });
    if (data) setPrefs(data.data as Preference[]);
    setSaving(null);
  }, []);

  const others = push.devices.filter((d) => d.id !== push.currentId);

  let thisDeviceHelp: string;
  if (push.loading) {
    thisDeviceHelp = t('settings.push.offHelp', 'Turn on to see approvals, mentions and your agents asking first on this device. Never marketing.');
  } else if (push.support === 'needs-install') {
    thisDeviceHelp = t('settings.push.needsInstall', 'On iPhone and iPad, add the app to your Home Screen first (Share, then Add to Home Screen). Then turn this on from the app.');
  } else if (push.support === 'unsupported') {
    thisDeviceHelp = t('settings.push.unsupported', "This browser can't show notifications from Whity.");
  } else if (push.support === 'denied' || push.error === 'denied') {
    thisDeviceHelp = t('settings.push.denied', 'Notifications are blocked for this site. Allow them in your browser settings, then turn this on.');
  } else if (push.error === 'failed') {
    thisDeviceHelp = t('settings.push.failed', 'Notifications could not be turned on. Try again.');
  } else {
    thisDeviceHelp = push.on
      ? t('settings.push.onHelp', 'This device shows a notification when something needs you here.')
      : t('settings.push.offHelp', 'Turn on to see approvals, mentions and your agents asking first on this device. Never marketing.');
  }

  return (
    <Card id={NOTIFICATIONS_ANCHOR} data-testid="push-settings" className="scroll-mt-4">
      <CardHeader>
        <CardTitle>{t('settings.push.title', 'Notifications')}</CardTitle>
        <CardDescription>
          {t('settings.push.description', 'Notifications on your devices for this workspace. Sign-in and security notifications always come.')}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-5">
        <label className="flex items-start justify-between gap-4">
          <span className="grid gap-0.5">
            <span className="text-sm font-medium">{t('settings.push.thisDevice', 'On this device')}</span>
            <span
              className={`text-xs ${push.error ? 'text-destructive' : 'text-muted-foreground'}`}
              data-testid="push-this-device-help"
            >
              {thisDeviceHelp}
            </span>
          </span>
          <Switch
            data-testid="push-this-device"
            checked={push.on}
            disabled={push.loading || push.busy || push.support !== 'ready'}
            onCheckedChange={(checked) => void (checked ? push.turnOn() : push.turnOff())}
          />
        </label>

        {others.length > 0 && (
          <div className="grid gap-2">
            <span className="text-sm font-medium">{t('settings.push.otherDevices', 'Your other devices')}</span>
            <ul className="divide-y divide-border rounded-control border border-border">
              {others.map((device) => {
                const { browser, os } = deviceLabel(device.user_agent);
                const name = browser && os
                  ? t('settings.push.deviceName', '{browser} on {os}', { browser, os })
                  : browser || os || t('settings.push.unknownDevice', 'A browser');
                return (
                  <li key={device.id} className="flex min-h-12 items-center gap-3 px-3 py-2">
                    <span className="grid min-w-0 flex-1 gap-0.5">
                      <span className="truncate text-sm">{name}</span>
                      <span className="text-xs text-muted-foreground">
                        {t('settings.push.since', 'Since {date}', { date: dates.date(device.created_at) ?? '' })}
                      </span>
                    </span>
                    <Button variant="outline" size="sm" disabled={push.busy} onClick={() => void push.remove(device.id)}>
                      {t('settings.push.remove', 'Turn off')}
                    </Button>
                  </li>
                );
              })}
            </ul>
          </div>
        )}

        <fieldset className="grid gap-3" disabled={push.devices.length === 0}>
          <legend className="mb-2 text-sm font-medium">{t('settings.push.kinds', 'Send a notification for')}</legend>
          {kinds.map((kind) => (
            <label key={kind.id} className="flex items-center justify-between gap-4">
              <span className="text-sm">{kind.label}</span>
              <Switch
                checked={isOn(prefs, kind.types)}
                disabled={push.devices.length === 0 || saving === kind.id}
                onCheckedChange={(checked) => void setKind(kind, checked)}
              />
            </label>
          ))}
        </fieldset>
      </CardContent>
    </Card>
  );
}
