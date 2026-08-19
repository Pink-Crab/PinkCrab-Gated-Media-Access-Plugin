# Gated Media Access

Gated access to documents, media and posts. Access is granted by on-site
payment, by an administrator, or by webhook.

A general WordPress plugin, distributed for use on other people's sites. It is
not a shop — payment is one of three ways in.

**Status: the account area is in.** The boot loop, the QA tooling and CI were
first; on top of them now sit the asset pipeline, the account route, the
section extension point and the four account blocks.

Still not built: no post types, no taxonomy, no tables, **no resolver**. That
last one is why the account views render their structure and their empty
states rather than rows — what a person can see is the resolver's answer, and
it is step 2 of `_temp/architecture.md` §12. The settings screen is still an
empty page.

Profile is the exception and is fully working, because it is WordPress user
fields rather than anything waiting on the resolver.

## Requires

| | |
|---|---|
| PHP | **8.3** |
| WordPress | **6.4** |
| [`a8cteam51/restrict-media-file-access`](https://github.com/a8cteam51/restrict-media-file-access) | **v1.4.2**, active |
| Node | **22** (`.nvmrc`), to build assets — not needed at runtime |

Both PHP and WordPress minimums are inherited from the dependency's plugin
header, not chosen here.

**The dependency is hard.** It owns the files — it moves them, serves them and
refuses them; this plugin only supplies the access decision. Without it active,
the plugin shows an admin notice and boots nothing. A plugin that looks alive
while files are served unprotected is worse than one that plainly says it is
not working.

The check is a runtime one on `RESTRICT_MEDIA_FILE_ACCESS_BASENAME` rather than
the `Requires Plugins` header, because core matches that header by
wordpress.org slug and this dependency self-updates from GitHub releases.

## Install

```bash
composer install
nvm use 22 && npm install && npm run build
```

**The build is not optional.** Blocks are registered from `build/blocks`, not
from source, so without it the account area has no blocks to render and the
route answers 404. Nothing fatals — a checkout without a build degrades rather
than breaks — but nothing works either.

## How it boots

```
gated-media-access.php
  └── plugins_loaded
        ├── dependency missing? → admin notice, stop
        └── Plugin::boot()
              ├── Dice builds each class in Plugin::SERVICES
              ├── the ones implementing Hookable register against Hook_Loader
              └── Hook_Loader::register_hooks() attaches everything in one pass
```

Adding a service means adding its class to `Plugin::SERVICES` and implementing
`Hookable`. Nothing constructs its own collaborators — the container resolves
them by type, which is what keeps the pieces testable.

The list is fixed. Third-party code extends through hooks, not by registering
services into it.

**One exception is coming.** The file access filter cannot wait for the boot
loop: files are served on `parse_request`, before the main query, so a filter
added after `init` is never consulted. It will be attached at plugin load and
resolve its service lazily. There is a note on `Plugin::SERVICES` saying so.

## Layout

| Path | |
|---|---|
| `gated-media-access.php` | Plugin header, constants, dependency guard, boot |
| `src/Plugin.php` | The boot loop and the missing-dependency notice |
| `src/Hookable.php` | `register_hooks( Hook_Loader $loader ): void` |
| `src/Account/` | The account area — route, shell, collection, profile writer |
| `src/Account/Sections/` | The four pages, each implementing `Account_Section` |
| `src/Support/` | `Block` composes a block from PHP; `Money` owns the Free rule |
| `src/Assets/Asset_Loader.php` | Registers the four bundles; enqueues none by default |
| `src/Blocks/Block_Registrar.php` | Registers every block in `build/blocks` |
| `src/Settings/Settings_Page.php` | Top-level menu, currently an empty page |
| `assets/scss/` | Tokens, base, the sixteen §6 components, the §7 views |
| `assets/js/` | `front.js`, `admin.js`, and `shared/` pulled into both |
| `assets/icons.svg` | The icon sprite, 24 symbols, inlined into the page |
| `blocks/<name>/` | Twenty blocks — four section views, sixteen §6 components |
| `build/` | wp-scripts output. Gitignored, and required at runtime |
| `webpack.config.js` | Three source trees to three destinations |
| `.wp-env.json` | Local WordPress for e2e, on port 8931 |
| `playwright.config.js` | e2e, run at both sides of the 782px breakpoint |
| `type-defs.php` | Plugin constants declared empty, for static analysis only. **Never loaded at runtime.** |
| `tests/` | Unit and integration suites, and the wp-phpunit bootstrap |
| `.karkinos/workflows/` | Workflows for the local act runner |
| `_temp/` | Working files — design docs and reference clones. Gitignored. |

## The account area

Lives at `/account/`, and at `/account/{section}/` for each section. The slug
comes from `gatedmedia_account_slug` — a filter, not a setting, per the brief.

**It is a virtual page, not a takeover.** The route answers with a page the
theme renders: its header, its navigation, its footer. This is the WooCommerce
My Account model, and it is the only one that behaves on a site whose theme we
have never seen. The content inside is the section's block — the same block an
administrator can place on a page of their own, so the two routes cannot drift.

**One rewrite rule, not one per section.** A rule per section would mean a
third party adding one has no URL until rewrites are flushed. The rule captures
any segment and the section list decides at runtime what is valid, so adding a
section needs no flush, ever. A second segment is captured too, which is what
`/account/orders/{id}` will use.

An unknown section, or one the user may not see, is a **real 404** — status
code and all, not a "not found" page served with 200.

### Adding a section

`gatedmedia_account_sections` is the only place third-party code adds UI. It
filters a `Section_Collection`, and anything implementing `Account_Section` can
go in it. `Section` is a ready-made implementation, so a class of your own is
optional:

```php
add_filter(
    'gatedmedia_account_sections',
    function ( Section_Collection $sections ): Section_Collection {
        return $sections->add(
            new Section(
                slug:       'subscriptions',
                title:      __( 'Subscriptions', 'my-plugin' ),
                menu_label: __( 'Subscriptions', 'my-plugin' ),
                block:      'my-plugin/subscriptions',
                position:   25,
            )
        );
    }
);
```

That is the whole contract. No rewrite rule of your own, no query var, no menu
call, no flush. Reusing an existing slug replaces that section, so a site can
swap ours for its own; `remove()` drops one entirely.

A section always renders **inside our shell** — the sidebar, the tab strip and
the page title are drawn for you and the block fills the main column. Something
wanting the whole page is not a section, it is a page, and it wants an ordinary
WordPress route.

A filter returning the wrong type falls back to our defaults rather than taking
the account area down with it.

### Components

**The sixteen components in `_temp/ui-spec.md` §6 are blocks**, one each, all
PHP-rendered. A section composes them; it does not write markup.

They are hidden from the inserter (`"inserter": false`) — composed
programmatically rather than dragged into a post — but they are ordinary
registered blocks in every other respect, which is what matters:

- Each gets `render_block_gated-media-access/<name>` for free, so a site can
  change how a Row draws without us inventing a filter for it.
- Each renders through `do_blocks()`, so a component behaves identically
  however it got onto the page.
- Each has typed attributes covering the states §6 documents, so a state that
  exists in the spec has a way to be asked for.

`Support\Block::render( $name, $attributes, $inner )` is how server code
composes one. Inner blocks where a component genuinely has children — Row's
aside, Notice's body, the nav's items — attributes where it does not.

```php
Block::render( 'gated-media-access/row', array( 'title' => 'Report.pdf' ),
    Block::render( 'gated-media-access/expiry', array( 'state' => 'soon', 'label' => '3 days' ) )
);
```

`gatedmedia-front` is the stylesheet handle and is public API — a third-party
section renders inside our shell and will declare it as a dependency.

Everything is prefixed `gatedmedia`, in CSS as well as PHP. No abbreviations,
because short prefixes collide.

## Tests

```bash
composer test              # unit + integration
composer coverage          # with a clover report
vendor/bin/phpunit --testsuite unit          # just the fast ones
vendor/bin/phpunit --testsuite integration
```

Copy `tests/.env_sample` to `tests/.env` and set the database credentials. The
integration suite installs a real WordPress through `wp-phpunit`, so it needs a
database; the unit suite does not.

### End to end

```bash
npx wp-env start           # WordPress on :8931, PHP 8.3, the dependency installed
npm run build              # blocks are registered from build/, so this comes first
npm run test:e2e
```

Playwright, against a real WordPress with a real theme. It runs every spec at
**both sides of the 782px breakpoint** — `wide` and `narrow` projects — because
the largest single thing to get wrong in this interface is the reflow.

`WP_BASE_URL` points it somewhere other than wp-env; `WP_USER` and
`WP_PASSWORD` override the wp-env defaults.

These cover what the PHP suites cannot see: that the theme still renders around
us, that a refused URL answers 404 rather than a soft one, that the sidebar and
the tab strip never both show, and that nothing overflows the viewport.

**The bootstrap downloads `restrict-media-file-access` on first run**, from its
public GitHub release, using
`Gin0115\WPUnit_Helpers\WP\WP_Dependencies::install_remote_plugin_from_zip()`.
It lands in `wordpress/wp-content/plugins/`, which is gitignored.

Two things about that are worth knowing before changing it:

- **It has to be the release zip, not composer.** The git tag ships no
  `vendor/` directory, and the plugin's main file returns early when its
  autoloader is missing — so a composer-installed copy defines the constants
  but never reaches `functions.php`, and no `rmfa_*` function exists. The
  release zip is a built artifact and includes `vendor/`.
- **The zip has no top-level folder.** It unpacks straight into
  `wp-content/plugins/`, so the plugin slug is `restrict-media-file-access.php`
  with no directory prefix.

### Writing tests

Unit tests extend `PHPUnit\Framework\TestCase` and carry `@group unit`;
integration tests extend `WP_UnitTestCase` and carry `@group integration`.
Files are `Test_*.php`, classes `Test_*`, and each test method gets a
`@testdox` line written as a statement.

Note that `Hook_Manager::validate_context()` only attaches `admin_action` hooks
when `is_admin()` is true, so a test covering an admin hook has to
`set_current_screen()` first — a plain CLI run will not register it.

## Quality

```bash
composer lint:php          # phpcs, phpstan, phpmd
composer format:php        # phpcbf
```

All three extend the shared rulesets in
[`pink-crab/qa-configs`](https://github.com/Pink-Crab/qa-configs). Prefixes are
pinned to `gatedmedia_`, `GATEDMEDIA_` and `PinkCrab\Gated_Access`; the text
domain is `gated-media-access`.

`restrict-media-file-access` is not autoloadable here, so its symbols come from
[`pinkcrab/restrict-media-file-access_stubs`](https://github.com/Pink-Crab/restrict-media-file-access_stubs)
via `scanFiles`.

## CI

`.karkinos/workflows/wp-6-9.yml` runs on the local act runner — checkout,
composer install, phpcs, phpstan, phpmd, phpunit. It triggers on
`pull_request`, which is how the runner discovers work.

Three things in it are runner-specific and will look wrong out of context:

1. **`WPCompat.pluginFile` is set in `.phpstan.neon`.** `johnbillion/wp-compat`
   otherwise looks for `<cwd>/<basename of cwd>.php` to read `Requires at
   least`. That resolves on a dev box only because the directory happens to be
   named after the plugin; in a runner worktree it looks for `run-41.php` and
   throws an Internal Error.
2. **The test bootstrap requires the plugin by path**, never through
   `activate_plugin()` with a slug. A slug resolves against `WP_PLUGIN_DIR`,
   which depends on where the checkout sits — and when it fails it returns a
   `WP_Error` nobody reads, so the plugin silently never loads.
3. **The mysql service publishes `3306:3306`.** Normally avoided, because it
   takes the runner's global lock and serialises the run. It is unavoidable
   here: act starts the job container with `network="host"` while services join
   the job's bridge network, so a service hostname never resolves from inside
   the job.

## Documentation

The design stage output lives in `_temp/` and is gitignored:

| | |
|---|---|
| `requirements.md` | The brief. Wins any disagreement. |
| `architecture.md` | The shape of the back end. Wins over the specification on structure. |
| `specification.md` | Storage, routes, hook names, capabilities, settings. |
| `ui-spec.md` | The front end — sixteen components, nine views. |
| `designs/`, `mocks/` | The Stitch output, and the spec built as running HTML. |
