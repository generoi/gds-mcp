# GDS MCP

WordPress MCP abilities plugin for content management, translations, and forms. Exposes WordPress functionality as [MCP tools](https://modelcontextprotocol.io) that AI assistants (Claude Desktop, etc.) can use to manage content on behalf of editors.

Built on the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) and [Abilities API](https://github.com/WordPress/abilities-api).

## Requirements

- PHP >= 8.0
- WordPress >= 6.8
- [wordpress/mcp-adapter](https://github.com/WordPress/mcp-adapter) (required)
- [wordpress/abilities-api](https://github.com/WordPress/abilities-api) (required, ships with WP 6.9+)

## Installation

```bash
composer require generoi/gds-mcp
wp plugin activate gds-mcp
```

## Abilities

### Core (always available)

| Ability | Description |
|---------|-------------|
| `gds/post-types-list` | Discover all registered post types with labels and counts |
| `gds/posts-create` | Create a new post, page, or custom post type |
| `gds/posts-read` | Read a post with content, meta, taxonomy terms, language, and translations |
| `gds/posts-list` | Search and filter posts by type, language, status |
| `gds/posts-update` | Update title, content, excerpt, status, or slug |
| `gds/media-search` | Search the media library by filename, title, or MIME type |
| `gds/media-upload` | Download a file from URL and add to the media library |
| `gds/menus-list` | List navigation menus with language and location assignments |
| `gds/menus-get` | Get all items in a navigation menu |
| `gds/menus-add-item` | Add an item to a navigation menu |
| `gds/terms-manage` | List, create, or update taxonomy terms |
| `gds/posts-duplicate` | Clone a post with content, meta, terms, and featured image as a draft |
| `gds/posts-bulk-update` | Update status or meta across multiple posts by query or IDs (supports dry run) |
| `gds/posts-revisions` | List, view, or restore post revisions |
| `gds/posts-delete` | Move a post to trash or permanently delete (force=true) |
| `gds/terms-delete` | Permanently delete a taxonomy term |
| `gds/media-delete` | Permanently delete a media attachment and files |
| `gds/menus-remove-item` | Remove an item from a navigation menu |
| `gds/blocks-get` | Get full details for a block: attributes, supports, styles, example markup from the demo page or published posts |

### Resources (always available)

| Resource | URI | Description |
|----------|-----|-------------|
| `gds/blocks-catalog` | `blocks://catalog` | Lightweight index of all registered blocks with styles, allowed inner blocks, parent constraints |
| `gds/site-map` | `site://pages` | Site structure from the primary navigation menu tree + disconnected pages |
| `gds/design-theme-json` | `theme://json` | Design tokens from theme.json (colors, spacing, font sizes, layout) + resolved CSS custom properties |
| `gds/design-css-vars` | `design://css-vars` | All resolved CSS custom properties from the theme stylesheet |

### ACF (when active)

| Resource | URI | Description |
|----------|-----|-------------|
| `gds/acf-fields` | `acf://fields` | ACF field groups with fields, types, and post type assignments |

Posts read via `gds/posts-read` automatically include structured ACF field data (label, type, value) when ACF is active.

### Polylang (when active)

| Ability | Description |
|---------|-------------|
| `gds/translations-create` | Create a translated post linked via Polylang (copies content, meta, terms) |
| `gds/translations-create-term` | Create a translated taxonomy term linked via Polylang |
| `gds/translations-audit` | Audit all content for missing translations across post types |
| `gds/strings-list` | List registered Polylang string translations with status per language |
| `gds/strings-update` | Update a string translation for a specific language |
| `gds/translations-machine` | Machine-translate a post or string group via DeepL (Polylang Pro) |

### Gravity Forms (when active)

| Ability | Description |
|---------|-------------|
| `gds/forms-list` | List all forms with entry counts |
| `gds/forms-get` | Get a form with all fields, confirmations, and notifications |
| `gds/forms-entries` | Query form submissions with filtering and pagination |
| `gds/forms-create` | Create a form from a structured field definition |

### Yoast SEO (when active)

| Ability | Description |
|---------|-------------|
| `gds/seo-get` | Read SEO title, meta description, focus keyphrase |
| `gds/seo-update` | Update SEO metadata for a post |

### Cache (sage-cachetags)

| Ability | Description |
|---------|-------------|
| `gds/cache-clear` | Flush site cache or purge specific content by cache tag |

### Redirects (Safe Redirect Manager / Redirection / Yoast)

| Ability | Description |
|---------|-------------|
| `gds/redirects-manage` | List or create URL redirects (auto-detects plugin) |

### Stream (when active)

| Ability | Description |
|---------|-------------|
| `gds/activity-query` | Query the activity log for recent changes |

## Connecting

### For developers (STDIO via WP-CLI)

If you have shell access (DDEV, SSH), configure your MCP client to use the STDIO transport:

**Claude Code** (`.claude/mcp.json` in project root):
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

**Claude Desktop** (`~/Library/Application Support/Claude/claude_desktop_config.json` on Mac):
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

### For content editors (HTTP via application password)

Content editors connect remotely using WordPress application passwords. No shell access needed.

#### Step 1: Create an application password

1. Log in to WordPress admin
2. Go to **Users → Profile** (or **Users → All Users** → click your username)
3. Scroll down to **Application Passwords**
4. Enter a name (e.g. "Claude Desktop") and click **Add New Application Password**
5. Copy the generated password — it will only be shown once (format: `xxxx xxxx xxxx xxxx xxxx xxxx`)

> **Note:** Application passwords require HTTPS on production. They work over HTTP only on localhost.

#### Step 2: Configure your AI client

**Claude Desktop** (`~/Library/Application Support/Claude/claude_desktop_config.json`):
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-wordpress-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Replace:
- `yoursite.com` with your WordPress site URL
- `your-wordpress-username` with your WordPress login username
- The password with the application password from Step 1

#### Step 3: Verify

Open Claude Desktop and ask: *"What tools do you have available?"* — it should list the WordPress MCP tools.

Or ask: *"List all pages on the site"* — it should call `gds/posts-list` and return your pages.

#### Permissions

Each ability checks `current_user_can()` — the editor can only do what their WordPress role allows. An Editor can create/edit posts but can't manage plugins or options. An Administrator can do everything.

## Host project setup

The host project needs the MCP adapter bootstrap mu-plugin to expose abilities. Example `gds-mcp-adapter.php`:

```php
<?php
use WP\MCP\Core\McpAdapter;

if (! defined('ABSPATH') || ! class_exists(McpAdapter::class)) {
    return;
}

McpAdapter::instance();

// Expose all gds/ and core/ abilities as public MCP tools.
add_filter('wp_register_ability_args', function (array $args, string $name): array {
    if (str_starts_with($name, 'gds/') || str_starts_with($name, 'core/')) {
        $args['meta']['mcp']['public'] = true;
    }
    return $args;
}, 10, 2);
```

### Disabling destructive operations

To expose everything except delete operations, use a deny-list:

```php
add_filter('wp_register_ability_args', function (array $args, string $name): array {
    if (!str_starts_with($name, 'gds/') && !str_starts_with($name, 'core/')) {
        return $args;
    }

    // Deny destructive operations.
    $denied = [
        'gds/posts-delete',
        'gds/terms-delete',
        'gds/media-delete',
        'gds/menus-remove-item',
    ];

    if (!in_array($name, $denied, true)) {
        $args['meta']['mcp']['public'] = true;
    }

    return $args;
}, 10, 2);
```

## Development

```bash
composer install
composer lint        # Check code style
composer lint:fix    # Fix code style
```

## Testing

Uses [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) for integration testing with WordPress + all integration plugins:

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli wp plugin activate gds-mcp polylang-pro gravityforms wordpress-seo safe-redirect-manager redirection stream
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/gds-mcp vendor/bin/phpunit
npx @wordpress/env stop
```

## License

MIT
