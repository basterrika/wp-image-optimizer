# Image Optimizer for WordPress

Converts uploaded images to WebP (optimized) and replaces the original. Zero-config.

## What it does

- Converts uploads to **WebP** using the WordPress image editor (GD/Imagick).
- Auto-rotates images based on EXIF orientation.
- Resizes images to a maximum 2560px edge without adding a `-scaled` suffix.
- Replaces the original file (no duplicate storage) — unless the WebP ends up **larger**, in which case the WebP is discarded and the original is kept untouched. Resized images are always replaced.
- Skips animated GIFs.
- Re-encodes `.webp` uploads in place (keeps the same filename/URL).
- Works for normal uploads and sideload/import uploads.

## Notes & limitations

- **Only new uploads.** Images already in the media library are not touched. There is no bulk/CLI command yet.
- **`big_image_size_threshold` is disabled site-wide.** The plugin filters it to `false` so core doesn't create `-scaled` duplicates. This affects every image on the site, including uploads this plugin doesn't convert.
- **HEIC/HEIF** require Imagick built with HEIC support, and WordPress must accept the MIME type on upload — core rejects `.heic` by default, so it never reaches this plugin unless you allow it.
- If any step fails (no editor, resize error, save error), the upload is left exactly as WordPress produced it.

## Requirements

- WordPress 6.5+
- PHP 8.4+
- GD or Imagick with WebP support
