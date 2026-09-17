# Image Optimization Handoff (Spec §13)

**Date:** 2026-09-17 · **Scope:** Replacement of all external CDN image references (pagesmith-cdn.com / Unsplash) with locally generated AVIF/WebP assets.

---

## 1. Summary

All raster images on the site are now self-hosted under `public/images/` (39 files, 1,323,273 bytes total). Every `<img>` that previously loaded from `pagesmith-cdn.com` was converted to a `<picture>` element with AVIF → WebP source negotiation. Zero external image hosts are referenced from `src/` or the built `dist/` output. The OG image, favicon and apple-touch-icon are local as well, and the obsolete CDN `<link rel="preconnect">` hints were removed.

## 2. Pipeline

- **Script:** `scripts/optimize-images.mjs` (sharp, devDependency `sharp@0.34.5`).
- **Sources:** 9 original WebP files preserved in `src/assets/originals/` (896 KB) for reproducibility.
- **Output:** `public/images/` — 18 AVIF variants + 18 WebP fallbacks + `home-og.jpg` + `favicon-32.png` + `apple-touch-icon.png`.
- **Quality tiers:** heavy-overlay backgrounds AVIF q30–44; faces/LCP candidates q56–58; logo q64. Visual QC of all variants completed and approved before integration.
- Re-run with: `pnpm run optimize:images` (sources must exist in `src/assets/originals/`).

## 3. URL → Asset Mapping

| Component | Old (pagesmith-cdn) | New local variants |
|---|---|---|
| `HeroSection.astro` (technician, LCP) | `Rauchmelder-anbringen-320-…-320.webp` | `hero-techniker-320.avif/.webp` (320×345, alpha) |
| `Navigation.astro` / `Footer.astro` / `ProofSection.astro` (logo ×4 total) | `Logo_Normcheck_Rauchmelder-320-…-320.webp` | `logo-normcheck-320.avif/.webp` (320×72, alpha) |
| `AboutSection.astro` | `Unsere-Techniker-mit-Wagen-768-…` (320/640/768) | `techniker-team-{480,768}.avif/.webp` |
| `CheckSection.astro` (freisteller) | `photo_2026-09-14_19-40-13-Photoroom-320.webp` | `rauchmelder-freisteller-{256,320}.avif/.webp` (320×251, alpha) |
| `ServicesSection.astro` (bg) | `vvills-…-unsplash-1536-…` (320–1536) | `bg-services-{768,1280,1536}.avif/.webp` |
| `PricingContact.astro` / `FinalCta.astro` (bg, shared) | `lasse-diercks-…-unsplash-1024-…` | `bg-formular-{640,1024}.avif/.webp` (1024×640) |
| `ProcessSection.astro` (bg) | `paul-lichtblau-…-unsplash-1024-…` | `bg-ablauf-{640,1024}.avif/.webp` (1024×683) |
| `BerlinSection.astro` (bg) | `sebastian-herrmann-…-unsplash-1024-…` | `bg-berlin-{640,1024}.avif/.webp` (1024×683) |
| `index.astro` (shared page bg) | `cecilia-miraldi-…-unsplash-1536-…` (320–1536) | `bg-pruefung-{768,1280,1536}.avif/.webp` |
| `SEOHead.astro` | env-based `${OG_IMAGE_CDN_URL}/…-og.png` | `/images/home-og.jpg` (static, 1200×630) |
| `SEOHead.astro` (icons) | `pagesmith-cdn.com/2be62e80/favicons/…` | `/favicon.svg` + `/images/favicon-32.png` + `/images/apple-touch-icon.png` |

## 4. Markup Pattern

```astro
<picture style="display:contents">
  <source type="image/avif" srcset="… .avif 768w, … 1280w" sizes="100vw" />
  <source type="image/webp" srcset="… .webp 768w, … 1280w" sizes="100vw" />
  <img src="/images/…-1280.webp" width="1280" height="847" alt="…" loading="lazy" decoding="async" />
</picture>
```

Decisions:

- `<picture style="display:contents">` keeps the DOM layout-neutral (the `<img>` remains the flex/grid child); verified no CSS in the codebase uses direct-child selectors against these images.
- **Hero technician** (LCP): `loading="eager"` + `fetchpriority="high"` (unchanged), AVIF first, WebP fallback.
- **All below-fold images:** `loading="lazy"` + `decoding="async"`.
- `width`/`height` attributes set from sharp-measured intrinsic dimensions everywhere (CLS prevention), including the previously attribute-less footer/proof logos.
- Alpha-carrying assets (technician, freisteller, logo) keep alpha in both AVIF and WebP.
- Backgrounds stay as positioned `<img>` layers (existing overlay/blur CSS untouched) — only their sources changed.
- `BaseLayout.astro`: removed `preconnect` to `pagesmith-cdn.com` and `images.unsplash.com` (no longer needed; saves two connection setups).
- `SEOHead.astro`: removed `ACCOUNT_ID`/`SITE_ID`/`OG_IMAGE_CDN_URL` env logic; OG image is now a static local path (emitted as absolute URL via `site` config).

## 5. Verification

- `grep -rn "pagesmith-cdn|images.unsplash" src/` → **0 matches** (16 `<img>` replacements across 12 files; an earlier truncated grep had missed 2 logo instances inside the `ProofSection` modal — caught on re-scan and also replaced).
- `pnpm build` → **exit 0**, 4 pages, Cloudflare adapter + sitemap OK.
- Built output: `dist/client` contains **0 CDN references**; `index.html` references all 39 local assets; `og:image` → `https://www.rauchmelder-service.berlin/images/home-og.jpg`.
- `dist/client/images` byte-identical to `public/images` (1,323,273 bytes, 39 files).
- Smoke test (static server over `dist/client`): `/`, `/impressum/`, hero AVIF (12.6 KB), bg-services WebP (44.2 KB), OG JPG (58.8 KB), favicon-32, apple-touch-icon → **all HTTP 200**.
- Hero AVIF dimension check via sharp: 320×345 — matches `width`/`height` attributes.

## 6. Known Issues / Follow-ups

1. **`pnpm check` (astro check) reports 7 pre-existing errors** — none introduced by this change, all outside the edited markup:
   - `astro.config.mjs:86,88` — vite plugin typing (`tsconfigPaths` not in `AllResolveOptions`).
   - `src/components/home/v2/ProofSection.astro:576,581` — `modal` possibly `null` in the pre-existing modal `<script>`.
   - `src/layouts/BlogLayout.astro:205` ×2 — implicit `any` in `tags.map(...)`.
   - `src/layouts/DocsLayout.astro:67` — `doc.slug` not on collection type.
   These do not affect `astro build`. Recommend a separate cleanup ticket.
2. **Unsplash photographer attribution unverified** (Unsplash API returned 401). The five author names in `impressum.astro` are best-effort from file metadata; a verification-status note was added directly under the *Bildnachweis*. **Action:** verify via Unsplash API once access is granted, then update/remove the note.
3. **AVIF `Content-Type`:** static dev servers may serve `.avif` as `application/octet-stream`; production hosting (Cloudflare) serves `image/avif` correctly. No action needed, but confirm on first deploy.
4. **pnpm build scripts:** `pnpm-workspace.yaml` must keep the `onlyBuiltDependencies: [esbuild, sharp, workerd]` block — a bare `pnpm install` had silently dropped it, which would break sharp for future pipeline runs. Restored and committed.
5. Note in `impressum.astro` (`Bildnachweis`) should be revisited together with follow-up #2.

## 7. Files Changed

- **Markup:** `HeroSection`, `ServicesSection`, `AboutSection`, `CheckSection`, `PricingContact`, `ProcessSection`, `BerlinSection`, `FinalCta`, `ProofSection` (all in `src/components/home/v2/`), `src/components/Navigation.astro`, `src/components/Footer.astro`, `src/pages/index.astro`.
- **Head/layout:** `src/components/SEOHead.astro`, `src/layouts/BaseLayout.astro`.
- **Legal:** `src/pages/impressum.astro` (Bildnachweis + verification note).
- **Assets/tooling:** `public/images/` (new), `scripts/optimize-images.mjs` (new), `src/assets/originals/` (new), `package.json`, `pnpm-lock.yaml`, `pnpm-workspace.yaml`.
