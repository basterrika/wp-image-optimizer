<?php
/**
 * Plugin Name: Image Optimizer
 * Description: Converts uploaded images to WebP (optimized) and replaces the original. Zero-config.
 * Version: 0.7.0
 * Author: Mikel
 * Author URI: https://basterrika.com
 *
 * Requires PHP: 8.4
 * Requires at least: 6.5
 * Tested up to: 6.9
 *
 * Text Domain: wpio
 * Domain Path: /languages
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
            esc_html__('Image Optimizer', 'wpio'),
            ['back_link' => true]
        );
    }

    public static function maybe_convert_upload_to_webp(array $upload): array {
        if (empty($upload['file']) || empty($upload['type'])) {
            return $upload;
        }

        $file = (string)$upload['file'];
        $mime = (string)$upload['type'];

        if (!is_file($file)) {
            return $upload;
        }

        // Prefer content-based detection when available (protects against spoofed MIME/ext)
        $detected_mime = $mime;
        if (function_exists('wp_check_filetype_and_ext')) {
            try {
                $checked = wp_check_filetype_and_ext($file, basename($file));
                if (is_array($checked) && !empty($checked['type']) && is_string($checked['type'])) {
                    $detected_mime = $checked['type'];
                }
            }
            catch (Throwable) {
                // ignore
            }
        }

        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        $is_webp_ext = ($ext === 'webp');

        if (!$is_webp_ext && empty(self::CONVERTIBLE_MIME_TYPES[$detected_mime])) {
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

        self::maybe_fix_exif_orientation($file, $detected_mime, $editor);

        $quality = in_array($detected_mime, ['image/png', 'image/gif'], true)
            ? self::WEBP_QUALITY_ALPHA
            : self::WEBP_QUALITY_PHOTO;

        if (method_exists($editor, 'set_quality')) {
            $editor->set_quality($quality);
        }

        /**
         * WebP is treated differently on purpose:
         * - If the uploaded file is already named ".webp", we re-encode it IN PLACE to keep the same filename/URL.
         *   Otherwise, generating a new "unique" filename would typically force "image-1.webp" because "image.webp"
         *   already exists at upload time.
         * - We only do in-place re-encoding when the extension is truly ".webp" to avoid mismatches like writing WebP
         *   bytes into a ".jpg" file path.
         */
        if ($is_webp_ext) {
            $saved = $editor->save($file, 'image/webp');
            if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) {
                return $upload;
            }

            $upload['file'] = $saved['path'];
            $upload['type'] = 'image/webp';

            // URL stays the same (same filename).
            return $upload;
        }

        // Otherwise convert to WebP using WP unique naming.
        $target = self::unique_webp_target_path($file);

        $saved = $editor->save($target, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) {
            return $upload;
        }

        // Replace original (no double storage).
        self::delete_file($file);

        $upload['file'] = $saved['path'];
        $upload['type'] = 'image/webp';

        if (!empty($upload['url'])) {
            $upload['url'] = self::replace_url_basename((string)$upload['url'], basename($saved['path']));
        }

        return $upload;
    }

    private static function unique_webp_target_path(string $originalPath): string {
        $dir = dirname($originalPath);
        $base = pathinfo($originalPath, PATHINFO_FILENAME);

        $uniqueFilename = wp_unique_filename($dir, $base . '.webp');

        return $dir . DIRECTORY_SEPARATOR . $uniqueFilename;
    }

    private static function replace_url_basename(string $url, string $newBasename): string {
        return preg_replace('~[^/?#]+(?=([?#]|$))~', $newBasename, $url) ?? $url;
    }

    private static function delete_file(string $path): void {
        if (!is_file($path)) {
            return;
        }

        try {
            if (function_exists('wp_delete_file')) {
                @wp_delete_file($path);

                return;
            }

            @unlink($path);
        }
        catch (Throwable) {
            // ignore
        }
    }

    private static function is_animated_gif(string $path): bool {
        $contents = @file_get_contents($path, false, null, 0, 1024 * 200);
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

    private static function maybe_fix_exif_orientation(string $file, string $mime, object $editor): void {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return;
        }

        try {
            $exif = @exif_read_data($file);
            if (!is_array($exif) || empty($exif['Orientation'])) {
                return;
            }

            $rotate = match ((int)$exif['Orientation']) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };

            if ($rotate !== 0 && method_exists($editor, 'rotate')) {
                $editor->rotate($rotate);
            }
        }
        catch (Throwable) {
            // ignore
        }
    }
}

register_activation_hook(__FILE__, [WP_Image_Optimizer::class, 'activate']);
add_action('plugins_loaded', [WP_Image_Optimizer::class, 'boot']);
