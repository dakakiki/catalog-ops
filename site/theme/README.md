# CatalogOps theme

The marketing site for catalog-ops.app, built from the agreed prototype in
`site/index.html`, `site/legal.html` and `site/contact.html`.

## Build

```
npm install
npm run build     # once
npm start         # watch while working
```

Two things come out, and where they land is not arbitrary:

| source | output |
|---|---|
| `assets/src/scss/main.scss` | **`style.css`** in the theme root |
| `assets/src/js/main.js` | `assets/dist/main.js` |

**The stylesheet is built to `style.css` in the theme root, not into
`assets/dist/`.** That is the one file WordPress itself reads — it takes the
theme's name, version and description from the comment at the top of it, and a
theme whose `style.css` is missing or headerless is not listed at all. So
`main.scss` opens with that header as a `/*!` comment, which survives
minification. **Never edit `style.css` by hand**; it is overwritten on every
build.

`style.css` and `assets/dist/` are committed on purpose: a server that deploys by
pulling the repository has no Node on it.

## Where the content lives

Every string on the site is editable, and every field is optional — a page
nobody has edited renders the copy the site was designed with, so an empty
database is a finished site rather than a page of empty headings.

- **Pages** — three ACF field groups, declared in `inc/fields.php` rather than
  drawn in the admin, so they arrive with the templates that read them and are
  in version control. Landing page, Legal documents, Contact page.
- **FAQ** and **Compatibility** are post types (`inc/post-types.php`), ordered by
  dragging. They are the two lists that genuinely grow; everything else is fixed
  by its own layout — three pricing columns, five pipeline stages — and is a
  Group of fields, because adding a fourth column is a change to the design and
  ought to feel like one.
- Lists inside a group are a textarea, one item per line. Plan features use a
  leading `-` for a greyed "not in this plan" row; seat prices read
  `5 sites | $199`; the preview card's rows read `Name | 24.00 | 20.99`.

The theme works without ACF: the fields simply stop being editable, and the page
keeps its shipped copy.

## The privacy position, and what it costs

The site sets **no cookies** and loads **nothing from anybody**. That is what
removes the consent banner, and it is a promise the code has to keep:

- Fonts are **self-hosted** (`assets/fonts/`), never linked from Google. All
  three are variable fonts, so one file covers a whole weight range — asking for
  500, 600 and 700 separately downloads the same file three times.
- `functions.php` removes the emoji script, oEmbed discovery and the block
  library's stylesheet, and closes comments everywhere.
- The contact form's only defence against robots is a **honeypot**. reCAPTCHA
  would put Google back on the page and undo all of the above.
- Analytics are Plausible, printed only once `catalogops_plausible_domain` is
  set — so a development copy reports nothing.

## Deploying, and the copy that can drift

This directory is the source. The development site at
`C:\wamp64\www\catalog-ops.app_WEBSITE_09.2026` holds a **copy**, not a link, so
that switching branches in this repository cannot leave that site without a
theme. The price is that the two drift, and the repository is the one to trust:

```
rsync -a --exclude node_modules site/theme/ \
  /c/wamp64/www/catalog-ops.app_WEBSITE_09.2026/wp-content/themes/catalogops/
```
