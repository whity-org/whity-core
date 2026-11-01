import { MCP_CLIENTS, mcpClient, mcpEndpoint, mcpServerName, recommendsToolSearch, type McpClientId } from '../connect-clients'

/**
 * Expected values are written out by hand, or recovered by decoding what the
 * module produced — never rebuilt with the module's own helpers, which would
 * only prove the module agrees with itself.
 */
const CONNECTION = {
  name: 'Acme Cursor',
  url: 'https://api.acme.test/mcp',
  token: 'eyJhbGciOiJIUzI1NiJ9.eyJqdGkiOiJ4In0.sig-_part',
}
const AUTH = 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJqdGkiOiJ4In0.sig-_part'

function setup(id: McpClientId) {
  const spec = mcpClient(id)
  if (spec.setup === undefined) throw new Error(`${id} has no setup`)
  return spec.setup(CONNECTION)
}

describe('mcpServerName', () => {
  it('reduces a display name to a slug every client accepts', () => {
    expect(mcpServerName('Acme Cursor')).toBe('acme-cursor')
    expect(mcpServerName('  Ops / Prod: v2 ')).toBe('ops-prod-v2')
    expect(mcpServerName('Café')).toBe('cafe')
  })

  it('never returns an empty name', () => {
    expect(mcpServerName('مساعد')).toBe('whity')
    expect(mcpServerName('')).toBe('whity')
  })

  it('stays short and never ends on a dash', () => {
    const slug = mcpServerName('a'.repeat(47) + ' b')
    expect(slug.length).toBeLessThanOrEqual(48)
    expect(slug.endsWith('-')).toBe(false)
  })
})

describe('MCP_CLIENTS', () => {
  it('has unique ids, a docs link each, and a setup for every app that does not need sign-in', () => {
    const ids = MCP_CLIENTS.map((c) => c.id)
    expect(new Set(ids).size).toBe(ids.length)
    for (const client of MCP_CLIENTS) {
      expect(client.docs).toMatch(/^https:\/\//)
      expect(client.requiresSignIn === true ? client.setup : typeof client.setup).toBe(
        client.requiresSignIn === true ? undefined : 'function'
      )
    }
  })

  it('offers Claude and ChatGPT as sign-in only, until the OAuth track lands', () => {
    expect(mcpClient('claude').requiresSignIn).toBe(true)
    expect(mcpClient('chatgpt').requiresSignIn).toBe(true)
  })

  it('throws on an unknown id', () => {
    expect(() => mcpClient('nope' as McpClientId)).toThrow('unknown MCP client')
  })
})

describe('Claude Code', () => {
  it('builds the claude mcp add command', () => {
    expect(setup('claude-code').command?.text).toBe(
      `claude mcp add --transport http acme-cursor "https://api.acme.test/mcp" --header "Authorization: ${AUTH}"`
    )
  })

  it('builds a .mcp.json entry', () => {
    const file = setup('claude-code').file
    expect(file?.paths).toEqual(['.mcp.json'])
    expect(JSON.parse(file?.text ?? '')).toEqual({
      mcpServers: { 'acme-cursor': { type: 'http', url: CONNECTION.url, headers: { Authorization: AUTH } } },
    })
  })
})

describe('Cursor', () => {
  it('builds an install link whose config decodes to the server object', () => {
    const install = setup('cursor').install
    expect(install?.verified).toBe(false)

    const url = new URL(install?.href ?? '')
    expect(url.protocol).toBe('cursor:')
    expect(url.searchParams.get('name')).toBe('acme-cursor')
    const decoded = JSON.parse(Buffer.from(url.searchParams.get('config') ?? '', 'base64').toString('utf8'))
    expect(decoded).toEqual({ url: CONNECTION.url, headers: { Authorization: AUTH } })
  })

  it('builds an mcp.json entry', () => {
    expect(JSON.parse(setup('cursor').file?.text ?? '')).toEqual({
      mcpServers: { 'acme-cursor': { url: CONNECTION.url, headers: { Authorization: AUTH } } },
    })
  })
})

describe('VS Code', () => {
  it('builds an install link carrying the named server', () => {
    const href = setup('vscode').install?.href ?? ''
    expect(href.startsWith('vscode:mcp/install?')).toBe(true)
    expect(JSON.parse(decodeURIComponent(href.slice('vscode:mcp/install?'.length)))).toEqual({
      name: 'acme-cursor',
      type: 'http',
      url: CONNECTION.url,
      headers: { Authorization: AUTH },
    })
  })

  it('uses the servers key in .vscode/mcp.json', () => {
    expect(JSON.parse(setup('vscode').file?.text ?? '')).toEqual({
      servers: { 'acme-cursor': { type: 'http', url: CONNECTION.url, headers: { Authorization: AUTH } } },
    })
  })
})

describe('Windsurf', () => {
  it('uses serverUrl', () => {
    expect(JSON.parse(setup('windsurf').file?.text ?? '')).toEqual({
      mcpServers: { 'acme-cursor': { serverUrl: CONNECTION.url, headers: { Authorization: AUTH } } },
    })
  })
})

describe('Codex CLI', () => {
  it('builds a config.toml table', () => {
    const file = setup('codex').file
    expect(file?.language).toBe('toml')
    expect(file?.text).toBe(
      '[mcp_servers.acme-cursor]\n' +
        'url = "https://api.acme.test/mcp"\n' +
        `http_headers = { "Authorization" = "${AUTH}" }\n`
    )
  })
})

describe('Gemini CLI', () => {
  it('builds the gemini mcp add command', () => {
    expect(setup('gemini-cli').command?.text).toBe(
      `gemini mcp add --transport http --scope user --header "Authorization: ${AUTH}" acme-cursor "https://api.acme.test/mcp"`
    )
  })

  it('uses httpUrl, which Gemini reads as streamable HTTP', () => {
    expect(JSON.parse(setup('gemini-cli').file?.text ?? '')).toEqual({
      mcpServers: { 'acme-cursor': { httpUrl: CONNECTION.url, headers: { Authorization: AUTH } } },
    })
  })
})

describe('n8n', () => {
  it('gives the form fields, with the bare token since n8n adds the Bearer prefix', () => {
    expect(setup('n8n').fields).toEqual([
      { key: 'endpoint', value: CONNECTION.url },
      { key: 'transport', value: 'HTTP Streamable' },
      { key: 'authentication', value: 'Bearer Auth' },
      { key: 'token', value: CONNECTION.token, secret: true },
    ])
  })
})

describe('Other', () => {
  it('gives the raw endpoint and header, and a generic config', () => {
    const result = setup('other')
    expect(result.fields).toContainEqual({ key: 'headerValue', value: AUTH, secret: true })
    expect(JSON.parse(result.file?.text ?? '')).toEqual({
      mcpServers: { 'acme-cursor': { type: 'http', url: CONNECTION.url, headers: { Authorization: AUTH } } },
    })
  })
})

describe('quoting', () => {
  it('keeps a hostile URL inside its shell argument and TOML string', () => {
    const spec = mcpClient('claude-code')
    const command = spec.setup?.({ ...CONNECTION, url: 'https://x.test/mcp"; rm -rf ~ #$HOME' }).command?.text ?? ''
    expect(command).toContain('"https://x.test/mcp\\"; rm -rf ~ #\\$HOME"')

    const toml = mcpClient('codex').setup?.({ ...CONNECTION, url: 'https://x.test/"a"\\b' }).file?.text ?? ''
    expect(toml).toContain('url = "https://x.test/\\"a\\"\\\\b"')
  })
})

describe('tool search (WHIT-707)', () => {
  it('is recommended when the access exceeds the app limit or the general budget', () => {
    expect(recommendsToolSearch(mcpClient('cursor'), 40)).toBe(false)
    expect(recommendsToolSearch(mcpClient('cursor'), 41)).toBe(true)
    expect(recommendsToolSearch(mcpClient('claude-code'), 60)).toBe(false)
    expect(recommendsToolSearch(mcpClient('claude-code'), 313)).toBe(true)
    // An app reported to hold more still gets the general budget.
    expect(recommendsToolSearch(mcpClient('windsurf'), 80)).toBe(true)
  })

  it('appends tools=full only when asked, keeping any existing query', () => {
    expect(mcpEndpoint('https://api.acme.test/mcp')).toBe('https://api.acme.test/mcp')
    expect(mcpEndpoint('https://api.acme.test/mcp', { allTools: true })).toBe('https://api.acme.test/mcp?tools=full')
    expect(mcpEndpoint('https://api.acme.test/mcp?x=1', { allTools: true })).toBe('https://api.acme.test/mcp?x=1&tools=full')
  })
})

describe('local support (whity-agent, WHIT-823)', () => {
  const ENDPOINT = { name: 'Example Bakery', url: 'https://app.whity.dev/mcp' }

  it('never carries a token, a header or a secret', () => {
    for (const client of MCP_CLIENTS) {
      if (client.local === undefined) continue
      const { addCommand, ...data } = client.local
      const text = JSON.stringify(data) + JSON.stringify(addCommand?.(ENDPOINT) ?? [])
      expect(text).not.toMatch(/bearer|authorization|token|header/i)
    }
  })

  it('adds the server with the app\'s own command, URL only', () => {
    expect(mcpClient('claude-code').local?.addCommand?.(ENDPOINT)).toEqual([
      'claude', 'mcp', 'add', '--scope', 'user', '--transport', 'http', 'example-bakery', 'https://app.whity.dev/mcp',
    ])
    expect(mcpClient('codex').local?.addCommand?.(ENDPOINT)).toEqual([
      'codex', 'mcp', 'add', 'example-bakery', '--url', 'https://app.whity.dev/mcp',
    ])
    const vscode = mcpClient('vscode').local?.addCommand?.(ENDPOINT) ?? []
    expect(vscode.slice(0, 2)).toEqual(['code', '--add-mcp'])
    expect(JSON.parse(vscode[2])).toEqual({ name: 'example-bakery', type: 'http', url: 'https://app.whity.dev/mcp' })
  })

  it('says how each app is connected: its own sign-in, or the bridge', () => {
    const local = Object.fromEntries(MCP_CLIENTS.filter((c) => c.local !== undefined).map((c) => [c.id, c.local!.signsInItself]))
    expect(local).toEqual({
      'claude-code': true,
      cursor: true,
      vscode: true,
      windsurf: false,
      codex: true,
      'gemini-cli': true,
      claude: false,
    })
    for (const client of MCP_CLIENTS) {
      if (client.local !== undefined && !client.local.signsInItself) expect(client.local.stdio).toBe(true)
    }
  })

  it('names config files per OS, and is marked unverified until whity-agent checks it', () => {
    expect(mcpClient('claude').local?.configPaths?.linux).toBeUndefined()
    expect(mcpClient('vscode').local?.configPaths?.win32).toEqual(['%APPDATA%\\Code\\User\\mcp.json'])
    for (const client of MCP_CLIENTS) {
      if (client.local !== undefined) expect(client.local.verified).toBe(false)
    }
  })
})
