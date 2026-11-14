import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import DocumentThemesPage from "@/app/(protected)/admin/document-themes/page"
import { arabicHandlers, withArabic } from "../../.storybook/arabic"
import { capabilitiesHandler, defaultHandlers, http, HttpResponse, MOCK_USER } from "../../.storybook/mocks"
import SCHEMA from "./document-theme-schema.fixture.json"

/**
 * The workspace document theme (WHIT-1024; design 3.4, `TplFormPrintStyle`):
 * the Documents and Form printouts tabs. Example Bakery, in its orange.
 * `document-theme-schema.fixture.json` is `DocumentThemeTokens::schema()` as
 * the server sends it.
 */
const THEME = {
  ref: "tenant.workspace",
  scope: "tenant",
  key: "workspace",
  name: "Example Bakery",
  name_ar: "المخبز النموذجي",
  extends: "whity.standard",
  tokens: { accent: "#b74c05", form: { officeDetails: "strip", sectionStyle: "eyebrow", questions: "twoColumns" } },
  version: 4,
  archived: false,
  default_for: ["workspace"],
  resolved: { tokens: { accent: "#b74c05", accentUse: "title", letterhead: { logo: "wide", lines: ["address", "taxId"] }, titleFace: "readex", largePrint: false } },
}

const NINE_DAYS = new Date(Date.now() + 9 * 86_400_000 - 60_000).toISOString()

function handlers(allowed: boolean, subscription: Record<string, unknown>) {
  return [
    capabilitiesHandler(["documents:read", "documents:themes", "settings:read"]),
    http.get("*/api/v1/me", () =>
      HttpResponse.json({
        user: { ...MOCK_USER, tenant_id: 10 },
        memberships: [{ tenant_id: 10, tenant_name: "Example Bakery", tenant_names: { ar: "المخبز النموذجي" }, role: "owner" }],
      })
    ),
    http.get("*/api/v1/document-themes/schema", () =>
      HttpResponse.json({ data: { ...SCHEMA, form: { ...SCHEMA.form, allowed } } })
    ),
    http.get("*/api/v1/document-themes/tenant.workspace", () => HttpResponse.json({ data: THEME })),
    http.get("*/api/v1/document-themes", () =>
      HttpResponse.json({ data: [{ ...THEME, ref: "whity.standard", scope: "system", key: "standard", default_for: [] }, THEME] })
    ),
    http.get("*/api/v1/subscription", () => HttpResponse.json({ data: subscription })),
    http.post("*/api/v1/document-themes/form-printout-preview", () =>
      HttpResponse.json({ error: "There is no form response to preview yet. Send one form first." }, { status: 404 })
    ),
  ]
}

const trial = handlers(true, { status: "trialing", current_period_end: NINE_DAYS })
const free = handlers(false, { status: "active" })

const meta = {
  title: "Documents/Document theme",
  component: DocumentThemesPage,
  parameters: { nextjs: { appDirectory: true }, msw: { handlers: [...trial, ...defaultHandlers] } },
} satisfies Meta<typeof DocumentThemesPage>

export default meta
type Story = StoryObj<typeof meta>

/** A paid plan or a free trial: every part can be chosen. */
export const Trial: Story = {}

/** A free workspace: the plan gate, B headings, everything else locked. */
export const FreeWorkspace: Story = {
  parameters: { msw: { handlers: [...free, ...defaultHandlers] } },
}

export const Arabic: Story = {
  decorators: [withArabic],
  parameters: { msw: { handlers: [...arabicHandlers, ...trial, ...defaultHandlers] } },
}

export const ArabicFreeWorkspace: Story = {
  decorators: [withArabic],
  parameters: { msw: { handlers: [...arabicHandlers, ...free, ...defaultHandlers] } },
}

/** No theme of its own yet: the workspace uses Whity's standard. */
export const NoWorkspaceTheme: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/document-themes", () =>
          HttpResponse.json({ data: [{ ...THEME, ref: "whity.standard", scope: "system", key: "standard", default_for: ["workspace"] }] })
        ),
        ...trial,
        ...defaultHandlers,
      ],
    },
  },
}
