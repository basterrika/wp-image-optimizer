# Image Optimizer for WordPress

Converts uploaded images to WebP (optimized) and replaces the original. Zero-config.

## What it does

- Converts uploads to **WebP** using the WordPress image editor (GD/Imagick).
- Resizes images to a maximum 2560px edge without adding a `-scaled` suffix.
- Replaces the original file (no duplicate storage).
- Skips animated GIFs.
- Re-encodes `.webp` uploads in place (keeps the same filename/URL).
- Works for normal uploads and sideload/import uploads.

## Requirements

- WordPress 6.5+
- PHP 8.4+
- GD or Imagick with WebP support
