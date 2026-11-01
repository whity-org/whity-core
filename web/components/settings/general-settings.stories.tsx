import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import AdminSettingsPage from "@/app/(protected)/admin/settings/page"
import { arabicHandlers, withArabic } from "../../.storybook/arabic"
import { capabilitiesHandler, defaultHandlers, http, HttpResponse } from "../../.storybook/mocks"

/**
 * Settings, General: a workspace's own settings, with its company details
 * (WHIT-788), which its documents, letterheads and invoices print.
 */
const pageHandlers = [
  capabilitiesHandler(["settings:read", "settings:write"]),
  http.get("*/api/v1/settings/tabs", () => HttpResponse.json({ data: [{ id: "general", href: "/admin/settings", label: "General" }] })),
  http.get("*/api/v1/settings", () =>
    HttpResponse.json({
      data: {
        effective: {
          site_name: "Example Bakery",
          timezone: "Asia/Amman",
          support_email: "help@bakery.example",
          "ui.hide_dates": "false",
          "company.name": "Example Bakery",
          "company.address": "1 Bread Street, Amman",
          "company.tax_id": "JO-1234567",
          "company.phone": "+962 6 555 0100",
          "company.email": "",
        },
        registry: [
          { key: "ui.hide_dates", type: "bool", default: "false" },
          { key: "company.name", type: "string", default: "" },
          { key: "company.address", type: "string", default: "" },
          { key: "company.tax_id", type: "string", default: "" },
          { key: "company.phone", type: "string", default: "" },
          { key: "company.email", type: "string", default: "" },
        ],
        overridden: ["site_name", "company.name", "company.address", "company.tax_id", "company.phone"],
        tenant_overridable: true,
      },
    })
  ),
]

const meta = {
  title: "Settings/General",
  component: AdminSettingsPage,
  parameters: { nextjs: { appDirectory: true }, msw: { handlers: [...pageHandlers, ...defaultHandlers] } },
} satisfies Meta<typeof AdminSettingsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Arabic: Story = {
  decorators: [withArabic],
  parameters: { msw: { handlers: [...arabicHandlers, ...pageHandlers, ...defaultHandlers] } },
}
