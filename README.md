# Gated Media Access

Gated access to documents, media and posts. Access is granted by on-site
payment, by an administrator, or by webhook.

A general WordPress plugin, distributed for use on other people's sites. It is
not a shop: payment is one of three ways in.

**Status: everything documented below is built.** In build order: the boot
loop, QA tooling and CI; the account area (route, sections, the twenty
blocks); the registrations (post types, statuses, the `gatedmedia_access`
taxonomy, capabilities); `Access_Writer` and the resolver; the restriction
and the file/post boundary; the admin screens (Access list, Add Access, the
per-item metabox, quick edit grants, the profile section, revoke with its
three behaviours, the daily expiry sweep); Stripe checkout with the webhook
and the payments table; the six notification emails; and the Settings screen
with its fields, `revoke_behaviour` control included.

## What it looks like

| | |
|---|---|
| ![My Access](docs/images/account-my-access.png) | ![A product for sale](docs/images/product-for-sale.png) |
| What a person holds, at `/account/` | A product page, at its own UUID URL |
| ![Groups](docs/images/admin-groups.png) | ![The product editor](docs/images/admin-product-editor.png) |
| Groups: what each holds, and who holds it | The product form, one locked block |

Every screen, front and admin, is in [`docs/`](docs/README.md).

## Requires

| | |
|---|---|
| PHP | **8.3** |
| WordPress | **6.4** |
| [`a8cteam51/restrict-media-file-access`](https://github.com/a8cteam51/restrict-media-file-access) | **v1.4.2**, active |
| Node | **22** (`.nvmrc`), to build assets, not needed at runtime |

Both PHP and WordPress minimums are inherited from the dependency's plugin
header, not chosen here.

**The dependency is hard.** It owns the files: it moves them, serves them and
refuses them, and this plugin only supplies the access decision. Without it active,
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
route answers 404. Nothing fatals, but nothing works either.

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
`Hookable`. Nothing constructs its own collaborators: the container resolves
them by type, which is what keeps the pieces testable.

The list is fixed. Third-party code extends through hooks, not by registering
services into it.

**One exception.** The file access filter cannot wait for the boot loop:
files are served on `parse_request`, before the main query, so a filter added
after `init` is never consulted. `File_Boundary` is therefore attached at
plugin load and resolves its service lazily on the first protected-file
request. There is a note on `Plugin::SERVICES` saying so.

## Layout

| Path | |
|---|---|
| `gated-media-access.php` | Plugin header, constants, dependency guard, boot |
| `src/Plugin.php` | The boot loop and the missing-dependency notice |
| `src/Hookable.php` | `register_hooks( Hook_Loader $loader ): void` |
| `src/Registration/` | Post types and statuses, the `gatedmedia_access` taxonomy, capabilities |
| `src/Access/` | The writer and its validator, the resolver and allowed items, restriction, both boundaries, the sweep |
| `src/Admin/` | The admin screens: list, add form, metabox, quick edit, profile section, revoke |
| `src/Account/` | The account area: route, shell, collection, profile writer |
| `src/Account/Sections/` | The four pages, each implementing `Account_Section` |
| `src/Support/` | `Block` composes a block from PHP; `Money` owns the Free rule |
| `src/Assets/Asset_Loader.php` | Registers the four bundles; enqueues none by default |
| `src/Blocks/Block_Registrar.php` | Registers every block in `build/blocks` |
| `src/Settings/` | `Settings_Page` (top-level menu, empty page) and `Settings` (the option, read-only) |
| `assets/scss/` | Tokens, base, the sixteen components, the views |
| `assets/js/` | `front.js`, `admin.js`, and `shared/` pulled into both |
| `assets/icons.svg` | The icon sprite, 24 symbols, inlined into the page |
| `blocks/<name>/` | Twenty blocks: four section views, sixteen components |
| `build/` | wp-scripts output. Gitignored, and required at runtime |
| `webpack.config.js` | Three source trees to three destinations |
| `.wp-env.json` | Local WordPress for e2e, on port 8931 |
| `playwright.config.js` | e2e, run at both sides of the 782px breakpoint |
| `type-defs.php` | Plugin constants declared empty, for static analysis only. **Never loaded at runtime.** |
| `tests/` | Unit and integration suites, and the wp-phpunit bootstrap |
| `.karkinos/workflows/` | Workflows for the local act runner |

## The account area

Screens: [the account area](docs/account-area.md), [buying access](docs/buying.md), [signing in](docs/signing-in.md).

Lives at `/account/`, and at `/account/{section}/` for each section. The slug
comes from `gatedmedia_account_slug`, a filter rather than a setting.
`Support\Account_Url` is the one place that resolves it: `section()` and
`detail()` build every link in, and `Account_Route::slug()` asks it for the
bare segment its rewrite rules need. Renaming the account area renames every
link into it at once.

**It is a virtual page, not a takeover.** The route answers with a page the
theme renders: its header, its navigation, its footer. This is the WooCommerce
My Account model, and it is the only one that behaves on a site whose theme we
have never seen. The content inside is the section's block, the same block an
administrator can place on a page of their own, so the two routes cannot drift.

**One rewrite rule, not one per section.** A rule per section would mean a
third party adding one has no URL until rewrites are flushed. The rule captures
any segment and the section list decides at runtime what is valid, so adding a
section needs no flush, ever. A second segment is captured too, and two
sections use it: `/account/orders/{payment-uuid}` opens one order, and
`/account/my-access/{group-uuid}` opens one group.

**A block answers its own question by filter.** A block's `render.php` cannot
reach the container, so each raises a filter and a class answers it. The block
stays a renderer and the query stays in a service:

| block | filter | answered by |
|---|---|---|
| `my-access` | `gatedmedia_my_access_data` | `Held_Access`, and `Group_Contents` when a group is open |
| `files` | `gatedmedia_files_data` | `Downloadable_Files` |
| `orders` | `gatedmedia_orders_data` | `Order_History` |
| `product-details` | `gatedmedia_product_data` | `Product_Offer` |

Each is named for the question it answers. They were one class called
`View_Data` until it had accumulated three unrelated jobs and a name that
resisted none of them.

An unknown section, or one the user may not see, is a **real 404**, status
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

A section always renders **inside our shell**: the sidebar, the tab strip and
the page title are drawn for you and the block fills the main column. Something
wanting the whole page is not a section, it is a page, and it wants an ordinary
WordPress route.

A filter returning the wrong type falls back to our defaults rather than taking
the account area down with it.

### Components

**The sixteen shared components are blocks**, one each, all PHP-rendered. A
section composes them; it does not write markup.

They are hidden from the inserter (`"inserter": false`) and composed
programmatically, but they are ordinary registered blocks in every other
respect, which is what matters:

- Each gets `render_block_gated-media-access/<name>` for free, so a site can
  change how a Row draws without us inventing a filter for it.
- Each renders through `do_blocks()`, so a component behaves identically
  however it got onto the page.
- Each has typed attributes covering every state it can be in.

`Support\Block::render( $name, $attributes, $inner )` is how server code
composes one. Inner blocks where a component genuinely has children (Row's
aside, Notice's body, the nav's items), attributes where it does not.

```php
Block::render( 'gated-media-access/row', array( 'title' => 'Report.pdf' ),
    Block::render( 'gated-media-access/expiry', array( 'state' => 'soon', 'label' => '3 days' ) )
);
```

`gatedmedia-front` is the stylesheet handle and is public API: a third-party
section renders inside our shell and will declare it as a dependency.

Everything is prefixed `gatedmedia`, in CSS as well as PHP. No abbreviations,
because short prefixes collide.

## The admin screens

Screens: [administration](docs/administration.md).

Native wp-admin: core list tables, no admin framework. Every screen
requires a capability rather than a role, and every capability is filtered
(`gatedmedia_give_access_capability` and its three siblings), so a site
decides who does what without touching us. The plugin's own pages (the tabbed
Settings screen and the payment detail) speak the product editor's designed
language through the `.gatedmedia-admin` classes in `assets/scss/admin.scss`,
whose values mirror the block's `STYLES` map.

**The Access screen is core's list table, re-columned.** The `gatedmedia_access`
post type turned `show_ui` on for exactly this: the list, the search and the
pagination are core's, and the columns (holder, item, status, expires, source)
are ours. Core's write surfaces are shut (`create_posts`, `publish_posts` and
`delete_posts` are `do_not_allow`): a record is never edited, it is written by
`Access_Writer`, the one path every change takes.

**Ways to grant.** The Add Access form (user, item, duration, where an empty
duration is lifetime); the Access metabox on every restrictable type's edit
screen, attachments included, which lists the item's direct holders and grants
right there from a user search, days and one button, and manages the item's
groups the same way; and quick edit on the posts and pages lists, whose Access column
counts holders and whose inline form grants user-plus-days on save. All of
them end in `Access_Writer::grant()`, stamped `source=admin` and created-by.

**Editing a record is editing its expiry.** The list's Edit action opens the
Edit Access page: holder, item and provenance are the record's identity and
stay fixed; the expiry moves through `Access_Writer::set_expiry()`. Status
follows the date, a past date expires it on the spot, and a future date
brings an expired record back. Revoked records refuse: the way back is a
fresh grant. Extending is also just re-granting, since the writer stacks days
onto a live timed record.

**The list filters** by holder (user search over core's `author` var), item
type, one specific item, and source, through `Access_Filters` on the list's own
toolbar, applied as meta clauses.

**The pickers are components** (`src/Admin/Pickers/`): an abstract `Picker`,
a visible control plus a hidden input carrying the choice, with
search-as-you-type `User_Picker`, `Post_Picker` and `File_Picker` against
`Picker_Search`'s capability-gated admin-ajax endpoints (an attachment is a
post, so files are the same search), and `Group_Picker`, which searches the
same way and carries the group's UUID. Any later surface drops one in with
`( new User_Picker( 'field', 'field' ) )->render()`.

**Groups are created on the Groups screen only.** Free-tagging would let
quick edit, bulk edit and both editors mint a group as a side effect of
saving a post; `Access_Taxonomy` refuses those origins and 403s the REST
terms create. Assigning existing groups from every surface still works.

**Revoke is one row action, three behaviours.** What it does is the
`revoke_behaviour` key of the `gatedmedia_settings` option: `revoke` (the
default, mark the record and keep the history), `expire` (pull the date to now)
or `delete` (remove the row outright). `gatedmedia_revoke_behaviour` filters
over the stored value, and the Settings screen carries the control. All three
are writer methods, and the resolver forgets the holder the moment any of
them fires.

**The profile screen shows one person's records**, all three statuses,
rendered by the same column code as the Access list, read-only, gated on the
same capability.

**The sweep keeps the list honest.** `gatedmedia_sweep_expired` runs daily and
moves active records past their date to `gatedmedia_expired`, firing
`gatedmedia_access_expired` per record. It is housekeeping, not enforcement:
the resolver compares dates against now on every read, so if the sweep never
ran, nothing would leak, the admin list would just read stale.

## Payments

All three ways in are real: paid on site through Stripe, given by an
administrator, and a webhook saying they paid elsewhere.

**One table, and the row is the replay guard.** `{prefix}gatedmedia_payments`
is created by `Payments_Schema` from a load-time version check, never an
activation hook, and `Payment_Store` owns every query. Each status move is
a single conditional update (`pending→complete`, `complete→refunded`,
`pending→failed`): affected rows answers who was first, so a retried Stripe
delivery changes nothing and needs no event log. `Payment` is the typed
read-back, snapshot decoded.

**Access lands on Stripe's confirmation and nowhere else.** `Checkout`
creates the pending row, contents snapshot frozen because groups are live,
*before* the buyer leaves for the hosted session, and grants nothing for a
priced product. `Stripe_Webhook` verifies the signature (the gateway wraps
the SDK; anything unverifiable is a 400), moves the row, and grants from
the snapshot via `Access_Writer::grant()`, source `stripe`, reference the
payment's uuid. The return page polls `GET /payment/{uuid}`: owner only,
status only, everything else the same 404. A refund (`charge.refunded`)
moves the row once and revokes every record the payment created. A free
product involves Stripe not at all: direct grants, no row. A coupon spends
exactly when a payment completes, with usage counted from the table rather
than stored, and one that takes the price to zero completes its row on the
spot. Eligibility is the product's email allow-list plus the
`gatedmedia_product_eligibility` filter.

**They paid elsewhere.** `POST /access` (application password plus the
give-access capability): a payload names a person by email, found or created
with profile fields filled like every other route, plus a target, a duration
and the sender's source and reference, which the writer's guard makes
retry-safe per item. A `product` target expands to one record per
item. Every delivery fires `gatedmedia_webhook_received`, accepted or not.

**A product is edited as a block and reached by its UUID.** The product
form is the locked `gated-media-access/product-details` block, carrying price
in the shop currency, duration, visibility, the items it grants and the email
allow-list, saving to registered meta over REST, managers only. Identity
is minted by the shared `Support\Uuid` (the same key groups carry), and
`/{product_path}/{uuid}` is the only public road in: slugs and IDs answer
404, products stay out of search, sitemaps and public REST, and
`get_permalink()` answers the UUID URL so every redirect points the one
way. Products and coupons both sit behind `gatedmedia_manage_products`.

**One payment, whole.** The Payments list's Reference column links each row
to `Payment_Detail_Page`, hidden like the Edit Access page
(`add_submenu_page( '', … )`), behind the same view capability, read-only:
the row's facts as Stripe left them, and every access record the payment's
reference wrote, each linked to its Edit Access view.

**The shop face.** The product page is the product's own post: the rewrite maps
`/{product_path}/{uuid}` to its single query, so the theme renders it like any
other post and `product-details` draws the page inside `the_content`. There is
no separate product route or template. Six states decide what is offered
(signed out, already held, lapsed, not eligible, free, for sale) and holding it
outranks every other message. `Product_Offer` chooses the state; `Checkout`
decides what may actually happen and is asked again on submit, so a page that
offered a control it should not have still could not buy anything. The buy
control is a form to `admin-post.php`.

**A coupon says what it saves before you commit.** Apply is not part of the
buy form: it is a GET form of its own, because a form cannot nest inside a
form and because pressing Apply must not be pressing Get access. It reloads
the product at `?gatedmedia_coupon=CODE`; `Product_Offer` asks
`Checkout::preview()` what that code is worth, and the page comes back with
the discount named, the full price struck through, and Remove as a link to
the bare permalink. `Coupon_Pricing` answers both questions, what a code is
worth and whether it still holds, so the price shown and the price charged
cannot drift. Previewing writes nothing and spends nothing: usage counts
completed payments only, and a coupon that runs out between the page being
priced and the button being pressed is refused at purchase, whatever the page
said. Only a code that actually priced the page rides the submit, as a hidden
`gatedmedia_coupon` field.

**On a phone the buy action is pinned.** `action-bar` is the one
fixed-to-the-bottom element anywhere, narrow only, and composed by
`product-details` alone so it can never appear on an account view. It carries
the price in the button's own label, so it appears only where there is a price
to pay; signed out and free have no bar, because a second control under the
same name is noise rather than help. It is a `<button form="…">` naming the buy form by id, so it
submits a form it is not inside without a line of script.

**There is no payment return page.** Stripe returns the buyer to the order
itself at `/account/orders/{uuid}?new_order={uuid}`, and the `payment-status`
block draws confirming, done or failed from the row's own status. The thank-you
shows only when the query arg names that very payment *and* the payment belongs
to whoever is looking; anyone pasting somebody else's uuid gets the ordinary
page.

**And it confirms itself.** Stripe returns the buyer before its webhook has
necessarily landed, so a pending panel now watches rather than telling them to
reload. `assets/js/modules/payment-status.js` polls `Payment_Status_Route`
every three seconds, twenty times, and **reloads the page** when the status
moves, because the pill, the panel and the "Access this created" section all
change together and redrawing the panel alone would put "You're in" above a
pending pill. Run out of attempts and the wording softens to say it is taking longer;
it is never an error, because the buyer has paid either way. The timing comes
from the `gatedmedia_payment_poll` filter. Everything the script needs (uuid,
a `wp_rest` nonce, the timing, the stand-down sentence) is printed on the
panel by `render.php`, and only for a pending payment belonging to somebody
signed in, so a page with nothing to watch carries no nonce and no attributes.
Without JavaScript the panel reads exactly as it did before.

**A held group opens.** `/account/my-access/{group-uuid}` lists what the group
holds now: groups are live, so it is a query, not the set as it was when access
was granted. Holding the group is the whole permission: a group nobody gave you
and a uuid that never existed answer the same nothing.

**Settings** has its own submenu entry: the shop currency (a real ISO list,
and every product is priced and stamped in it), the product URL path, the
Stripe mode with test and live key sets (secrets are never echoed back, an
empty resubmit keeps what is stored, and `Settings` is the one reader,
filtered so wp-config can own the keys), and the revoke behaviour.
`Support\Money` formats any ISO currency from ICU data, through the intl
extension where loaded, symfony/intl's bundled copy where not, and the
browser's own `Intl` in the editor, with zero always the word "Free".

## Notifications

Six emails, none load-bearing: if no mail ever left the site, access would
still resolve exactly the same.

**One sender owns the contract.** Every email goes through
`Notification_Sender::send()`: the stored template override (or the shipped
default) → placeholders (`{name}`, `{item}`, `{link}`, `{expires}`,
`{site}`) → the `gatedmedia_notification_recipients` filter → the
`gatedmedia_notification_content` filter → the
`gatedmedia_notification_sending` action → `wp_mail()`. A site that mails
through its own system listens on the action and empties the recipients, so
our send stops and theirs starts. Per-type switches, subject/body overrides,
an admin-copy address and the warning lead time all live in the one
`gatedmedia_settings` option, edited on the Settings screen's
Notifications tab (`&section=notifications`).

**Access created** (`Access_Created_Mail`) listens on the writer's
`gatedmedia_access_granted`, whichever route granted. Grants queue per
holder and flush once on shutdown, so a product purchase granting three
items is one email with the items joined, `{expires}` the soonest date or
never. Invite-sourced grants are skipped: their invite email is the
announcement.

**The expiry warning** (`Expiry_Warning`) is `Sweep`'s shape: a daily
`gatedmedia_expiry_warnings` event scanning active records whose date falls
inside the `expiry_warning_days` window (filtered by
`gatedmedia_expiry_warning_days`). Lifetime records are never warned; each
record warns once per date, and the `gatedmedia_expiry_warned_at` meta is the
bookkeeping, cleared when a record is rescheduled so a new date earns a new
warning.

**Invites** (`Products\Invites`) fire when an address joins a published
product's allow-list, gated by the block's per-product switch and the
per-type switches. Four variants by who they are and what it costs:
an existing user on a free product is granted on the spot (through
`Checkout::grant_items()`, source `invite`) and told; an existing user on a
priced product is invited to buy; an address with no account is asked to
create one, worded by price. Sent dates live in one JSON map on the product
(`gatedmedia_invites`), shown in the block's allow-list rows. Removing an
address forgets its invite, so re-adding sends afresh.

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
**both sides of the 782px breakpoint**, as the `wide` and `narrow` projects,
because the largest single thing to get wrong in this interface is the reflow.

`WP_BASE_URL` points it somewhere other than wp-env; `WP_USER` and
`WP_PASSWORD` override the wp-env defaults.

These cover what the PHP suites cannot see: that the theme still renders around
us, that a refused URL answers 404 rather than a soft one, that the sidebar and
the tab strip never both show, and that nothing overflows the viewport.

**The fixtures build themselves.** `tests/e2e/global-setup.js` runs each file in
`tests/e2e/fixtures/` before the suite. `kitchen-sink.php` creates a page
holding every component in every state, plus the four section views.
`shop.php` creates the products, the held group and the completed order the shop
specs walk, and prints their URLs for the specs to read, because a product's URL
carries a uuid minted when the fixture ran and cannot be written down.

Two things that fixture had to learn, both of which made specs pass on a clean
database and fail on a developer's. A product created by a fixture has never
been opened in the editor, so `Product_Route::ensure_block()` has never put the
block delimiter in its content and the page renders nothing, so the fixture
writes the delimiter itself. And a grant for an item the person already holds
on a live dated record is *stacked onto that record* rather than written as a
new one (`Access_Writer::stack_onto_live()`), so the order grants an item
nothing else does, or it would create no record carrying its reference and the
order detail's "Access this created" would be empty.

That page is also why the component specs matter: everything else visits
`/account/`, where the shell wraps the lot. Three faults survived a full suite
that way: design tokens scoped so a component had none outside the shell, the
icon sprite printed only on the account route, and inline components with no
block-level host to sit in a theme's content column.

The setup derives the plugin directory from the checkout rather than assuming
the slug, because CI checks out into a directory named after the run.

**The bootstrap downloads `restrict-media-file-access` on first run**, from its
public GitHub release, using
`Gin0115\WPUnit_Helpers\WP\WP_Dependencies::install_remote_plugin_from_zip()`.
It lands in `wordpress/wp-content/plugins/`, which is gitignored.

Two things about that are worth knowing before changing it:

- **It has to be the release zip, not composer.** The git tag ships no
  `vendor/` directory, and the plugin's main file returns early when its
  autoloader is missing, so a composer-installed copy defines the constants
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
`set_current_screen()` first, because a plain CLI run will not register it.

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

Two workflows, both on the local act runner, both triggered by `pull_request`,
which is how the runner discovers work:

| | |
|---|---|
| `.karkinos/workflows/wp-6-9.yml` | checkout, composer install, phpcs, phpstan, phpmd, phpunit. *Is the code sound* |
| `.karkinos/workflows/e2e.yml` | build assets, start wp-env, Playwright at both viewports. *Does it render* |

They are separate files so a browser run never slows the lint-and-test one, and
because they fail for different reasons.

The e2e one brings WordPress and MySQL up itself, as containers on the host
daemon, so it declares no services of its own. It destroys any leftover
environment **before** it starts as well as after: a run killed outright never
reaches its teardown, and its containers would still be holding 8931 and 8932
when the next one begins.

Three things in `wp-6-9.yml` are runner-specific and will look wrong out of
context:

1. **`WPCompat.pluginFile` is set in `.phpstan.neon`.** `johnbillion/wp-compat`
   otherwise looks for `<cwd>/<basename of cwd>.php` to read `Requires at
   least`. That resolves on a dev box only because the directory happens to be
   named after the plugin; in a runner worktree it looks for `run-41.php` and
   throws an Internal Error.
2. **The test bootstrap requires the plugin by path**, never through
   `activate_plugin()` with a slug. A slug resolves against `WP_PLUGIN_DIR`,
   which depends on where the checkout sits, and when it fails it returns a
   `WP_Error` nobody reads, so the plugin silently never loads.
3. **The mysql service publishes `3306:3306`.** Normally avoided, because it
   takes the runner's global lock and serialises the run. It is unavoidable
   here: act starts the job container with `network="host"` while services join
   the job's bridge network, so a service hostname never resolves from inside
   the job.

## Documentation

This README and [`docs/`](docs/README.md) are the documentation that ships. The
README says how the plugin is built; `docs/` shows every screen, captured from a
real site.

| | |
| --- | --- |
| [The account area](docs/account-area.md) | What a person holds, their files, their orders, their profile |
| [Buying access](docs/buying.md) | The product page, coupons, free products, and the wait for Stripe |
| [Signing in](docs/signing-in.md) | Sign in, sign up and password reset |
| [Administration](docs/administration.md) | Access records, groups, products, payments and settings |
