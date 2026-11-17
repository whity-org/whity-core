import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import { http, HttpResponse } from "msw"

import { BrandingSettings } from "./branding-settings"
import { capabilitiesHandler, defaultHandlers } from "../.storybook/mocks"

const meta = {
  title: "App/BrandingSettings",
  component: BrandingSettings,
  tags: ["autodocs"],
  // Saving refreshes the app's branding.
  parameters: { layout: "padded", nextjs: { appDirectory: true } },
} satisfies Meta<typeof BrandingSettings>

export default meta
type Story = StoryObj<typeof meta>

// Save branding PATCHes the tenant or global settings.
const saveSettings = http.patch("*/api/v1/settings*", () => HttpResponse.json({ data: {} }))

const withWrite = [capabilitiesHandler(["settings:write", "settings:manage"]), saveSettings, ...defaultHandlers]

// A workspace admin editing the workspace's own branding.
export const Workspace: Story = {
  args: { scope: "tenant", canWrite: true },
  parameters: { msw: { handlers: withWrite } },
}

// Read-only: no settings:write, so no Save, Upload or Clear.
export const ReadOnly: Story = {
  args: { scope: "tenant", canWrite: false },
  parameters: { msw: { handlers: [capabilitiesHandler([]), ...defaultHandlers] } },
}

// The system tenant: the platform-wide defaults and the web address.
export const SystemTenant: Story = {
  args: { scope: "global", canWrite: true },
  parameters: { msw: { handlers: withWrite } },
}
