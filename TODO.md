# TODO

## Skipped Tests

### Polylang Pro REST write errors (4 tests)

`PostTypeAbilityTest`: `test_create_post`, `test_update_post`, `test_delete_trashes_by_default`, `test_delete_force`

Polylang Pro's REST response filter (`polylang-pro/modules/rest/rest-post.php:337`) calls `get_new_post_translation_link()` on null during `rest_prepare_{post_type}`. This crashes in the PHPUnit test context because Polylang Pro's internal state (links model) isn't fully initialized when using `rest_do_request()` outside a real HTTP request.

**Root cause:** Polylang Pro assumes a web context in its REST response filter. In tests, `PLL()->links` is null.

**Possible fixes:**
- Initialize Polylang Pro's links model in test setUp (tried `PLL_Links_Default`, didn't resolve nested null)
- Report to Polylang — the REST filter should guard against null `$this->links`
- Run these tests without Polylang Pro loaded (separate phpunit config)

### ACF Pro not in wp-env (15 tests)

`AcfFieldsResourceTest`, `AcfIntegrationTest`

ACF Pro is a commercial plugin not distributable via `.wp-env.json`. Tests skip gracefully when ACF is not available.

**Fix:** Add `../advanced-custom-fields-pro` to `.wp-env.json` plugins array (requires the plugin to be present locally at `web/app/plugins/advanced-custom-fields-pro`).
