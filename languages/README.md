# Translations

CatalogOps is fully translatable. The PHP strings load from a `.mo` via
`load_plugin_textdomain()`; the React app's strings (the `__()` calls in
`assets/src/admin/index.js`) load from a `.json` via
`wp_set_script_translations()`.

Files here:

| File | Purpose |
|---|---|
| `catalogops.pot` | Template — every translatable string. Give this to translators. |
| `catalogops-<locale>.po` | A locale's translations (editable, e.g. with Poedit). |
| `catalogops-<locale>.mo` | Compiled PHP translations (loaded at runtime). |
| `catalogops-<locale>-<md5>.json` | Compiled JS translations (loaded at runtime). |

`sr_RS` ships as a starter translation (the onboarding, the backup gate, and the
core controls); untranslated strings fall back to English.

## Regenerating

Run from the plugin root with WP-CLI on PATH.

```bash
# 1. Refresh the template from source (PHP + JS/JSX).
wp i18n make-pot . languages/catalogops.pot \
  --slug=catalogops --domain=catalogops \
  --exclude=vendor,node_modules,tests,docker,bin,assets/dist

# 2. Translate: edit languages/catalogops-<locale>.po (copy the .pot to start).

# 3. Compile the PHP .mo and the JS .json.
wp i18n make-mo   languages/ languages/
wp i18n make-json languages/ --no-purge --pretty-print
```

### The JS `.json` filename

`wp i18n make-json` names the JSON after the **source** file
(`md5('assets/src/admin/index.js')`), but `wp_set_script_translations()` looks it
up by the **enqueued** script's path, relative to the plugin root
(`md5('assets/dist/admin.js')`). So the generated file must be renamed:

```
catalogops-<locale>-<md5('assets/src/admin/index.js')>.json
        →  catalogops-<locale>-<md5('assets/dist/admin.js')>.json
```

For the current bundle path that target md5 is
`e5bd2ec3317781c06f08f3c5478dd567`, i.e. the file is
`catalogops-<locale>-e5bd2ec3317781c06f08f3c5478dd567.json`. The md5 is of the
path, not the contents, so it stays stable across rebuilds. If the bundle path
ever changes, recompute it — mirror of WP core `load_script_textdomain()`:

```php
$src      = plugins_url( 'assets/dist/admin.js', 'catalogops/catalogops.php' );
$rel      = wp_parse_url( $src, PHP_URL_PATH );
$rel      = trim( substr( $rel, strlen( wp_parse_url( content_url(), PHP_URL_PATH ) ) ), '/' );
$relative = implode( '/', array_slice( explode( '/', $rel ), 2 ) ); // assets/dist/admin.js
echo md5( $relative );
```
