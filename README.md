# GDS MCP

WordPress MCP abilities plugin for content management, translations, and forms. Exposes WordPress functionality as [MCP tools](https://modelcontextprotocol.io) that AI assistants (Claude Desktop, etc.) can use to manage content on behalf of editors.

Built on the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) and [Abilities API](https://github.com/WordPress/abilities-api).

## Requirements

- PHP >= 8.0
- WordPress >= 7.0
- [wordpress/mcp-adapter](https://github.com/WordPress/mcp-adapter) (required for MCP HTTP/STDIO transport)

## Installation

```bash
composer require generoi/gds-mcp
wp plugin activate gds-mcp
```

Install and activate the MCP adapter as a normal WordPress plugin or Composer-managed dependency. `gds-mcp` bootstraps the adapter when the adapter class is present, so host projects do not need a separate adapter MU-plugin.

## Architecture

CRUD abilities delegate to the WordPress REST API via `rest_do_request()` — a pure PHP call that works in HTTP, STDIO, and CLI contexts. Input/output schemas are pulled dynamically from REST route registrations. Permissions are handled by the REST API itself.

Custom abilities (duplication, block patching, translations, etc.) handle operations without REST equivalents, returning REST responses where possible for consistency.

## Abilities

### REST-Delegated CRUD (auto-registered for all post types and taxonomies)

Post types: `gds/{rest_base}-list`, `gds/{rest_base}-read`, `gds/{rest_base}-create`, `gds/{rest_base}-update`, `gds/{rest_base}-delete`

| Post Type | Abilities |
|-----------|-----------|
| Pages | `gds/pages-list`, `gds/pages-read`, `gds/pages-create`, `gds/pages-update`, `gds/pages-delete` |
| Posts | `gds/posts-list`, `gds/posts-read`, `gds/posts-create`, `gds/posts-update`, `gds/posts-delete` |
| Media | `gds/media-list`, `gds/media-read`, `gds/media-create`, `gds/media-update`, `gds/media-delete` |
| Patterns | `gds/blocks-list`, `gds/blocks-read`, `gds/blocks-create`, `gds/blocks-update`, `gds/blocks-delete` |
| Templates | `gds/templates-list`, etc. |
| Template Parts | `gds/template-parts-list`, etc. |
| Menu Items | `gds/menu-items-list`, etc. |
| Navigation | `gds/navigation-list`, etc. |
| Custom types | Auto-registered for any `show_in_rest` post type |

Taxonomies: `gds/{rest_base}-list`, `gds/{rest_base}-read`, `gds/{rest_base}-create`, `gds/{rest_base}-update`, `gds/{rest_base}-delete`

| Taxonomy | Abilities |
|----------|-----------|
| Categories | `gds/categories-list`, `gds/categories-read`, etc. |
| Tags | `gds/tags-list`, `gds/tags-read`, etc. |
| Menus | `gds/menus-list`, `gds/menus-read`, etc. |
| Custom taxonomies | Auto-registered for any `show_in_rest` taxonomy |

All REST parameters pass through — use `_fields`, `search`, `per_page`, `page`, `slug`, `include`, `orderby`, `lang`, etc.

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

ACF fields are also included in REST responses for post types via the `acf` field.

### Polylang (when active)

| Ability | Description |
|---------|-------------|
| `gds/languages-list` | List languages (REST-delegated to /pll/v1/languages) |
| `gds/translations-create` | Create a translated post linked via Polylang |
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

**Managing access**

- **Users → MCP connections** lists connected applications and revokes them. Revoking kills that grant's access and refresh tokens immediately. Everyone sees their own; anyone who can manage users sees all.
- Connecting requires `edit_posts` by default — filter `gds-mcp/oauth_capability` to change it. What a token may then *do* is governed per ability by `CapabilityPolicy`, exactly as for a cookie session.
- **Enabling OAuth raises the MCP endpoint's own floor to that same capability**, for every client including ones using an application password. The adapter's default is `read`, which would otherwise let a subscriber reach the endpoint the consent screen refuses them.
- Deactivating the plugin revokes every connection, and uninstalling removes the grants, refresh tokens and client registrations. Deactivation is a real revocation, not a pause.
- Access tokens last an hour, refresh tokens 30 days and rotate on every use. Tokens are stored hashed and are bound to the MCP endpoint they were issued for, so a leaked token is not a general-purpose site credential.

### HTTP with an application password

Still the simplest option for a shared machine-to-machine credential, or where OAuth cannot be enabled.

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
