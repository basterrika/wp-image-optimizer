<?php
/**
 * Plugin Name: Image Optimizer
 * Description: Converts uploaded images to WebP (optimized) and replaces the original. Zero-config.
 * Version: 1.0.0
 * Author: Mikel
 * Author URI: https://basterrika.com
 * Update URI: https://github.com/basterrika/wp-image-optimizer
 * Requires PHP: 8.4
 * Requires at least: 6.5
 * Tested up to: 6.9
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Image_Optimizer {
    private const bool DISABLE_BIG_IMAGE_SCALING = true;

    private const int WEBP_QUALITY_PHOTO = 85; // JPEG/HEIC/HEIF/WebP
    private const int WEBP_QUALITY_ALPHA = 90; // PNG/GIF (better edges/alpha)

    private const array CONVERTIBLE_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/gif' => true, // non-animated only
        'image/heic' => true,
        'image/heif' => true,
        'image/webp' => true
    ];

    public static function boot(): void {
        add_filter('wp_handle_upload', [self::class, 'maybe_convert_upload_to_webp'], 20);
        add_filter('wp_handle_sideload', [self::class, 'maybe_convert_upload_to_webp'], 20);

        if (self::DISABLE_BIG_IMAGE_SCALING) {
            add_filter('big_image_size_threshold', '__return_false');
        }
    }

    public static function activate(): void {
        if (!function_exists('wp_image_editor_supports')) {
            self::fail_activation('WordPress image editor is not available on this installation.');
        }

        if (!wp_image_editor_supports(['mime_type' => 'image/webp'])) {
            self::fail_activation('This server cannot generate WebP images (GD/Imagick WebP support missing).');
        }
    }

    private static function fail_activation(string $message): void {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html($message),
            'Image Optimizer',
            ['back_link' => true]
        );
    }

    public static function maybe_convert_upload_to_webp(array $upload): array {
        if (
            !isset($upload['file'], $upload['type']) ||
            !is_string($upload['file']) ||
            !is_string($upload['type']) ||
            $upload['file'] === '' ||
            $upload['type'] === ''
        ) {
            return $upload;
        }

        $file = $upload['file'];

        if (!is_file($file)) {
            return $upload;
        }

        $detected_mime = $upload['type'];

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $is_webp_ext = ($ext === 'webp');

        if (!$is_webp_ext && !isset(self::CONVERTIBLE_MIME_TYPES[$detected_mime])) {
            return $upload;
        }

        // Avoid flattening animated GIFs
        if ($detected_mime === 'image/gif' && self::is_animated_gif($file)) {
            return $upload;
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return $upload;
        }

        $editor->maybe_exif_rotate();

        $quality = in_array($detected_mime, ['image/png', 'image/gif'], true)
            ? self::WEBP_QUALITY_ALPHA
            : self::WEBP_QUALITY_PHOTO;

        $editor->set_quality($quality);

        /**
         * WebP is treated differently on purpose:
         * - If the uploaded file is already named ".webp", we re-encode it IN PLACE to keep the same filename/URL.
         *   Otherwise, generating a new "unique" filename would typically force "image-1.webp" because "image.webp"
         *   already exists at upload time.
         * - We only do in-place re-encoding when the extension is truly ".webp" to avoid mismatches like writing WebP
         *   bytes into a ".jpg" file path.
         */
        if ($is_webp_ext) {
            return self::save_in_place($editor, $file, $upload);
        }

        return self::convert_to_webp($editor, $file, $upload);
    }

    private static function save_in_place(WP_Image_Editor $editor, string $file, array $upload): array {
        $saved = $editor->save($file, 'image/webp');

        if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) {
            return $upload;
        }

        $upload['file'] = $saved['path'];
        $upload['type'] = 'image/webp';

        // URL stays the same (same filename)
        return $upload;
    }

    private static function convert_to_webp(WP_Image_Editor $editor, string $file, array $upload): array {
        $target = self::unique_webp_target_path($file);
        $saved = $editor->save($target, 'image/webp');

        if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) {
            return $upload;
        }

        // Replace original (no double storage)
        wp_delete_file($file);

        $upload['file'] = $saved['path'];
        $upload['type'] = 'image/webp';

        if (isset($upload['url']) && is_string($upload['url']) && $upload['url'] !== '') {
            $upload['url'] = dirname($upload['url']) . '/' . basename($saved['path']);
        }

        return $upload;
    }

    private static function unique_webp_target_path(string $original_path): string {
        $dir = dirname($original_path);
        $base = pathinfo($original_path, PATHINFO_FILENAME);
        $unique_filename = wp_unique_filename($dir, $base . '.webp');

        return trailingslashit($dir) . $unique_filename;
    }

    private static function is_animated_gif(string $path): bool {
        if (!is_readable($path)) {
            return false;
        }

        $contents = file_get_contents($path, false, null, 0, 1024 * 200);

        if ($contents === false) {
            return false;
        }

        // Count Graphic Control Extensions; >1 usually indicates multiple frames.
        $count = 0;
        $pattern = "\x00\x21\xF9\x04";
        $pos = 0;

        while (true) {
            $pos = strpos($contents, $pattern, $pos);
            if ($pos === false) {
                break;
            }
            if (++$count > 1) {
                return true;
            }
            $pos += 4;
        }

        return false;
    }
}

register_activation_hook(__FILE__, [WP_Image_Optimizer::class, 'activate']);
WP_Image_Optimizer::boot();
