import type { Metadata, Viewport } from "next";
import { headers } from "next/headers";
import { Noto_Sans, Noto_Sans_Arabic, Geist_Mono, Readex_Pro, IBM_Plex_Sans, IBM_Plex_Sans_Arabic, Cairo, Noto_Kufi_Arabic, Rubik, Alexandria, Noto_Naskh_Arabic, Noto_Serif } from "next/font/google";
import "./globals.css";
import { cn } from "@/lib/utils";
import { AuthProvider } from "@/lib/auth-context";
import { ToastProvider } from "@/lib/toast-context";
import { NavigationProvider } from "@/lib/navigation-context";
import { DirectionProvider } from "@/lib/direction-context";
import { PluginFeaturesProvider } from "@/lib/plugin-features-context";
import { CapabilitiesProvider } from "@/lib/capabilities-context";
import { SessionScope } from "@/lib/session-scope";
import { ToastContainerMount } from "@/components/ui/toast-container-mount";
import { PluginScreenRegistrations } from "@/lib/plugin-screens";
import { ReferralCapture } from "@/components/referral-capture";
import { getBranding, getBrandThemeCss } from "@/lib/branding";
import { BrandingProvider, BrandingSync } from "@/lib/branding-context";
import { getThemeOverrides } from "@/lib/theme";
import { ThemeModeProvider, ThemeModeInitScript } from "@/lib/theme-mode-context";
import { DisplayPreferencesProvider, DisplayPreferencesInitScript } from "@/lib/display-preferences-context";
import { DirectionInitScript } from "@/lib/direction-context";
import { AppLanguageProvider } from "@/lib/app-language-provider";
import { getUiPreferences } from "@/lib/ui-preferences";
import { UiPreferencesProvider } from "@/lib/ui-preferences-context";
import { getAppInstall } from "@/lib/pwa/app-install";
import { appIconUrl } from "@/lib/pwa/shared";
import { AppInstall } from "@/components/pwa/app-install";
import { pwaScope } from "@/lib/pwa/scope";

// Design-token font families (see src/design/tokens/base.json): Noto Sans
// (latin) + Noto Sans Arabic together drive --font-sans / --font-heading (see
// the composed stack in globals.css), Geist Mono drives --font-mono. Loading
// both scripts unconditionally — rather than swapping fonts on `dir` — lets
// the browser fall through per-glyph, which is correct for bidi/mixed-script
// content (e.g. an Arabic name inside an English sentence). Only the weights
// design system 2.2 declares are loaded: no 700 anywhere (decision 3.6).
// Readex Pro 600 is for document titles and brand headlines only (3.1, E.35).
const notoSans = Noto_Sans({
  subsets: ["latin"],
  weight: ["400", "500", "600"],
  variable: "--font-noto-sans",
});
const notoSansArabic = Noto_Sans_Arabic({
  subsets: ["arabic"],
  weight: ["400", "500", "600"],
  variable: "--font-noto-sans-arabic",
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  weight: ["400", "500"],
  subsets: ["latin"],
});

// Readex Pro 600 is for document titles and brand headlines (3.1, E.35), and
// since TH.6 a workspace theme may also choose it for headings and body, so
// 400 and 500 are loaded too.
const readexPro = Readex_Pro({
  variable: "--font-readex-pro",
  weight: ["400", "500", "600"],
  subsets: ["latin", "arabic"],
});

// The other allowed theme font pairs (TH.6, src/Core/Theme/ThemeFonts.php).
// Not preloaded: a browser fetches a face only when the workspace's theme
// names it in --font-sans or --font-heading. 400, 500 and 600 only (3.6).
const ibmPlexSans = IBM_Plex_Sans({
  variable: "--font-ibm-plex-sans",
  weight: ["400", "500", "600"],
  subsets: ["latin"],
  preload: false,
});
const ibmPlexSansArabic = IBM_Plex_Sans_Arabic({
  variable: "--font-ibm-plex-sans-arabic",
  weight: ["400", "500", "600"],
  subsets: ["arabic"],
  preload: false,
});
const cairo = Cairo({
  variable: "--font-cairo",
  weight: ["400", "500", "600"],
  subsets: ["latin", "arabic"],
  preload: false,
});
const notoKufiArabic = Noto_Kufi_Arabic({
  variable: "--font-noto-kufi-arabic",
  weight: ["400", "500", "600"],
  subsets: ["arabic"],
  preload: false,
});

// TH.20 (21 Nov 2026): four more pairs, chosen by their Arabic face.
const rubik = Rubik({
  variable: "--font-rubik",
  weight: ["400", "500", "600"],
  subsets: ["latin", "arabic"],
  preload: false,
});
const alexandria = Alexandria({
  variable: "--font-alexandria",
  weight: ["400", "500", "600"],
  subsets: ["latin", "arabic"],
  preload: false,
});
const notoNaskhArabic = Noto_Naskh_Arabic({
  variable: "--font-noto-naskh-arabic",
  weight: ["400", "500", "600"],
  subsets: ["arabic"],
  preload: false,
});
const notoSerif = Noto_Serif({
  variable: "--font-noto-serif",
  weight: ["400", "500", "600"],
  subsets: ["latin"],
  preload: false,
});

export async function generateMetadata(): Promise<Metadata> {
  const b = await getBranding();
  // #1733: the manifest link and the iOS home-screen tags exist only while the
  // operator lets the app be installed.
  const install = await getAppInstall();
  const installable = install.state === "on";
  return {
    title: b.siteName,
    description: "Authentication and plugin management platform",
    icons: {
      // A workspace favicon wins; otherwise the Whity mark (F2, handoff/logos).
      icon: b.faviconUrl
        ? b.faviconUrl
        : [
            { url: "/favicon.svg", type: "image/svg+xml" },
            { url: "/favicon.ico", sizes: "any" },
          ],
      ...(installable ? { apple: appIconUrl("180", install.version) } : {}),
    },
    ...(installable
      ? {
          manifest: "/manifest.webmanifest",
          appleWebApp: { capable: true, title: b.siteName, statusBarStyle: "default" as const },
        }
      : {}),
  };
}

export async function generateViewport(): Promise<Viewport> {
  const install = await getAppInstall();
  return {
    width: "device-width",
    initialScale: 1,
    // The on-screen keyboard shrinks the layout instead of covering it, so the
    // app shell, a bottom sheet's buttons and a chat composer move up above it
    // and a focused field scrolls into view (Chrome on Android; iOS Safari
    // ignores it and scrolls the focused field into view itself).
    interactiveWidget: "resizes-content",
    // #1733: an installed app draws edge to edge; globals.css keeps content
    // inside the safe area (the notch, the home indicator).
    ...(install.state === "on" ? { themeColor: install.themeColor, viewportFit: "cover" as const } : {}),
  };
}

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const branding = await getBranding();
  // #1068: resolved on the SERVER so the first paint already honours it. A
  // client-only fetch would render every date and then blank it a moment
  // later, which is the setting being briefly false on every navigation.
  const uiPreferences = await getUiPreferences();
  // WC-242: color overrides an installed plugin may contribute (see
  // web/lib/theme.ts). Both the server (ThemeApiHandler) and the client
  // (getThemeOverrides) already restrict keys to known design-token names
  // and values to strict '#rrggbb' hex, so building this CSS string by plain
  // concatenation is safe — neither component can contain quotes or braces.
  const themeOverrides = await getThemeOverrides();
  // Decision 2.8: the workspace main colour's light and dark sets, as the HOST
  // resolves them (see BrandingProvider, which takes over in the browser).
  const brandCss = await getBrandThemeCss();
  const appInstall = await getAppInstall();
  // READINESS CE-12: the nonce proxy.ts put in this request's CSP, for the
  // three blocking bootstraps below (Next puts it on its own scripts).
  const nonce = (await headers()).get("x-nonce") ?? undefined;
  const overrideCss = Object.entries(themeOverrides)
    .map(([key, value]) => `--${key}:${value};`)
    .join("");
  return (
    <html
      // `lang` and `dir` are the SERVER's best guess and nothing more. The
      // server cannot know the reader's language: the durable preference lives
      // on their profile and is fetched after hydration. So this is the neutral
      // starting point, and DirectionInitScript (in <head>) corrects both from
      // the last resolved values BEFORE first paint — which is the difference
      // between an Arabic reader seeing a mirrored interface immediately and
      // watching an English left-to-right one flip a moment later.
      lang="en"
      dir="ltr"
      className={cn(
        "h-full",
        "antialiased",
        notoSans.variable,
        notoSansArabic.variable,
        geistMono.variable,
        readexPro.variable,
        ibmPlexSans.variable,
        ibmPlexSansArabic.variable,
        cairo.variable,
        notoKufiArabic.variable,
        rubik.variable,
        alexandria.variable,
        notoNaskhArabic.variable,
        notoSerif.variable,
        "font-sans"
      )}
      // The blocking init scripts (see <head> below) set the `dark` class and
      // the `dir`/`lang` attributes on this element before hydration, from
      // localStorage and system preferences the server can't know — expected,
      // benign mismatches.
      suppressHydrationWarning
    >
      <head>
        {/*
          Blocking, must run before first paint to avoid a flash of the wrong
          color scheme (see lib/theme-mode-context.tsx) — rendered here, ahead
          of globals.css, rather than left to ThemeModeProvider's own effects.
        */}
        <ThemeModeInitScript nonce={nonce} />
        <DisplayPreferencesInitScript nonce={nonce} />
        {/*
          Blocking for the same reason, and a louder one: `dir` decides where
          everything on the page IS. Applied after the theme script only because
          both are synchronous and the order between them does not matter.
        */}
        <DirectionInitScript nonce={nonce} />
      </head>
      {/*
        suppressHydrationWarning (one level deep, body attributes only): browser
        extensions such as Grammarly inject attributes onto <body> after the SSR
        HTML is sent but before React hydrates (e.g. data-gr-ext-installed,
        data-new-gr-c-s-check-loaded), which otherwise trips a dev-only
        hydration-mismatch warning. This does NOT suppress mismatches in the app's
        own markup below <body>.
      */}
      <body className="min-h-full flex flex-col" suppressHydrationWarning>
        {/*
          #964: the app-owned plugin screen overrides, registered in the
          BROWSER. This used to be a bare `import "@/lib/plugin-screens"` at
          the top of this SERVER component, which ran the registrations in the
          server's module graph only — leaving the client-side registry (the
          one `/admin/x/[featureId]` actually consults) empty on every render,
          so no override ever rendered. Rendering the client module here is
          what puts it in the shell's client bundle.
        */}
        <PluginScreenRegistrations />
        {/*
          Notices an affiliate link on ANY page, not only the one carrying the
          registration form. An affiliate points their link wherever their case
          is best made, and almost nobody signs up on the page they land on —
          capturing at the form alone would credit them for exactly the
          customers who did not stop to think. Renders nothing.
        */}
        <ReferralCapture />
        {/*
          Decision 2.8: the main colour's <style> is rendered by BrandingProvider,
          not here, because only the browser knows the signed-in tenant (the
          session cookies are Path=/api, so this server render resolves branding
          by host alone). The provider starts from this server CSS and replaces
          it once it has asked as the reader (#1716).
        */}
        <BrandingProvider initial={branding} initialThemeCss={brandCss}>
          <ThemeModeProvider>
            <AuthProvider>
              <BrandingSync />
              {/*
                Inside AuthProvider for the reason the language provider below
                is: `ui.hide_dates` resolves per TENANT, and a tenant switch
                changes the identity without reloading the page. See
                lib/ui-preferences-context.tsx.
              */}
              <DisplayPreferencesProvider>
              <UiPreferencesProvider initial={uiPreferences}>
                {/*
                  The language provider is ABOVE DirectionProvider deliberately:
                  direction is derived from the resolved language's `direction`
                  property, so the language must resolve first. See
                  lib/direction-context.tsx. It sits INSIDE AuthProvider because
                  it re-resolves the preference when the signed-in identity
                  changes — see lib/app-language-provider.tsx.
                */}
                <AppLanguageProvider>
                  <DirectionProvider>
                    <ToastProvider>
                      {/*
                        2026-11-22: everything that depends on the workspace
                        is remounted together when the session changes (a
                        switch, another account, a plan change), here or in
                        another tab. Toasts stay outside it, so "You're now
                        in …" survives. See lib/session-scope.tsx.
                      */}
                      <SessionScope>
                        <CapabilitiesProvider>
                          <NavigationProvider>
                            <PluginFeaturesProvider>
                              {children}
                            </PluginFeaturesProvider>
                          </NavigationProvider>
                        </CapabilitiesProvider>
                      </SessionScope>
                      <ToastContainerMount />
                      <AppInstall
                        state={appInstall.state}
                        name={appInstall.siteName}
                        iconUrl={appIconUrl("192", appInstall.version)}
                        scope={pwaScope()}
                      />
                    </ToastProvider>
                  </DirectionProvider>
                </AppLanguageProvider>
              </UiPreferencesProvider>
              </DisplayPreferencesProvider>
            </AuthProvider>
          </ThemeModeProvider>
        </BrandingProvider>
        {/* After the brand <style> above, so a plugin's colours still win. */}
        {overrideCss !== "" && <style>{`:root{${overrideCss}}`}</style>}
      </body>
    </html>
  );
}
