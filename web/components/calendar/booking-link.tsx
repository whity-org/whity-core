"use client";

/**
 * "Your booking link" (WHIT-1117): the public booking page's address, so the
 * workspace can give it to customers. Shown in Calendar settings and on the
 * calendar itself, and reachable from Ctrl+K ("Booking link", "Share booking
 * link").
 *
 * - The full address with Copy and Share.
 * - Share opens a ready-made message, editable before sending, in English or
 *   Arabic (the workspace's own language first): the phone's own share sheet
 *   where there is one, WhatsApp, email, or copy; and a QR code to print for
 *   the counter.
 * - When the booking page is off the card says so, and someone who manages
 *   the calendar can turn it on from here.
 */

import { useState } from "react";
import QRCode from "react-qr-code";
import { Button } from "@whity/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@whity/ui/card";
import { Icon } from "@whity/ui/icon";
import { Textarea } from "@whity/ui/textarea";
import { ToggleGroup } from "@whity/ui/toggle-group";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useTranslation } from "@whity/features/i18n";
import { useAuth } from "@/lib/auth-context";
import { useToast } from "@/lib/toast-context";
import { calendarApi } from "@/lib/calendar/api";
import { cn } from "@/lib/utils";

export type MessageLanguage = "en" | "ar";

/** The ready-made message, in each language whatever the screen's language. */
const MESSAGE: Record<
  MessageLanguage,
  (workspace: string, link: string) => string
> = {
  en: (workspace, link) => `Book your appointment with ${workspace}: ${link}`,
  ar: (workspace, link) => `احجز موعدك لدى ${workspace}: ${link}`,
};

export function bookingMessage(
  language: MessageLanguage,
  workspace: string,
  link: string,
): string {
  return MESSAGE[language](workspace, link);
}

/** The workspace's own language (the language its name was given in) and its name in each. */
export function useWorkspaceVoice(): {
  language: MessageLanguage;
  nameIn: (language: MessageLanguage) => string;
} {
  const { user, memberships } = useAuth();
  const membership = memberships.find((m) => m.tenant_id === user?.tenant_id);
  const language: MessageLanguage =
    membership?.tenant_name_language === "ar" ? "ar" : "en";
  return {
    language,
    nameIn: (lang) =>
      membership?.tenant_names?.[lang] || membership?.tenant_name || "",
  };
}

async function copyText(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

/** Opens a page holding only the QR code, the name and the address, and prints it. */
function printQr(
  svg: SVGSVGElement | null,
  workspace: string,
  link: string,
  title: string,
) {
  if (svg === null) return;
  const page = window.open("", "_blank", "width=480,height=640");
  if (page === null) return;
  const escape = (text: string) =>
    text.replace(
      /[&<>"]/g,
      (c) =>
        ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c] ?? c,
    );
  page.document.write(
    `<!doctype html><html><head><meta charset="utf-8"><title>${escape(title)}</title>` +
      '<style>body{font-family:"Noto Sans","Noto Sans Arabic",sans-serif;text-align:center;padding:32px}h1{font-size:24px;font-weight:600;margin:0 0 8px}p{font-size:16px;margin:16px 0 0;word-break:break-all}svg{width:280px;height:280px}</style>' +
      `</head><body><h1>${escape(workspace)}</h1><p>${escape(title)}</p>${svg.outerHTML}<p dir="ltr">${escape(link)}</p></body></html>`,
  );
  page.document.close();
  page.focus();
  page.print();
}

export interface BookingLinkShareProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  link: string;
}

/** Share the link: an editable message, then the phone's share sheet, WhatsApp, email or copy, and the QR code. */
export function BookingLinkShare({
  open,
  onOpenChange,
  link,
}: BookingLinkShareProps) {
  // The body mounts fresh each time it opens, so the message starts in the workspace's language.
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {open && <ShareBody link={link} onClose={() => onOpenChange(false)} />}
    </Dialog>
  );
}

function ShareBody({ link, onClose }: { link: string; onClose: () => void }) {
  const t = useTranslation("calendar");
  const { addToast } = useToast();
  const voice = useWorkspaceVoice();
  const [language, setLanguage] = useState<MessageLanguage>(voice.language);
  const [message, setMessage] = useState(() =>
    bookingMessage(voice.language, voice.nameIn(voice.language), link),
  );
  const [qrBox, setQrBox] = useState<HTMLDivElement | null>(null);
  const canShare =
    typeof navigator !== "undefined" && typeof navigator.share === "function";

  const pick = (next: MessageLanguage) => {
    setLanguage(next);
    setMessage(bookingMessage(next, voice.nameIn(next), link));
  };

  const copied = async (what: string) => {
    if (await copyText(what)) addToast(t("link.copied", "Copied"), "success");
    else
      addToast(
        t(
          "link.copyFailed",
          "It could not be copied. Select it and copy it yourself.",
        ),
        "error",
      );
  };

  return (
    <DialogContent
      className="max-h-[90vh] max-w-lg overflow-y-auto"
      data-testid="booking-link-share"
    >
      <DialogHeader>
        <DialogTitle>
          {t("link.shareTitle", "Share your booking link")}
        </DialogTitle>
        <DialogDescription>
          {t(
            "link.shareDescription",
            "Change the message if you like, then send it the way your customers use.",
          )}
        </DialogDescription>
      </DialogHeader>
      <div className="grid gap-3">
        <ToggleGroup
          label={t("link.messageLanguage", "Message language")}
          required
          value={language}
          onChange={(value) => {
            if (value === "en" || value === "ar") pick(value);
          }}
          options={[
            { value: "en", label: "English" },
            { value: "ar", label: "العربية" },
          ]}
        />
        <label htmlFor="booking-link-message" className="sr-only">
          {t("link.message", "Message")}
        </label>
        <Textarea
          id="booking-link-message"
          rows={3}
          dir={language === "ar" ? "rtl" : "ltr"}
          value={message}
          onChange={(event) => setMessage(event.target.value)}
          data-testid="booking-link-message"
        />
        <div className="grid gap-2 sm:grid-cols-2">
          {canShare && (
            <Button
              onClick={() => {
                void navigator.share({ text: message }).catch(() => undefined);
              }}
              data-testid="booking-link-native"
            >
              <Icon name="send" />
              {t("link.share", "Share")}
            </Button>
          )}
          <Button variant="outline" asChild>
            <a
              href={`https://wa.me/?text=${encodeURIComponent(message)}`}
              target="_blank"
              rel="noopener noreferrer"
              data-testid="booking-link-whatsapp"
            >
              <Icon name="whatsapp" />
              {t("link.whatsapp", "WhatsApp")}
            </a>
          </Button>
          <Button variant="outline" asChild>
            <a
              href={`mailto:?subject=${encodeURIComponent(t("link.emailSubject", "Book an appointment"))}&body=${encodeURIComponent(message)}`}
              data-testid="booking-link-email"
            >
              <Icon name="mail" />
              {t("link.email", "Email")}
            </a>
          </Button>
          <Button
            variant="outline"
            onClick={() => void copied(message)}
            data-testid="booking-link-copy-message"
          >
            <Icon name="copy" />
            {t("link.copyMessage", "Copy the message")}
          </Button>
        </div>
        <div className="mt-2 grid justify-items-center gap-2 rounded-card border border-border bg-card p-4">
          <span className="text-sm font-medium">
            {t("link.qrTitle", "QR code for the counter")}
          </span>
          <div className="p-1" ref={setQrBox}>
            {/* Dark on light in both themes: a phone camera reads it reliably and it prints the same. */}
            <QRCode
              value={link}
              size={168}
              bgColor="#FFFFFF"
              fgColor="#000000"
              className="rounded p-3"
              style={{ background: "#FFFFFF", boxSizing: "content-box" }}
              aria-label={t("link.qrLabel", "QR code of your booking link")}
            />
          </div>
          <Button
            variant="outline"
            size="sm"
            onClick={() =>
              printQr(
                qrBox?.querySelector("svg") ?? null,
                voice.nameIn(language),
                link,
                t("link.qrPrintTitle", "Scan to book"),
              )
            }
            data-testid="booking-link-print"
          >
            <Icon name="print" />
            {t("link.print", "Print")}
          </Button>
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>
          {t("action.close", "Close")}
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

export interface BookingLinkCardProps {
  /** The booking page's address (`booking_page_url`); null when it cannot be formed. */
  link: string | null;
  enabled: boolean;
  canManage: boolean;
  /** After the booking page is turned on from here. */
  onEnabled: () => void;
  /** Smaller, for the calendar's side panel. */
  compact?: boolean;
  /** Open the share dialog straight away (Ctrl+K "Share booking link"). */
  shareOnOpen?: boolean;
  className?: string;
}

export function BookingLinkCard({
  link,
  enabled,
  canManage,
  onEnabled,
  compact = false,
  shareOnOpen = false,
  className,
}: BookingLinkCardProps) {
  const t = useTranslation("calendar");
  const { addToast } = useToast();
  const [sharing, setSharing] = useState(
    shareOnOpen && enabled && link !== null,
  );
  const [turningOn, setTurningOn] = useState(false);

  if (link === null) return null;

  const turnOn = async () => {
    setTurningOn(true);
    try {
      await calendarApi.saveSettings({ booking_page_enabled: true } as never);
      addToast(t("link.turnedOn", "Your booking page is on"), "success");
      onEnabled();
    } catch {
      addToast(
        t(
          "link.turnOnFailed",
          "The booking page could not be turned on. Try again.",
        ),
        "error",
      );
    } finally {
      setTurningOn(false);
    }
  };

  const copy = async () => {
    if (await copyText(link)) addToast(t("link.copied", "Copied"), "success");
    else
      addToast(
        t(
          "link.copyFailed",
          "It could not be copied. Select it and copy it yourself.",
        ),
        "error",
      );
  };

  return (
    <Card
      id="booking-link"
      className={cn(compact && "gap-3 py-4", className)}
      data-testid="booking-link"
    >
      <CardHeader className={cn(compact && "px-4")}>
        <CardTitle className={cn(compact && "text-sm")}>
          {t("link.title", "Your booking link")}
        </CardTitle>
        {!compact && (
          <CardDescription>
            {enabled
              ? t(
                  "link.description",
                  "Give it to customers so they can book themselves: on WhatsApp, by email, on your website, or as a QR code at the counter.",
                )
              : t(
                  "link.offDescription",
                  "Your booking page is off, so customers can’t book themselves yet.",
                )}
          </CardDescription>
        )}
      </CardHeader>
      <CardContent className={cn("grid gap-3", compact && "px-4")}>
        {enabled ? (
          <>
            <p
              className="m-0 rounded-control border border-border bg-muted px-3 py-2 font-mono text-sm break-all text-foreground select-all"
              dir="ltr"
              data-testid="booking-link-url"
            >
              {link}
            </p>
            <div
              className={cn("grid gap-2", compact ? "grid-cols-2" : "sm:flex")}
            >
              <Button
                variant="outline"
                size={compact ? "sm" : "default"}
                onClick={() => void copy()}
                data-testid="booking-link-copy"
              >
                <Icon name="copy" />
                {t("link.copy", "Copy")}
              </Button>
              <Button
                variant="outline"
                size={compact ? "sm" : "default"}
                onClick={() => setSharing(true)}
                data-testid="booking-link-share-open"
              >
                <Icon name="send" />
                {t("link.share", "Share")}
              </Button>
            </div>
            <BookingLinkShare
              open={sharing}
              onOpenChange={setSharing}
              link={link}
            />
          </>
        ) : canManage ? (
          <>
            {compact && (
              <p className="m-0 text-sm text-muted-foreground">
                {t(
                  "link.offDescription",
                  "Your booking page is off, so customers can’t book themselves yet.",
                )}
              </p>
            )}
            <Button
              variant="outline"
              onClick={() => void turnOn()}
              loading={turningOn}
              data-testid="booking-link-turn-on"
            >
              {t("link.turnOn", "Turn on the booking page")}
            </Button>
          </>
        ) : (
          <p className="m-0 text-sm text-muted-foreground">
            {t(
              "link.askToTurnOn",
              "Ask someone who manages the calendar to turn on the booking page.",
            )}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
