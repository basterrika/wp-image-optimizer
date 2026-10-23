# Image Optimizer for WordPress

Converts uploaded images to WebP (optimized) and replaces the original. Zero-config.

## What it does

- Converts uploads to **WebP** using the WordPress image editor (GD/Imagick).
- Auto-rotates images based on EXIF orientation.
- Resizes images to a maximum 2560px edge without adding a `-scaled` suffix.
- Strips metadata (EXIF incl. GPS and camera details, XMP, IPTC) from the WebP and every sub-size. The ICC color profile is kept.
- Replaces the original file (no duplicate storage) — unless the WebP ends up **equal-sized or larger**, in which case the WebP is discarded and the original is kept untouched. This also applies to re-encoded WebP uploads. Resized images are always replaced.
- Skips GIFs, animated WebPs and PNGs (APNG).
- Re-encodes `.webp` uploads in place (keeps the same filename/URL). The new file is written beside the original and swapped in only once complete and either smaller or resized.
- Works for normal uploads and sideload/import uploads.

## Notes & limitations

- **Only new uploads.** Images already in the media library are not touched. There is no bulk/CLI command yet.
- **`big_image_size_threshold` is disabled site-wide.** The plugin filters it to `false` so core doesn't create `-scaled` duplicates. This affects every image on the site, including uploads this plugin doesn't convert.
- **HEIC/HEIF** require Imagick built with HEIC support, and WordPress must accept the MIME type on upload — core rejects `.heic` by default, so it never reaches this plugin unless you allow it.
- **Metadata stripping** only matters with Imagick. GD never writes metadata. Originals that are kept (including WebP uploads whose re-encoding saves no space, animated images, and unsupported types) keep their metadata. To keep metadata on converted images: `add_filter('wp_image_optimizer_strip_metadata', '__return_false');`
- **Quality** is WordPress's WebP default (86). Change it with the core `wp_editor_set_quality` filter.
- If any step fails (no WebP-capable editor, another plugin remapping WebP output, rotation error, resize error, save error, empty, incomplete or non-WebP output), the upload is left exactly as WordPress produced it. If only metadata removal fails, the image is still converted.

## Requirements

- WordPress 7.0+
- PHP 8.4+
- GD or Imagick with WebP support
