# BA AVIF Converter — AVIF + WebP for WordPress, 100% local and free

🇫🇷 **[Version française (originale) →](./README.md)**

**WordPress plugin that converts your JPEG/PNG/GIF images to AVIF and WebP, encoded locally (Imagick or GD), and serves them through an AVIF → WebP → original .htaccess cascade — without ever touching your original files.** Built by [Bernard David Corroy](https://www.david-corroy.com/) for [BuzzArena](https://www.buzzarena.com/), where it runs in production over tens of thousands of images.

Everything the popular converters charge for is free here: AVIF output, the AVIF + WebP mode, and local encoding (no image ever leaves your server).

## How it works

The plugin **never** modifies your images. It creates copies in a `wp-content/uploads-avifc/` mirror that reproduces your uploads tree, then `.htaccess` rules serve the best version each browser accepts:

```
Browser accepts AVIF?   → uploads-avifc/2026/07/photo.jpg.avif
Else, accepts WebP?     → uploads-avifc/2026/07/photo.jpg.webp
Otherwise               → uploads/2026/07/photo.jpg (the original)
```

Deactivate the plugin: the rules are removed, the site serves the originals again, nothing is lost.

## Features

- **New uploads converted within seconds** — a non-blocking self-call fires at the end of the upload request, with a double safety net (WP-Cron event + "recent uploads first" priority on the next batch)
- **Bulk optimization** of the existing library: batched, with pause / resume / forced reconversion, and live progress rings
- **"Newest first" order**: your hot articles (homepage, Discover) get AVIF within hours, the archives follow
- **Full disk scan**: also catches images that exist on disk but not in the Media Library (FTP uploads, plugins writing directly)
- **Themes and plugins** converted optionally (logo, sprites…)
- **Imagick or GD** with automatic fallback when the chosen method can't produce a format
- **Safety rails**: copies heavier than the original are dropped (`.skip` marker), atomic writes (`.tmp` then `rename` — a truncated file can never be served), file-based lock against overlapping runs
- **Built for shared hosting**: short batches, a 3-second breather between batches, options read straight from the database (works around APCu object caches not shared between web and CLI PHP), and a **direct server-cron trigger** (`admin-post.php?action=ba_avif_tick&key=…`) that bypasses WP-Cron when it misbehaves
- **Media Library column**: average size reduction per image, converted file count, "Convert now" button
- **Complete settings**: separate AVIF and WebP quality, source extensions (.png/.gif/.webp), excluded directories, EXIF metadata, error logging

## Installation

1. Download the `ba-avif/` folder and drop it into `wp-content/plugins/`
2. Activate **BA AVIF Converter** (the plugin refuses to activate if the server can't encode any of the chosen formats — you need at least Imagick with AVIF support, or GD on PHP 8.1+)
3. **Settings → BA AVIF**: pick the output format (AVIF + WebP recommended), then "Start bulk optimization"
4. On shared hosting, paste the cron trigger shown under "Advanced settings" into a cPanel cron job (every 5 minutes) — recommended
5. Purge your cache and verify: right-click an image on your site → Inspect → Network tab → the Type column should read `avif`

## Requirements and limits (the honest part)

- **Apache or LiteSpeed** (`.htaccess` rules) — battle-tested in production on o2switch. No Nginx support at this time.
- AVIF: Imagick compiled with libheif, **or** GD on PHP 8.1+ with libavif. The settings page shows your server's exact capabilities.
- Animated GIFs: only the first frame is kept — leave `.gif` unchecked if you use them.
- The `Vary: Accept` header is sent for caches/CDNs; purge your cache after activation.
- The plugin UI is in French. The code comments too. It runs fine on any WordPress locale.

## License

GPL-2.0-or-later — same as WordPress. Use, modify, redistribute freely.

## Going further

- 🔬 [Express SEO & GEO audit](https://www.david-corroy.com/audit-express/) (FR) — test your site's speed and AI visibility, free, 60 seconds
- 📚 [GEO Prompts](https://github.com/david-corroy/prompts-geo) — get cited by ChatGPT, Perplexity and Gemini
- 📊 [AI visibility tracking grid](https://github.com/david-corroy/grille-suivi-visibilite-ia)

**The author**: Bernard David Corroy, independent SEO & GEO consultant, founder of Phonandroid and BuzzArena — [Website](https://www.david-corroy.com/) · [LinkedIn](https://www.linkedin.com/in/bernard-david-corroy/) · [Wikidata](https://www.wikidata.org/entity/Q140472682) · [Malt](https://www.malt.fr/profile/davidcorroy) · [Crunchbase](https://www.crunchbase.com/person/bernard-david-corroy)
