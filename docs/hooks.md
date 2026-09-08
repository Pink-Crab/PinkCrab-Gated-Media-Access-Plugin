# Hooks

Every filter and action the plugin fires, what fires it, what it hands you, and what changes when you use it.

Nothing here needs the plugin's classes: hook from anywhere that runs on `plugins_loaded` or later.

## Access

### `gatedmedia_user_can_access`

`apply_filters( 'gatedmedia_user_can_access', bool $allowed, int $user_id, string $item_type, string $item_id )`

The last word on any access decision. `Resolver::can_see()` works out its answer from the person's live records, expanding held groups, and then hands it here. Every boundary asks that method: the file boundary before serving bytes, the post boundary before rendering a post or answering REST, the account area before listing anything.

`$item_type` is `file`, `post` or `group`. `$item_id` is an attachment id, a post id, or a group's UUID, always as a string.

Returning true grants access wherever the question was asked, including the file itself.

```php
// Anyone who can edit the site sees everything.
add_filter(
	'gatedmedia_user_can_access',
	function ( bool $allowed, int $user_id ): bool {
		return $allowed || user_can( $user_id, 'manage_options' );
	},
	10,
	2
);
```

### `gatedmedia_access_object_types`

`apply_filters( 'gatedmedia_access_object_types', array $object_types )`

Which post types the `gatedmedia_access` taxonomy attaches to, defaulting to `post`, `page` and `attachment`. Read when the taxonomy registers, so it decides where the Access panel appears, which lists gain the Access column, and what can be put in a group at all.

```php
add_filter(
	'gatedmedia_access_object_types',
	fn( array $types ): array => array_merge( $types, array( 'lesson' ) )
);
```

### `gatedmedia_gated_path`

`apply_filters( 'gatedmedia_gated_path', string $segment )`

The URL segment a gated post is reached under, default `gated`, sanitised with `sanitize_title()`. A gated post's only address is `/{segment}/{uuid}`, and `get_permalink()` answers that, so changing this moves every link to gated content at once. A filter rather than a setting, because a setting invites somebody to break links they have already shared.

## The account area

### `gatedmedia_account_sections`

`apply_filters( 'gatedmedia_account_sections', Section_Collection $sections )`

The only place third-party code adds UI. Resolved once per request, and the nav, the router and the renderer all read the result, so a section cannot appear in one and be missing from another.

Return a `Section_Collection`. `add()` returns a new collection rather than mutating, reusing a slug replaces that section, and `remove( $slug )` drops one. Return anything else and the plugin falls back to its own four rather than taking the account area down.

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

The block named there is rendered inside the account shell. No rewrite rule, query var or flush is needed: one catch-all rule serves every section and the collection decides at runtime which slugs are valid.

### `gatedmedia_account_slug`

`apply_filters( 'gatedmedia_account_slug', string $slug )`

The account area's URL segment, default `account`, sanitised. `Account_Url` resolves it in one place and `Account_Route` asks the same method for its rewrite rules, so renaming moves the route and every link into it together. An empty or non-string return falls back to `account`.

### `gatedmedia_account_url`

`apply_filters( 'gatedmedia_account_url', string $url, string $section, string $identifier )`

Where one link into the account area points. Runs for every link the plugin builds: the nav, the row links, the emails, and Stripe's return URL. `$identifier` is the second segment, `''` for a plain section link.

Use it when the blocks are placed on your own pages rather than served by the route.

```php
add_filter(
	'gatedmedia_account_url',
	fn( string $url, string $section ): string => home_url( "/members/{$section}/" ),
	10,
	2
);
```

### `gatedmedia_account_route`

`apply_filters( 'gatedmedia_account_route', bool $on )`

Whether the account route registers at all, over the stored setting. Off, no rewrite rules are added and the plugin's links fall back to the home page unless `gatedmedia_account_url` sends them somewhere.

### `gatedmedia_account_creation`

`apply_filters( 'gatedmedia_account_creation', string $route )`

How accounts are made: `registration`, `admin` or `purchase`. Decides whether the sign-up state is drawn and whether a product page offers to create an account, so a page never advertises a route that does not exist. Anything unrecognised falls back to `registration`. Core's `users_can_register` is never read or written.

### `gatedmedia_profile_prompt`

`apply_filters( 'gatedmedia_profile_prompt', bool $prompt )`

Whether a new account is sent to complete its profile on first sign-in. The prompt shows only the missing required fields and is not dismissible.

### `gatedmedia_auth_slug`

`apply_filters( 'gatedmedia_auth_slug', string $slug )`

The sign-in view's segment, default `sign-in`. One view in four states, so this moves all four. wp-login.php is untouched either way.

## What the blocks draw

Each account block raises a filter for its own data, because a `render.php` cannot reach the container. The defaults below are what renders when nothing answers, which is also what a signed-out visitor gets.

| Filter | Second argument | Shape |
| --- | --- | --- |
| `gatedmedia_my_access_data` | the group UUID, `''` for the list | `groups`, `posts`, `files`, `detail` |
| `gatedmedia_files_data` | none | `available`, `downloading`, `past` |
| `gatedmedia_orders_data` | the order UUID, `''` for the list | `orders`, `detail` |
| `gatedmedia_product_data` | the product id | `product_id`, `state`, `items`, `price`, `currency`, `term`, `nonce`, `action_url`, `error`, `coupon`, `page_url` |
| `gatedmedia_auth_data` | none | `state`, `error`, `message`, `invalid`, `email`, `redirect`, `signup_offered`, `minimum`, `action_url`, `nonce` |

The plugin's own classes answer these at priority 10. Hook later to change what they said.

```php
// Put a banner row at the top of Files.
add_filter(
	'gatedmedia_files_data',
	function ( array $data ): array {
		array_unshift( $data['available'], array(
			'title' => __( 'Start here', 'my-plugin' ),
			'href'  => home_url( '/start/' ),
		) );

		return $data;
	},
	20
);
```

### `gatedmedia_payment_poll`

`apply_filters( 'gatedmedia_payment_poll', array $poll, string $uuid )`

How the order page waits for Stripe's confirmation: `interval` in milliseconds and `attempts`. Printed onto the panel, so the script never decides timing for itself. Values are clamped to at least one second and one attempt.

## Products and payments

### `gatedmedia_product_eligibility`

`apply_filters( 'gatedmedia_product_eligibility', bool $eligible, int $product_id, int $user_id )`

Whether this person may buy this product. The plugin's own answer is the product's email allow-list, or true when it has none. Asked when the page decides what to offer and again when the buy form is submitted, so refusing here refuses the purchase itself rather than only hiding a button.

```php
add_filter(
	'gatedmedia_product_eligibility',
	fn( bool $ok, int $product_id, int $user_id ): bool => $ok && my_membership_is_current( $user_id ),
	10,
	3
);
```

### `gatedmedia_product_path`

`apply_filters( 'gatedmedia_product_path', string $path )`

The segment products sit under, default `access`, over the stored setting and sanitised. A product's only public address is `/{path}/{uuid}`, so this moves every product URL. The rewrite rules are flushed when the stored setting changes; a code-only change needs a flush of your own.

### `gatedmedia_checkout_return_url`

`apply_filters( 'gatedmedia_checkout_return_url', string $url, Payment $payment )`

Where Stripe sends the buyer back to. Defaults to their own order, flagged as just placed, which is what draws the confirmation panel.

### `gatedmedia_coupon_valid`

`apply_filters( 'gatedmedia_coupon_valid', bool $valid, ?WP_Post $coupon, int $user_id )`

Whether a coupon applies for this buyer, after the code has been matched and its expiry and limits checked. `$coupon` is null when no coupon matched the code. Asked when the page is priced and again at purchase, so a coupon refused here is refused at the till.

### `gatedmedia_coupon_discount`

`apply_filters( 'gatedmedia_coupon_discount', int $amount, WP_Post $coupon, int $subtotal )`

What the coupon takes off, in minor units, after the percent or fixed calculation. Return more than the subtotal and the price simply reaches zero, which completes the payment on the spot without involving Stripe.

### `gatedmedia_coupon_hold_seconds`

`apply_filters( 'gatedmedia_coupon_hold_seconds', int $seconds )`

How long a checkout in flight reserves a limited coupon, default one minute. Usage is counted from completed payments, so this window is what stops buyers arriving together from all passing the same limit. Return 0 to reserve nothing.

### `gatedmedia_stripe_mode`

`apply_filters( 'gatedmedia_stripe_mode', string $mode )`

`test` or `live`, over the stored setting. It picks which set of keys the credential readers answer with, so filtering it swaps the whole connection.

### `gatedmedia_stripe_key`, `gatedmedia_stripe_secret`, `gatedmedia_stripe_webhook_secret`

`apply_filters( 'gatedmedia_stripe_key', string $value, string $mode )`

The three credentials, each filtered separately with the current mode alongside. This is the one place they are read, so a site can keep keys out of the database entirely.

```php
add_filter( 'gatedmedia_stripe_secret', fn(): string => MY_STRIPE_SECRET );
```

### `gatedmedia_stripe_client_config`

`apply_filters( 'gatedmedia_stripe_client_config', array $config )`

`timeout`, `connect_timeout` and `max_network_retries` for the Stripe client. The call runs inline in the buyer's own request, which is why the defaults are far below the SDK's 80 seconds.

## Money and time

### `gatedmedia_currency`

`apply_filters( 'gatedmedia_currency', string $code )`

The shop currency, over the stored setting. Every product is priced and stamped in it. A code ICU does not recognise falls back to GBP.

### `gatedmedia_format_price`

`apply_filters( 'gatedmedia_format_price', string $formatted, int $minor_units, string $currency )`

The last word on a displayed amount, after the currency's own digits and symbol have been applied. Zero arrives as the word "Free".

### `gatedmedia_expiry_soon_days`

`apply_filters( 'gatedmedia_expiry_soon_days', int $days )`

How close an expiry has to be before the account area counts down in days rather than naming a date. Default 7.

### `gatedmedia_expiry_warning_days`

`apply_filters( 'gatedmedia_expiry_warning_days', int $days )`

How many days before access lapses the warning email is sent, over the stored setting, floored at 1. The daily job selects records whose expiry falls inside that window.

## Notifications

### `gatedmedia_notification_enabled`

`apply_filters( 'gatedmedia_notification_enabled', bool $enabled, string $type )`

Whether one notification type sends at all, over its stored switch. Return false and nothing is composed, so the content and recipient filters never run for it.

### `gatedmedia_notification_template`

`apply_filters( 'gatedmedia_notification_template', array $template, string $type )`

The stored subject and body override for a type. An empty part means the shipped default is used, so returning `array( 'subject' => '', 'body' => '' )` restores both.

### `gatedmedia_notification_recipients`

`apply_filters( 'gatedmedia_notification_recipients', array $recipients, string $type, int $user_id )`

Who receives one email: the holder's address, or the address a guest invite was sent to, plus the admin copy when that is on. Empty the list to stop the plugin's own send while still hearing the sending action.

### `gatedmedia_notification_content`

`apply_filters( 'gatedmedia_notification_content', array $content, string $type, array $args )`

The rendered `subject` and `body`, after placeholders. `$args` carries the values that were substituted (`name`, `item`, `link`, `expires`, `site`).

### `gatedmedia_notification_admin_copy`

`apply_filters( 'gatedmedia_notification_admin_copy', string $address )`

Where copies go, `''` for none. Defaults to the site admin address once the copy switch is on.

Taking over sending entirely is those last few together:

```php
add_action( 'gatedmedia_notification_sending', function ( string $type, int $user_id, array $args ): void {
	my_mailer_send( $type, $user_id, $args );
}, 10, 3 );

add_filter( 'gatedmedia_notification_recipients', '__return_empty_array' );
```

## Capabilities and lifecycle

### `gatedmedia_give_access_capability`, `gatedmedia_manage_products_capability`, `gatedmedia_view_payments_capability`, `gatedmedia_manage_settings_capability`

`apply_filters( 'gatedmedia_give_access_capability', string $capability )`

The four capabilities, each defaulting to its own `gatedmedia_*` string. Every screen and route checks the filtered value, so pointing one at a capability a role already holds is enough to move who does what.

```php
add_filter( 'gatedmedia_give_access_capability', fn(): string => 'edit_others_posts' );
```

### `gatedmedia_revoke_behaviour`

`apply_filters( 'gatedmedia_revoke_behaviour', string $behaviour )`

What Revoke does: `revoke` keeps the record as history, `expire` pulls its date to now, `delete` removes it outright. Over the stored setting, and anything unrecognised falls back to `revoke`.

### `gatedmedia_purge_on_uninstall`

`apply_filters( 'gatedmedia_purge_on_uninstall', bool $purge )`

Whether deleting the plugin also takes the payments table, the access records, the products, the coupons and the groups. Off unless asked for, because that data is business history.

## Actions

### `gatedmedia_access_granted`

`do_action( 'gatedmedia_access_granted', int $access_id, int $user_id )`

Fires once a record has been written, whichever route wrote it: the admin form, the metabox, quick edit, a purchase, a free claim, an invite, or the access webhook. Not fired when a repeat grant is refused by the retry guard, and not for a stacked extension, which fires `gatedmedia_access_rescheduled` instead.

Internally, `Resolver` drops that holder's memo so the new access is visible for the rest of the request, and `Access_Created_Mail` queues the notification, flushing once on shutdown so a purchase granting three items sends one email.

```php
add_action( 'gatedmedia_access_granted', function ( int $access_id, int $user_id ): void {
	my_crm_record_access( $user_id, get_post_meta( $access_id, 'gatedmedia_item_id', true ) );
}, 10, 2 );
```

### `gatedmedia_access_revoked`

`do_action( 'gatedmedia_access_revoked', int $access_id, int $user_id )`

Fires when access is taken away, on all three behaviours. Under `delete` it fires after the row has gone, so read what you need from the ids rather than the record.

### `gatedmedia_access_expired`

`do_action( 'gatedmedia_access_expired', int $access_id, int $user_id )`

Fires when a record moves to expired, whether by the daily sweep or by an administrator pulling the date into the past.

### `gatedmedia_access_rescheduled`

`do_action( 'gatedmedia_access_rescheduled', int $access_id, int $user_id )`

Fires when a live record's expiry moves: an edit, or a re-grant stacking more days onto it. `Expiry_Warning` clears its warned flag here, so the new date earns a new warning.

### `gatedmedia_payment_completed`

`do_action( 'gatedmedia_payment_completed', int $payment_id )`

Fires once a payment has completed and its access has landed, from Stripe's confirmation or, for a coupon that took the price to zero, from the checkout itself. The status move guards it, so a redelivered Stripe event fires nothing.

### `gatedmedia_payment_refunded`

`do_action( 'gatedmedia_payment_refunded', int $payment_id )`

Fires once a refund has been recorded and the access that payment created has been taken back. Guarded the same way, so it fires once however many times Stripe delivers.

### `gatedmedia_checkout_failed`

`do_action( 'gatedmedia_checkout_failed', int $payment_id, WP_Error $error )`

Fires when a checkout could not be started at all, which means Stripe refused or could not be reached. The payment row is already marked failed and any coupon it reserved has been given back.

### `gatedmedia_stripe_event`

`do_action( 'gatedmedia_stripe_event', \Stripe\Event $event )`

Fires for every verified Stripe delivery, whatever its type, before the plugin acts on the ones it knows. The signature has already been checked, so this is where to handle event types the plugin ignores.

### `gatedmedia_webhook_received`

`do_action( 'gatedmedia_webhook_received', array $payload, string $source, bool $accepted, string $reason )`

Fires for every delivery to the access webhook, accepted or refused, so a sending system's mistakes are visible rather than silent. `$reason` says why a refusal was refused, and is `''` when accepted.

### `gatedmedia_account_created`

`do_action( 'gatedmedia_account_created', int $user_id )`

Fires after somebody creates their own account from the front end. Accounts made by an administrator or by the access webhook do not fire it.

### `gatedmedia_profile_updated`

`do_action( 'gatedmedia_profile_updated', int $user_id )`

Fires after a person saves their own profile from the account area.

### `gatedmedia_file_downloaded`

`do_action( 'gatedmedia_file_downloaded', int $user_id, int $attachment_id, string $file_path )`

Fires when a protected file is served to somebody allowed to have it, once per download, before the bytes go out. Refusals do not fire it.

```php
add_action( 'gatedmedia_file_downloaded', function ( int $user_id, int $attachment_id ): void {
	my_log( sprintf( 'user %d downloaded attachment %d', $user_id, $attachment_id ) );
}, 10, 2 );
```

### `gatedmedia_notification_sending`

`do_action( 'gatedmedia_notification_sending', string $type, int $user_id, array $args )`

Fires before every send, after the recipients and content have been settled, and fires even when the recipient list is empty. That is what makes it the take-over point for a site mailing through its own system.
