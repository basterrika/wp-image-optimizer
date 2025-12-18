# Image Optimizer for WordPress

Zero-config plugin that converts uploaded images to **WebP** and replaces the original file.

## What it does

- Converts uploads to **WebP** using the WordPress image editor (GD/Imagick).
- Replaces the original file (no duplicate storage).
- Skips animated GIFs.
- Re-encodes `.webp` uploads in place (keeps the same filename/URL).
- Works for normal uploads and sideload/import uploads.

## Requirements

- WordPress 6.5+
- PHP 8.4+
- GD or Imagick with WebP support
