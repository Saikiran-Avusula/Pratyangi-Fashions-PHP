# UI parity: Node home page vs PHP landing page

Sources read (read-only): `apps/web/src` (page.tsx, SiteHeader, Hero, motion/*, useMarquee, SmoothScroll, TryOnSection, CompareSlider, SocialFeed, WhyPratyangi, SiteFooter, ProductCard and their CSS modules) and `flow-test/web-out/index.html`.
PHP side: `public/assets/site.js` (all behaviour), `public/assets/site.css` (3 small rules), `app/Views/*`, `app/Web/*`.
Nothing here has been seen in a real browser. "matched" means the code follows the Node source value for value, not that it was compared on screen.

How it works: the compiled CSS (keyframes, transitions, hover rules, breakpoints) is the Node CSS, copied. Node animates with framer-motion (JS), so site.js uses the Web Animations API with the same durations, delays and easings. Hidden start states are set by site.js only when it runs and only when reduced motion is off, so with JS off or reduced motion on, everything is visible.

## Behaviours

| Behaviour | Node file | PHP implementation | Status |
|---|---|---|---|
| Header sticky | SiteHeader.module.css `position: sticky; top: 0` | copied CSS | matched |
| Header shrink on scroll | none in Node (fixed 62/72/82px by breakpoint) | none | matched (Node has none) |
| Header entrance (y -24 to 0, fade, 0.45s easeOutExpo) | SiteHeader.tsx motion.header | site.js `entrances()` | matched; brief flash possible before the deferred script runs (see Notes) |
| Header hover states (Shop, bag, search pill, avatar ring) | SiteHeader.module.css | copied CSS | matched |
| Hamburger icon to cross, chevron flip, gold colour when open | `.menuIcon[data-open]`, `.shopToggle[aria-expanded]` | markup carries `data-open` / `aria-expanded`; site.js toggles them; CSS does the transition | matched |
| Shop menu open (clip-path unroll 0.42s, columns rise 0.45s, delay 0.12 + 0.07 per column) | SiteHeader.tsx AnimatePresence | site.js `setMenu()`; nav is server-rendered with `hidden` | matched |
| Shop menu close (reverse clip-path 0.42s, then removed) | same | site.js exit animation then `hidden` | matched |
| Menu backdrop (click outside closes), Escape closes, link click closes | same | site.js | matched |
| Menu focus handling | none in Node (focus stays on the button) | same | matched (Node has none) |
| Body scroll lock | none in Node for menu or search | none | matched (Node has none) |
| Menu links | catalogue pages | go to `/soon` (pages not built) | approximated by design |
| Search dialog (scrim fade, dialog y16/scale .98 in, 0.24s), autofocus, live filter, "No matching items" text, Esc, click on scrim, close button, Tab trap, focus returns to Search button | SiteHeader.tsx | site.js; dialog and result list server-rendered with `hidden`; filter uses the same labels and extra words | matched; result links go to `/soon` (and `/#arrivals` for Best Sellers) |
| Account icon, signed in: avatar initial + "Hello," + first name at >=1101px, avatar only below; name width caps at 80px / 110px (>=1440px) | SiteHeader.tsx, `.accountText` media queries | server-rendered by `LandingController` + `HeaderAccount` from the `pf_session` cookie; widths come from the copied CSS | matched |
| Account icon, signed out | SiteHeader.tsx | server-rendered "Sign in" icon link (lucide user-round, size 22), href `/login` (stage 2A; was `/soon`) | matched |
| Account dropdown | none in Node (the avatar is a plain link to /account) | link to `/account` (stage 2A; the page is the "coming" page with Node's Logout button when signed in) | matched (Node has none) |
| Google profile picture in the avatar | Avatar component | not rendered; initial shown. CSP `img-src 'self' data:` would block the picture, and Google sign-in is a later phase | not possible for now |
| Bag icon | SiteHeader.tsx | server-rendered, no count badge (as requested), href `/soon` | approximated (Node shows a count) |
| Hero page-load intro (stage scale 1.08 to 1, models rise 56px, 1s; delays centre 0.25, -1 0.4, -2 0.55, -3 0.7) | Hero.tsx | site.js `entrances()` | matched |
| Hero pinned while sections slide over it | Hero.module.css sticky + `--header-h` | copied CSS | matched |
| Hero scroll effects: stage dims to 0.6, side models drift outward (soft ease), centre fades out | Hero.tsx, heroLayers.ts | site.js `scrollLinked()`, same travel/width/fade table and `cubic-bezier(.45,0,.2,1)`; progress = scrollY / category section offsetTop | matched |
| Hero slider / autoplay | none in Node (not a slider) | none | matched (Node has none) |
| "Shop by Category" heading fades in on scroll with a soft blur (0.05-0.45, blur 0 to 2.5 to 0) | page.tsx | site.js `scrollLinked()` | matched |
| Category carousel arrows (disabled at ends, scroll one card width, smooth) | page.tsx | site.js `categories()` | matched |
| Category card hover (image zoom, label arrow nudge) | page.module.css | copied CSS | matched |
| Section headings reveal (rise 26px + blur 6px + fade, "See all" slides in after 0.25s; repeats both ways) | RevealHeading.tsx | site.js `scrollReveals()` | matched except the exit timing (Node uses framer's default, site.js uses 0.4s ease-out) |
| Product cards: fade up 36px, stagger (index % 4) x 0.09s, hover lift 4px, hover actions and Add to cart reveal | ProductCard.tsx | site.js reveal; hover lift in `site.css`; the rest is copied CSS | matched; "Add to cart" and wishlist buttons do nothing (bag not built) |
| fadeUp / fadeIn reveals on every other block (try-on, offers, join, socials, why, newsletter, footer) with the same delays, amounts and repeat-on-scroll | motion/presets.ts, ScrollReveal | site.js `scrollReveals()` (selector table) | matched |
| Reviews frame reveal (y32, 0.8s, delay 0.2) | page.tsx | site.js | matched |
| Reviews marquee: 36px/s drift, pauses on hover/touch/focus/drag, resumes after 2.2s (0.6s after hover), mouse drag, loop through three groups, runs only on screen | useMarquee.ts | site.js `marquee()` (port) | matched |
| Parallax on the join photo (3%) and social photos (2.5%), only >=701px | ParallaxLayer.tsx | site.js `parallax()` | matched (progress measured from the layer's own box) |
| Smooth inertial wheel scroll (Lenis lerp 0.085, wheel x0.9) | SmoothScroll.tsx | site.js `smoothScroll()`: damped scroll toward a target | approximated: same constants, but not Lenis's exact maths; touch stays native; skipped over scrollable panels and with ctrl-zoom |
| In-page anchors glide with offset -80 | SmoothScroll.tsx anchors | site.js click handler | matched |
| Try-on compare slider (range input moves `--split`) | CompareSlider.tsx | site.js `tryOn()` | matched |
| Try-on buttons open a dialog (Esc, click outside, close) with the Node animation | TryOnSection.tsx | site.js; dialog server-rendered. Platinum and Gold use the Node texts; Silver shows "not part of this version yet" | approximated: the real AI studio needs the Gemini try-on API and the bag, which do not exist in this build. Not possible now |
| Offer buttons show the "Local demo only" message | page.tsx | site.js | matched |
| Newsletter form: empty message, thanks message, field cleared | page.tsx | site.js | matched |
| Back-to-top button | none in Node | none | matched (Node has none) |
| Other marquees | none besides reviews | none | matched |
| Hover and focus styles (buttons, offer cards, why cards, review cards, social links, tier buttons) | CSS modules | copied CSS | matched |
| prefers-reduced-motion | everywhere (useReducedMotion, CSS) | site.js reads it once at load: no hiding, no entrance, hero still, heading visible, no parallax, no marquee, no smooth scroll, instant menu/dialogs, carousel jumps; CSS hides the duplicate review groups | matched; changing the OS setting needs a reload |
| JavaScript off | Node needs JS for most of it | page fully visible; links and server-rendered product cards work; menu, search, carousel arrows do nothing | better than Node |

## Notes

- CSP: `script-src 'self'` already allows `/assets/site.js`; no CSP change. Styles are set through the DOM (CSSOM), which `style-src` does not block. The only header change is `Cache-Control: private, no-store` plus `Vary: Cookie` on `GET /` when a `pf_session` cookie is sent (the header differs per visitor).
- `/assets/site.js` is served as `text/javascript; charset=utf-8` by `StaticFiles` (only the file named `site.js`; no other JavaScript is served).
- Flash of content: the script is `defer` (as asked), so it runs after parsing. Elements already on screen at load (header, hero) can show for a moment in their final state before the entrance animation restarts them. Removing it would need a tiny blocking script in `<head>` that sets a class on `<html>`; not done because the brief asked for one deferred file.
- The session lookup is the phase 1 `AuthService::customerForToken`. No cookie: no database. Any failure: signed-out header and a log line with the exception class only.

## Manual side-by-side checklist

Open Node `http://localhost:3100` and PHP `http://127.0.0.1:8100` in two windows. Test at desktop (1440 wide) and narrow (390 wide). Repeat with the OS "reduce motion" setting on (steps marked R).

1. Load the page. Desktop and narrow: header slides down and fades in over about half a second; the stage settles, then models rise, centre first, then outwards. R: everything appears instantly.
2. Scroll slowly from the top. The hero stays pinned, dims a little, side models drift outward, the centre model fades out, "Shop by Category" fades in (slight blur mid-way). R: no motion, heading already visible.
3. Click the category arrows. Left arrow starts disabled; the row moves one card; the right arrow disables at the end. Narrow: swipe the row; arrows follow.
4. Hover a category card: photo zooms slightly, the chevron nudges right.
5. Scroll to New Arrivals: heading rises and sharpens, then "See all" slides in. Product cards fade up one after another. Scroll back up: they fade out again. Hover a card: it lifts, wishlist/eye icons and "Add to cart" appear.
6. Reviews: the row drifts left slowly. Hover or focus it: stops. Drag with the mouse: it follows, resumes about 2 seconds after release. R: no drift, one set of cards.
7. Try-on section: blocks rise as they enter. Drag the compare slider (and use arrow keys): the split moves.
8. Click "Try Your Look" on each tier and the upload icon: dialog fades/slides in; Esc, clicking outside, and the X close it. (PHP texts for Silver differ, see table.)
9. Offers: cards stagger in; "Claim Offer" shows the demo message under the grid. Hover lifts the card.
10. Join / socials: texts and cards stagger in; on desktop the photos shift slightly while scrolling (parallax); narrow: no parallax.
11. Why section: cards rise in sequence, icons pop in a moment later; hover lifts a card.
12. Newsletter: submit empty (message asked for email), then with an address (thanks message, field cleared). Footer blocks rise in.
13. Header menu: click Shop. The icon becomes a cross, the chevron flips, the panel unrolls, columns rise one after another. Hover a column title (gold line draws) and a link (slides right). Click outside, press Esc, or click a link: it closes with the reverse animation. Narrow: same, full-width panel.
14. Search: click Search (desktop pill, narrow round icon). Dialog opens with the input focused. Type "silk": list filters; type "zzz": "No matching items..." text. Tab cycles inside; Esc closes and returns focus to the Search button.
15. Account, signed out: user icon, tooltip/label "Sign in". Signed in (log in on Node, and give PHP a valid `pf_session`): avatar with the first letter; at >=1101px "Hello," and the first name beside it; below that width the avatar only. Name longer than 80px (110px at >=1440) is cut with an ellipsis.
16. Bag: icon only on PHP (no count). Click goes to `/soon`.
17. Wheel scroll: both pages glide with inertia. Click a "See all" or category card (`#arrivals`): smooth scroll that stops 80px above the section. R: native jump.
18. Turn JavaScript off in PHP: whole page visible, no hidden sections.
