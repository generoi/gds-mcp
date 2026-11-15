# GDS MCP

WordPress MCP abilities plugin for content management, translations, and forms. Exposes WordPress functionality as [MCP tools](https://modelcontextprotocol.io) that AI assistants (Claude Desktop, etc.) can use to manage content on behalf of editors.

Built on the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) and [Abilities API](https://github.com/WordPress/abilities-api).

## Requirements

- PHP >= 8.2
- WordPress >= 7.0
- [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/), as an active plugin or the `wordpress/mcp-adapter` Composer library (required for MCP HTTP/STDIO transport)

## Installation

```bash
composer require generoi/gds-mcp
wp plugin activate gds-mcp
```

Install the MCP adapter as a WordPress plugin (`wp plugin install mcp-adapter --activate`) or as the `wordpress/mcp-adapter` Composer library. `gds-mcp` does not declare `Requires Plugins: mcp-adapter`, because WordPress would then refuse to activate it on sites that load the adapter as a library. If the adapter's classes are present but it is not booted, `gds-mcp` boots it through `WP\MCP\Plugin`, so host projects do not need a separate adapter MU-plugin. If the adapter is missing, an admin notice says so.

## Architecture

CRUD abilities delegate to the WordPress REST API via `rest_do_request()` — a pure PHP call that works in HTTP, STDIO, and CLI contexts. Input/output schemas are pulled dynamically from REST route registrations. Permissions are handled by the REST API itself.

Custom abilities (duplication, block patching, translations, etc.) handle operations without REST equivalents, returning REST responses where possible for consistency.

## Abilities

### REST-Delegated CRUD (all `show_in_rest` post types and taxonomies)

| Abilities | Description |
|-----------|-------------|
| `gds/content-list`, `-read`, `-create`, `-update`, `-delete` | Any post type, picked with `type` (its REST base: `pages`, `posts`, `media`, `blocks`, `templates`, …). The list returns `{posts, total, pages}`; create and update take ACF values in `fields` |
| `gds/terms-list`, `-read`, `-create`, `-update`, `-delete` | Any taxonomy, picked with `taxonomy` (its REST base: `categories`, `tags`, `menus`, …). The list returns `{terms, total, pages}` |
| `gds/nav-menu-items-list`, `-read`, `-create`, `-update`, `-delete`, `-move`, `-reorder` | Menu items, with sibling positions handled server-side. Menus themselves are `gds/terms-list` with `taxonomy: menus` |

Other REST parameters pass through — use `_fields`, `search`, `per_page`, `page`, `slug`, `include`, `orderby`, `lang`, etc.

### Custom Abilities (always available)

| Ability | Description |
|---------|-------------|
| `gds/help` | Grouped summary of all available tools and resources |
| `gds/posts-duplicate` | Clone a post with content, meta, terms, and featured image |
| `gds/posts-bulk-update` | Update status or meta across multiple posts (supports dry run) |
| `gds/revisions-list` | List revisions for a post (REST-delegated) |
| `gds/revisions-read` | Read a single revision (REST-delegated) |
| `gds/revisions-restore` | Restore a post to a previous revision |
| `gds/blocks-get` | Block details with attributes, supports, and real-world usage examples from published posts |
| `gds/blocks-patch` | Update specific blocks within a post without replacing full content |

### Block markup checks

`gds/content-create`, `gds/content-update` and `gds/blocks-patch` check the block markup they write, so markup that saves but renders nothing does not pass as a success.

Refused (the call returns an error and nothing is saved):

- Block names missing from the PHP block registry, with the closest registered name. A name that already appears in saved content passes, since blocks registered only in JavaScript and blocks of inactive plugins are not in the registry.
- `core/missing`, the editor's placeholder for an unregistered block.
- Inner blocks or HTML inside `core/block` (a synced pattern renders its `wp_block` post and discards them), including a `blocks-patch` of `inner_blocks`, `inner_html` or a prepend/append on a `core/block`. Edit the `wp_block` post its `ref` points to instead.

Warned about, in a `warnings` array in the result (present only when there are warnings):

- Attribute keys the block type does not define (block-supports attributes count), and values that fail its type or enum.
- Raw `style` values equal to a theme.json preset: colors, gradients, border color, font size and spacing. They are reported, never rewritten, because the preset attributes also change a static block's saved classes.
- A `blocks-patch` attribute that is saved in the block's HTML but could not be synced there (unsupported selector, or no matching element). Patch `inner_html` too.

### Resources (always available)

| Ability | URI | Description |
|---------|-----|-------------|
| `gds/block-types-list` | `blocks://catalog` | All registered block types (REST-delegated to /wp/v2/block-types) |
| `gds/site-map` | `site://pages` | Site structure from navigation menu + disconnected pages |
| `gds/design-theme-json` | `theme://json` | Design tokens from theme.json + resolved CSS custom properties |

### ACF (when active)

| Ability | URI | Description |
|---------|-----|-------------|
| `gds/acf-fields` | `acf://fields` | ACF field groups with fields, types, and post type assignments |

ACF fields are also included in REST responses for post types via the `acf` field, so `gds/content-read` returns them. Write them with the `fields` input of `gds/content-create` / `gds/content-update`.

### Polylang (when active)

| Ability | Description |
|---------|-------------|
| `gds/languages-list` | List languages (REST-delegated to /pll/v1/languages) |
| `gds/translations-create` | Create a translated post linked via Polylang, keeping author and featured image; optional `seo` fields |
| `gds/translations-create-term` | Create a translated taxonomy term |
| `gds/translations-audit` | Audit content for missing translations |
| `gds/strings-list` | List Polylang string translations |
| `gds/strings-update` | Update a string translation |
| `gds/translations-machine` | Machine-translate via DeepL (Polylang Pro) |

Polylang also adds `lang` and `translations` fields to all REST responses automatically.

### Gravity Forms (when active, REST API must be enabled)

| Ability | Description |
|---------|-------------|
| `gds/forms-list` | List forms (REST-delegated to /gf/v2/forms) |
| `gds/forms-read` | Read a form with fields |
| `gds/forms-create` | Create a form |
| `gds/forms-entries` | List form submissions |

### Cache (sage-cachetags)

| Ability | Description |
|---------|-------------|
| `gds/cache-clear` | Flush site cache or purge by tag |

### Yoast SEO (when active)

| Ability | Description |
|---------|-------------|
| `gds/seo-read` | Read a post's or term's SEO title, meta description, focus keyphrase, canonical, robots, cornerstone, breadcrumb title, primary category and social (Open Graph / X) title, description and image — stored values plus what Yoast outputs |
| `gds/seo-update` | Update any of those fields; empty string or `0` clears a field. Undoable |

### Redirects (Safe Redirect Manager / Redirection / Yoast)

| Ability | Description |
|---------|-------------|
| `gds/redirects-manage` | List or create URL redirects (auto-detects plugin) |

### Stream (when active)

| Ability | Description |
|---------|-------------|
| `gds/activity-query` | Query the activity log |

## Connecting

`gds-mcp` registers WordPress abilities. The `wordpress/mcp-adapter` plugin exposes those abilities as an MCP server.

### STDIO (developers with shell access)

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "ddev",
      "args": ["wp", "mcp-adapter", "serve", "--server=mcp-adapter-default-server", "--user=admin"]
    }
  }
}
```

### OAuth (production, remote access — recommended)

With OAuth enabled, anyone adds the site as a **custom connector** by URL alone: no application password, no base64, no `npx`. Each person signs in with their own WordPress account and gets a token carrying their own capabilities. It works in Claude Desktop, claude.ai, mobile and Claude Code.

**1. Enable it** — the module is off unless the site says otherwise:

```php
// Bedrock config/application.php
Config::define('GDS_MCP_OAUTH', env('GDS_MCP_OAUTH') ?? true);

// Or plain WordPress, in wp-config.php
define('GDS_MCP_OAUTH', true);
```

**2. Check discovery answers** — a client that cannot read these never starts:

```bash
curl https://example.com/.well-known/oauth-authorization-server
curl https://example.com/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server
```

**3. Connect** — in Claude, add a custom connector pointing at `https://example.com/wp-json/mcp/mcp-adapter-default-server`. Claude registers itself, sends the person to `wp-login.php` if needed, and shows a consent screen. Claude Code takes the same URL:

```bash
claude mcp add -s local --transport http my-site https://example.com/wp-json/mcp/mcp-adapter-default-server
```

#### Connecting, for the person doing it

Send them this, with the URL filled in. They need nothing from you but a WordPress account on the site.

1. In Claude, open **Settings → Connectors** and choose **Add custom connector** (in Claude Desktop it's the same screen; on claude.ai it's under your profile menu).
2. Paste the site's MCP URL — `https://example.com/wp-json/mcp/mcp-adapter-default-server` — give it a name, and click **Add**.
3. Claude opens the site in a browser window. Sign in with your normal WordPress account if you are not already, then check the page that appears: it names the application asking, the account you are signed in as, and where it will send you back. Click **Allow access**.
4. That's it — the connector shows as connected, and Claude can read and write whatever your account can, including drafts and private posts.

Two things worth knowing:

- **You are connecting as yourself.** Claude gets exactly your permissions, no more. If you cannot edit something in wp-admin, Claude cannot either.
- **You can disconnect at any time**, from either end: remove the connector in Claude, or open **Users → MCP connections** in wp-admin and click **Revoke**. Revoking takes effect immediately.

If step 3 says your account is not allowed to connect, ask a site administrator — connecting needs the same permission as editing posts.

**Requirements**

- HTTPS (localhost and `local`/`development` environments are exempt)
- Pretty permalinks: the authorization, token and registration endpoints are paths under `/mcp-oauth/`, and a site with plain permalinks has no rewrite rules to route them
- `/.well-known/` must reach WordPress. The common nginx recipe denies every dotted path (`location ~ /\. { deny all; }`) — see `.ddev/nginx/well-known.conf` in the kaskipuu project for the override. Kinsta passes it through as-is.

**Allowed callback hosts**

Client registration is open to anyone, and the consent screen shows whatever name a client gave itself. So a client may only register a callback on a host this site allows (plus loopback, which lands on the person's own machine). Others get `invalid_redirect_uri` naming the refused host. The defaults are the clients known to work:

| Client | Callback host |
| --- | --- |
| Claude (claude.ai, Desktop, mobile) | `claude.ai`, `claude.com` |
| Claude Code, VS Code | loopback; `vscode.dev` |
| ChatGPT | `chatgpt.com` |
| Microsoft Copilot Studio | `*.consent.azure-apim.net` |
| Microsoft 365 Copilot | `teams.microsoft.com` |

To allow only some of them, or another client, define the list (it replaces the defaults; `*.example.com` matches subdomains):

```php
Config::define('GDS_MCP_OAUTH_REDIRECT_HOSTS', ['*.consent.azure-apim.net']);
```

or filter `gds-mcp/oauth_redirect_hosts`. A host taken off the list stops working for clients already registered on it. The list only stops callbacks to someone else's server: someone with their own account on an allowed platform can still register a connector there, so the consent screen shows the callback host prominently, and people should still check it.

**Managing access**

- **Users → MCP connections** lists connected applications and revokes them. Revoking kills that grant's access and refresh tokens immediately. Everyone sees their own; anyone who can manage users sees all.
- Connecting requires `edit_posts` by default — filter `gds-mcp/oauth_capability` to change it. The same capability is the MCP endpoint's floor on every site, OAuth or not (it raises the adapter's default `read`; a capability another plugin set is kept). What a token may then *do* is governed per ability by `CapabilityPolicy`, exactly as for a cookie session.
- **Enabling OAuth raises the MCP endpoint's own floor to that same capability**, for every client including ones using an application password. The adapter's default is `read`, which would otherwise let a subscriber reach the endpoint the consent screen refuses them.
- Deactivating the plugin revokes every connection, and uninstalling removes the grants, refresh tokens and client registrations. Deactivation is a real revocation, not a pause.
- Access tokens last an hour, refresh tokens 30 days and rotate on every use. Tokens are stored hashed and are bound to the MCP endpoint they were issued for, so a leaked token is not a general-purpose site credential.

### Read-only mode

Define `GDS_MCP_READ_ONLY` as true (or filter `gds-mcp/read_only`) to unregister every ability not annotated `readonly => true`, an ability with no annotation included, whichever plugin registered it. The write tools are then gone from every MCP server, core's `/wp-abilities/v1` routes, gds-assistant and `gds/help` alike; one registered later can't run, and gds-assistant's undo is refused. Only the MCP adapter's own `mcp-adapter/*` dispatchers stay.

```php
Config::define('GDS_MCP_READ_ONLY', env('GDS_MCP_READ_ONLY') ?? false);
```

### Remote clients: mail and web fetch

gds-assistant asks the person before every `gds/mail-send` and before `gds/web-fetch` reaches a host they have not trusted. A remote client has no such step, so a prompt injection in something it reads (a form entry, a fetched page) could have it mail data out, or put data into a URL. For a remote client, meaning an MCP request, an OAuth token or an application password:

- `gds/mail-send` is refused.
- `gds/web-fetch` is refused unless the site lists the hosts it may fetch from. Every redirect hop has to be on the list too. Read-only mode keeps web-fetch, so this matters there as well.

```php
add_filter('gds-mcp/web_fetch_allowed_hosts', fn () => ['example.com', '*.example.com']);
```

The same list, when set, also applies in gds-assistant.

### Gravity Forms feed secrets

`gds/feeds-*` never return the value of a feed setting Gravity Forms encrypts (an API key or the like). Such a value reads as `********`, and the feed's `masked` array lists its keys. Sent back unchanged to feeds-update, the mask keeps the stored value; feeds-create leaves that setting unset; any other value replaces it. feeds-duplicate copies the real value. Add other secret keys with the `gds-mcp/feed_secret_fields` filter (`fn (array $keys, string $addonSlug) => [...$keys, 'token']`).

### HTTP with an application password

Still the simplest option for a shared machine-to-machine credential, or where OAuth cannot be enabled.

An application password is not bound to the MCP endpoint the way an OAuth token is: it works on every REST route the account can reach, core's `/wp-abilities/v1` routes included. Treat it as a full login for that account, and prefer OAuth for anything a third-party service holds.

**1. Create an application password:**

```bash
# Via WP-CLI
wp user application-password create USERNAME yourname-claude-mcp --porcelain

# Or via WP Admin: Users > Profile > Application Passwords
```

**2. Claude Code:**

```bash
AUTH=$(echo -n 'USERNAME:YOUR_APP_PASSWORD' | base64)
claude mcp add-json -s local my-site \
  "{\"type\":\"http\",\"url\":\"https://example.com/wp-json/mcp/mcp-adapter-default-server\",\"headers\":{\"Authorization\":\"Basic $AUTH\"}}"
```

**3. Claude Desktop:**

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "USERNAME",
        "WP_API_PASSWORD": "xxxx-xxxx-xxxx-xxxx"
      }
    }
  }
}
```

## Upgrade notes

- **0.8.2:** `gds/posts-bulk-update` `set_meta` values are now run through kses for users without `unfiltered_html`, as post content already was. Besides stripping disallowed HTML, kses encodes a bare `&` as `&amp;`, so meta that relied on a raw `&` (URLs with query strings, for example) is stored encoded for those users.
- **After 0.8.2:** web-fetch hosts trusted with "Approve & trust" are kept per user (or site-wide when an administrator trusts them). The old shared list (`gds_mcp_trusted_web_hosts`) recorded no owner, so it is deleted on upgrade rather than carried over: each user re-trusts their hosts once.
- **Unreleased:** OAuth clients may only use callbacks on the allowed hosts ("Allowed callback hosts" above); a connection through any other host has to be allowed and made again. Remote clients can no longer send mail, and fetch only from `gds-mcp/web_fetch_allowed_hosts`. `gds/posts-bulk-update` and `gds/translations-audit` take only the post types the content-* tools list and no internal statuses. Feed secrets read as `********` (see "Gravity Forms feed secrets").

## Development

```bash
composer install
composer lint        # Check code style
composer lint:fix    # Fix code style
```

## Testing

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli wp plugin activate gds-mcp polylang-pro gravityforms wordpress-seo safe-redirect-manager redirection stream
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/gds-mcp vendor/bin/phpunit
npx @wordpress/env stop
```

## License

MIT
