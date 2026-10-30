# Figma vs the PHP storefront (PX0b + PX0c)

Source: the PNGs in this folder. Our shots use the demo data (26 sample products with offers and 5 out of stock, a bag of 3 lines, 3 wishlist items, a customer with 2 saved addresses). Side-by-sides (Figma left, ours right) are in `.claude/snapshots/px0c/sbs-*.png`; phone shots at 320 / 360 / 394 / 414 are `m<width>-*.png` there.

## Matches
- Account dashboard and addresses page (1440): heading, sidebar with burgundy active row and outlined Logout, panels, address cards (default highlight, Edit / Delete).
- Bag (with items): item cards, quantity box, Order Summary card, burgundy Proceed to Checkout, "You May Also Like" cards, "Why PRATYANGI" cards.
- New arrivals: Categories / Filters / sort bar, three-across card grid, pagination.
- Address dialog: Address Type cards, field order, required stars, +91 prefix, Cancel / Save.
- Phone menu (D1 at 394px, measured): avatar 94px (D1 93), row height 82px, icon 22px, icon and text at 63 / 101px (D1 64 / 102), title 16px, subtitle 13px, name 20px, email 16px, Logout 299x44 at 48px (D1 298x44). At 320 / 360 / 414 the side margins shrink with the width (clamp) so nothing overflows.

## Fixed (PX0b + PX0c)
- Phone `/account` is the D1 menu; rows open their page with a back arrow. Desktop sidebar: Wishlist row added, My Reviews removed.
- Page background: bag, new arrivals, wishlist and product pages were greyish (#e5e3d9); now the cream of the account pages (#faf7f2), as in Figma.
- Wishlist: Figma card grid (large picture, name, price, Move to bag / Remove), titled "My Wishlist / Your saved favourites for later".
- Addresses page: titled "My Addresses / Manage your saved addresses for a faster checkout."; "+ Add address" is a burgundy underlined 14px link.
- Address dialog texts as Figma, disabled "Check Pincode" with a tooltip (until DL1), full-height bottom sheet on phones.
- Footer: company column full width on phones; checked at 320, 360, 394, 414: no word is broken, no overflow on 14 pages.
- Coupon note hidden on bag and checkout.

## Hidden on purpose (no feature or data behind it)
Silver Member badge, Membership row and stat card, My Reviews row, Track Order, Help / Store Locator in the top bar, app-store badges, COD / EMI badges, "Secure Payments" row (until PAYMENTS_ENABLED), coupon box, AI Try-On, Exclusive Offers, newsletter, "Loved by our customers", recent-orders list and "View Details / Track Order" buttons, wishlist block on the account page, avatar edit pencil, "Saved with love / saved for 30 days" banner on the wishlist, breadcrumbs that read "Home > My Account > My Orders" on the bag (wrong trail in the design).

## Open (not fixed, for PX1)
- Header: Figma has a search input and only bag + account icons; ours is the approved H1 header (Search pill, heart, bag, "Hello, name").
- Home: Figma has Wedding Collection, Festive Collections and Best Sellers product rows (they need occasion / sales data from the ERP), plus two social sections ("Where Tradition Meets Tomorrow", "See Pratyangi Fashions in motion") with no real content. Ours has New Arrivals only.
- Wishlist and addresses in Figma sit inside the account layout (sidebar on the left); ours is full width for the wishlist. The wishlist also lacks the "3 Items" count and the sort box.
- Address dialog: Figma has a "My Addresses" list pane on the left of the dialog; ours is the form only. Our extras: Alternate Mobile, the offers tick.
- Addresses page: Figma panel is titled "Delivery Address" with a pin icon and radio circles on the cards; ours says "Saved Addresses" and has no radios.
- Bag: item tags (Pure Silk, Handwoven), "Size / Saree length" line and the Discount / FREE shipping rows need data we do not have; cards under "You May Also Like" show the product name where Figma shows the tagline.
- New arrivals: Figma's filter panels are collapsed with grey headers and a slider with two values; ours are open lists (same data, different look).
- Stat cards: Figma shows 4 (orders, wishlist, addresses, membership); ours 2.
