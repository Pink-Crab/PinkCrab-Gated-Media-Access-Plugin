# Administration

Everything the plugin adds sits under one menu, Gated Access. No second top-level entry, and no screen of ours anywhere else.

## Access

Every record: who holds what, its status, when it expires and where it came from. The toolbar filters on the holder, the item and the source.

A record is data rather than content, so there is no editor for one. The row actions are Edit, which changes the expiry and nothing else, and Revoke.

![The Access list](images/admin-access-list.png)

## Add Access

Pick a person, pick an item, give it a duration. The form is the only admin door into a record, and the record itself is written by `Access_Writer`, the same class every other route goes through.

![Add Access](images/admin-add-access.png)

## Edit Access

One record's expiry, moved, cleared to lifetime, or pulled into the past. The status follows the date, and a revoked record refuses, because revocation is final and a fresh grant is the way back.

![Edit Access](images/admin-edit-access.png)

## Groups

A group holds content, and people hold the group. This screen is those two facts per group in one place: what it contains, and who has access to it.

Core's taxonomy screens are switched off, because they hung a Groups entry under Posts, Pages and Media and could only ever show a name, a slug and a misleading count.

![Groups](images/admin-groups.png)

## Access on an item

A post or a file's own edit screen carries one Access panel: who holds this item directly, each removable, and which groups it sits in, with add and remove right here.

An inline grant is staged in the form and applied when the item is saved, so pressing it never abandons an edit in progress.

![The posts list, with the Access column](images/admin-posts-access-column.png)

## Products

A product is a price, a duration, the items it grants and an optional email allow-list. The form is one locked block on every product, saving straight to meta over REST.

![The products list](images/admin-products-list.png)

![The product editor](images/admin-product-editor.png)

### Sales

A product goes on sale from its own form, on the Sale line under the price: No sale, Money off or Percentage off, and how much. The header shows the sale price beside the full price struck through.

Many go on sale at once from the products list. Tick them, choose Edit from Bulk actions, and under Sale pick Percentage off or Amount off with how much, or End sale. The Price column shows each one's full price struck through beside its sale price.

A sale is stored as the percentage or the amount off (`gatedmedia_sale_type` and `gatedmedia_sale_value`), not as a price, so it is worked out from the current price every time: 20% off stays 20% off when the price changes. It is on only while it gives a price above nothing and below the full price, so a free product, or an amount off as large as the price, is no sale. A coupon comes off the sale price.

## Payments

Every payment Stripe reported, read-only. The rows are the record of what happened and nothing here edits one.

![Payments](images/admin-payments.png)

One payment in full: the row's own facts, and the access its reference created.

![A payment](images/admin-payment-detail.png)

## Settings

The shop currency, the product path, the Stripe mode and keys, the revoke behaviour, the account rules and the uninstall choice.

A stored secret is never echoed back: it renders as an empty field marked saved, and an empty submit keeps what is stored.

![Settings](images/admin-settings-general.png)

Notifications carry a switch, a subject and a body per type, with the tokens each one accepts.

![Notification settings](images/admin-settings-notifications.png)
