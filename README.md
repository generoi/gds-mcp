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
| `gds/list-post-types` | Discover all registered post types with labels and counts |
| `gds/create-post` | Create a new post, page, or custom post type |
| `gds/read-post` | Read a post with content, meta, taxonomy terms, language, and translations |
| `gds/list-posts` | Search and filter posts by type, language, status |
| `gds/update-post-content` | Update title, content, excerpt, status, or slug |
| `gds/search-media` | Search the media library by filename, title, or MIME type |
| `gds/upload-media` | Download a file from URL and add to the media library |
| `gds/list-menus` | List navigation menus with language and location assignments |
| `gds/get-menu` | Get all items in a navigation menu |
| `gds/add-menu-item` | Add an item to a navigation menu |
| `gds/manage-terms` | List, create, or update taxonomy terms |
| `gds/get-block` | Get full details for a block: attributes, supports, styles, example markup from the demo page or published posts |

### Resources (always available)

| Resource | URI | Description |
|----------|-----|-------------|
| `gds/block-catalog` | `blocks://catalog` | Lightweight index of all registered blocks (title, description, category, styles, allowed inner blocks). Use `gds/get-block` for full details and examples. |

### Polylang (when active)

| Ability | Description |
|---------|-------------|
| `gds/create-translation` | Create a translated post linked via Polylang (copies content, meta, terms) |
| `gds/create-term-translation` | Create a translated taxonomy term linked via Polylang |
| `gds/translation-audit` | Audit all content for missing translations across post types |
| `gds/list-string-translations` | List registered Polylang string translations with status per language |
| `gds/update-string-translation` | Update a string translation for a specific language |
| `gds/machine-translate` | Machine-translate a post or string group via DeepL (Polylang Pro) |

### Gravity Forms (when active)

| Ability | Description |
|---------|-------------|
| `gds/list-gravity-forms` | List all forms with entry counts |
| `gds/get-gravity-form` | Get a form with all fields, confirmations, and notifications |
| `gds/get-gravity-form-entries` | Query form submissions with filtering and pagination |
| `gds/create-gravity-form` | Create a form from a structured field definition |

### Yoast SEO (when active)

| Ability | Description |
|---------|-------------|
| `gds/get-seo-meta` | Read SEO title, meta description, focus keyphrase |
| `gds/update-seo-meta` | Update SEO metadata for a post |

### Cache (sage-cachetags)

| Ability | Description |
|---------|-------------|
| `gds/clear-cache` | Flush site cache or purge specific content by cache tag |

### Redirects (Safe Redirect Manager / Redirection / Yoast)

| Ability | Description |
|---------|-------------|
| `gds/manage-redirects` | List or create URL redirects (auto-detects plugin) |

### Stream (when active)

| Ability | Description |
|---------|-------------|
| `gds/query-activity-log` | Query the activity log for recent changes |

## Connecting

### STDIO (developers with shell access)

Configure in Claude Code or Claude Desktop MCP settings:

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

### HTTP (content editors, remote access)

Editors connect via application passwords (Users > Profile > Application Passwords):

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "editor-username",
        "WP_API_PASSWORD": "xxxx-xxxx-xxxx-xxxx"
      }
    }
  }
}
```

## Host project setup

The host project needs the MCP adapter bootstrap mu-plugin to expose abilities. Example `gds-mcp-adapter.php`:

```php
<?php
use WP\MCP\Core\McpAdapter;

if (! defined('ABSPATH') || ! class_exists(McpAdapter::class)) {
    return;
}

McpAdapter::instance();

add_filter('wp_register_ability_args', function (array $args, string $name): array {
    // Add ability names you want to expose via MCP.
    $public = [
        'core/get-site-info',
        'gds/list-post-types',
        'gds/read-post',
        'gds/list-posts',
        // ... add more as needed
    ];

    if (in_array($name, $public, true)) {
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
