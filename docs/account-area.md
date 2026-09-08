# The account area

Lives at `/account/`, and at `/account/{section}/` for each section. The four sections ship by default and a third party adds its own through `gatedmedia_account_sections`.

The nav is one list drawn two ways: a 256px column above 782px, a scrolling tab strip below it. Both are in the markup and CSS shows one, so the width decides and nothing is guessed server-side.

## My Access

Everything a person holds, in three lists: the groups, the posts and the files. Each row carries its expiry, and a group row says how many items it holds and opens on its own page.

![My Access](images/account-my-access.png)

![My Access on a phone](images/account-my-access-narrow.png)

## One group, opened

A held group lists what it contains right now, not what it contained when access was granted, because an administrator moves things in and out of a group and the holder gets whatever is in it at the moment they ask.

A group nobody gave you reads exactly like a group that does not exist.

![A group, opened](images/account-group-detail.png)

## Files

Everything downloadable, the contents of held groups included. Search and the type filter run against the rows already on the page, so nothing is fetched to filter, and with the script absent every row is simply shown.

Access that has run out moves to a past list, unless the file is still reachable another way.

![Files](images/account-files.png)

![Files on a phone](images/account-files-narrow.png)

## Orders

The record of what was bought and when. Not a shop: one order's detail hangs off the list at `/account/orders/{uuid}`.

![Orders](images/account-orders.png)

![Orders on a phone](images/account-orders-narrow.png)

## One order

What was paid, what the order included at the time, and the access it created. The contents are the snapshot frozen at purchase, because groups are live and what they held that day is not recoverable later.

An order that is not yours reads as an order that never existed.

![An order](images/account-order-detail.png)

## An order still confirming

Stripe returns the buyer before its webhook has necessarily landed, so the order they arrive on can still be pending. The panel says so, claims nothing, and asks the status route until the answer changes.

![An order waiting on Stripe](images/account-order-pending.png)

## Profile

The one profile shape every account-creation route fills, so somebody created by webhook is indistinguishable from somebody who signed up. Email identifies the account and is read-only here.

![Profile](images/account-profile.png)

![Profile on a phone](images/account-profile-narrow.png)
