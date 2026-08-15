# Gated Media Access

Gated access to documents, media and posts. Access is granted by on-site
payment, by an administrator, or by webhook.

A general WordPress plugin, distributed for use on other people's sites. It is
not a shop — payment is one of three ways in.

**Status: base setup only.** The boot loop, the QA tooling, the test suite and
CI are in place and green. None of the domain is built yet: no post types, no
taxonomy, no tables, no resolver, no blocks. The settings screen is an empty
page.

## Requires

| | |
|---|---|
| PHP | **8.3** |
| WordPress | **6.4** |
| [`a8cteam51/restrict-media-file-access`](https://github.com/a8cteam51/restrict-media-file-access) | **v1.4.2**, active |

Both minimums are inherited from the dependency's plugin header, not chosen
here.

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
```

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
| `src/Settings/Settings_Page.php` | Top-level menu, currently an empty page |
| `type-defs.php` | Plugin constants declared empty, for static analysis only. **Never loaded at runtime.** |
| `tests/` | Unit and integration suites, and the wp-phpunit bootstrap |
| `.karkinos/workflows/` | Workflows for the local act runner |
| `_temp/` | Working files — design docs and reference clones. Gitignored. |

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
