# Tolk brand assets

The mark is a lowercase t whose crossbar branches out to three dots: the middle dot continues the crossbar (the origin site), the outer two are its localizations. The wordmark uses the same t as its first letter.

## Colours

|      | On light backgrounds | On dark backgrounds |
| ---- | -------------------- | ------------------- |
| Ink  | `#111827`            | `#ffffff`           |
| Teal | `#0d9488`            | `#2dd4bf`           |

## Sizes

- From 32px up, use the primary mark (`tolk-mark`, three branches).
- At 24px and below, use the small cut (`tolk-mark-small`, two branches, heavier strokes). The favicons already do.
- Leave clear space around the mark of at least a quarter of its height. Don't stretch it, recolour single parts, or add shadows and gradients.

## svg/

| File | Use |
| ---- | --- |
| `tolk-mark.svg` | Primary mark, colour, on light backgrounds |
| `tolk-mark-on-dark.svg` | Primary mark, colour, on dark backgrounds |
| `tolk-mark-black.svg`, `tolk-mark-white.svg` | One colour, for print, stamps, single-colour contexts |
| `tolk-mark-small.svg`, `tolk-mark-small-on-dark.svg` | Small cut, colour, 24px and below |
| `tolk-mark-small-black.svg`, `tolk-mark-small-white.svg` | Small cut, one colour |
| `tolk-icon.svg` | Small cut in `currentColor`, for inlining next to text or other icons, such as a Control Panel nav item |
| `tolk-tile-teal.svg` | Rounded teal tile, white mark: app-style icons |
| `tolk-tile-ink.svg` | Rounded ink tile, white letter and teal localizations: listings on light pages |
| `tolk-square-teal.svg` | Full-bleed teal square, for platforms that crop or mask the icon themselves |
| `tolk-wordmark.svg`, `tolk-wordmark-on-dark.svg` | Wordmark, colour |
| `tolk-wordmark-black.svg`, `tolk-wordmark-white.svg` | Wordmark, one colour |

## png/

All PNGs have transparent backgrounds except the social preview and the full-bleed squares.

| File | Use |
| ---- | --- |
| `tolk-mark-{1024,512,256,128}.png`, `tolk-mark-on-dark-{1024,512}.png` | Primary mark |
| `tolk-mark-black-512.png`, `tolk-mark-white-512.png` | One colour |
| `tolk-tile-teal-{1024,512,256}.png`, `tolk-tile-ink-{1024,512,256}.png` | Tiles, such as a marketplace or directory listing |
| `tolk-avatar-512.png` | GitHub or Packagist avatar; the platform crops it to a circle or rounded square |
| `tolk-wordmark-{1600,800}.png`, `tolk-wordmark-on-dark-1600.png` | Wordmark, for a README header or docs |
| `tolk-social-preview-1280x640.png` | GitHub repository social preview (Settings › General › Social preview) |

## favicon/

`favicon.svg` switches to the dark colours when the browser is in dark mode. For a docs site, copy the folder's files to the web root and add:

```html
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
```

`icon-192.png` and `icon-512.png` are for a web app manifest, if one is ever needed.
