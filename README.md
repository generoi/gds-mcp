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

### HTTP (production, remote access)

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

## Host project setup

The host project needs an MCP adapter bootstrap mu-plugin:

```php
<?php
use WP\MCP\Core\McpAdapter;

if (! defined('ABSPATH') || ! class_exists(McpAdapter::class)) {
    return;
}

McpAdapter::instance();

// Expose all gds/ abilities as public MCP tools.
add_filter('wp_register_ability_args', function (array $args, string $name): array {
    if (str_starts_with($name, 'gds/')) {
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

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli wp plugin activate gds-mcp polylang-pro gravityforms wordpress-seo safe-redirect-manager redirection stream
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/gds-mcp vendor/bin/phpunit
npx @wordpress/env stop
```

## License

MIT
