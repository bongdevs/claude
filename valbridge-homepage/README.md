# Valbridge Truffle Auctions: homepage HTML template

A static homepage template for the Valbridge Group truffle auction platform, ready to be converted into a WordPress block theme and the **Valbridge Core** plugin.

Open `index.html` in a browser, or run `python3 -m http.server` in this folder and go to http://localhost:8000.

## Files

```
index.html                 complete homepage (all sections)
assets/css/style.css       design system + layout (BEM, "vb-" prefix, CSS variables)
assets/js/main.js          countdowns, live updates hook, currency, watchlist, slider, menu, consent
assets/img/brand/          logos (from 04_Logo_Dateien), OG image
assets/img/favicon/        favicons (from 03_Digitale_Assets)
assets/img/lots/           SVG placeholder truffle images (replace with real lot photos)
```

## Brand system used (from the Brand Guidelines PDF)

| Token | Value |
|---|---|
| Valbridge Gold | `#C9962B` |
| Deep Bronze | `#8B6914` |
| Champagne | `#E8D5A3` |
| Rich Black | `#0A0A0A` |
| Ivory | `#F5F1E8` |
| Headlines | Cinzel, uppercase with letter-spacing |
| Taglines / quotes | Playfair Display Italic |
| Body / UI | Montserrat |

Following the guidelines, gold is used only on black or ivory, never as a solid fill on pure white.

## Homepage sections (Technical Brief §15/§16)

1. Top bar: current delivery, currency switch (CHF/EUR/USD/GBP), 5 languages
2. Sticky header: logo, nav, search, watchlist, account, Register
3. Hero: headline, CTAs ("View live auctions" / "Register to bid"), real stats, featured lot with countdown ring
4. Live activity ticker (real bid events only)
5. **Ending soon** slider with live countdown + current price
6. **4 categories** as large tiles: 1st Class, Single Showpieces, Broken Pieces, 2nd Class
7. **How it works**: rolling 15-minute timer explained with an animated example
8. **New arrivals** from the latest delivery, with batch passport (traceability)
9. **Buy Now** list
10. Trust elements: freshness, origin, cold chain, secure payment + payment methods
11. **Valbridge "who is behind this"** section (swap in the founder photo)
12. FAQ (also output as FAQPage JSON-LD)
13. "Next delivery" sign-up: newsletter + web push
14. Footer: company data, legal links, languages; mobile bottom bar; cookie consent

## WordPress conversion notes

- **Lot card** (`article.vb-lot`) becomes `template-parts/lot-card.php`. Valbridge Core outputs these attributes:
  `data-lot-id`, `data-price` (CHF), `data-increment`, `data-bids`, `data-end` (ISO-8601 UTC, empty = awaiting first bid), `data-buynow`.
  The static preview uses `data-remaining` (seconds) instead of `data-end` so its demo timers never show as expired.
- **Server time**: set `window.VB_CONFIG = { serverNow: <?php echo time()*1000; ?>, demo: false, rates: {...} }` before `main.js` (via `wp_add_inline_script`).
- **Live updates**: call `VB.applyBid(lotId, { price, bids, end })` from your SSE / polling handler. The UI flashes, resets the timer and updates the ticker.
- **Turn off demo mode**: `demo: false`. The demo simulates bids to show the rolling timer. Production must show only real activity (fair-trading / honesty rule).
- **Currency**: `VB_CONFIG.rates` comes from the multi-currency plugin. In production, switching should also set the plugin's cookie so the server renders matching prices.
- **SEO**: hreflang, canonical, OG/Twitter and JSON-LD are in `<head>`. Let Rank Math / Yoast + WPML / Polylang output these per page.
- **Images**: replace the SVG placeholders with real lot photos (WebP/AVIF, `srcset`). Keep `width`/`height` to avoid layout shift.
- **Cookie banner**: placeholder only. Replace it with a certified CMP connected to Google Consent Mode v2.
- **Accessibility**: skip link, focus styles, ARIA on menus/sliders/toggles, `prefers-reduced-motion` support, and AA contrast tokens (`--vb-bronze-text` on ivory).

All numbers, lots, batch data and ticker entries in this template are **sample content**.
