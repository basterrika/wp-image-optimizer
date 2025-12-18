<?php
/**
 * Plugin Name: Image Optimizer
 * Description: Converts uploaded images to WebP (optimized) and replaces the original. Zero-config.
 * Version: 0.4.0
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

    private const int WEBP_QUALITY_PHOTO = 85; // JPEG/HEIC/HEIF
    private const int WEBP_QUALITY_ALPHA = 90; // PNG/GIF (better edges/alpha)

    private const array CONVERTIBLE_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/gif' => true, // non-animated only
        'image/heic' => true,
        'image/heif' => true,
    ];

    public static function boot(): void {
        add_filter('wp_handle_upload', [self::class, 'maybe_convert_upload_to_webp'], 20);

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

        if ($mime === 'image/webp' || str_ends_with(strtolower($file), '.webp')) {
            return $upload;
        }

        if (empty(self::CONVERTIBLE_MIME_TYPES[$mime]) || !is_file($file)) {
            return $upload;
        }

        // Avoid flattening animated GIFs.
        if ($mime === 'image/gif' && self::is_animated_gif($file)) {
            return $upload;
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return $upload;
        }

        self::maybe_fix_exif_orientation($file, $mime, $editor);

        $quality = in_array($mime, ['image/png', 'image/gif'], true)
            ? self::WEBP_QUALITY_ALPHA
            : self::WEBP_QUALITY_PHOTO;

        if (method_exists($editor, 'set_quality')) {
            $editor->set_quality($quality);
        }

        $target = self::unique_webp_target_path($file);

        $saved = $editor->save($target, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) {
            return $upload;
        }

        // Replace original (no double storage).
        self::delete_file($file);

        $upload['file'] = $saved['path'];
        $upload['type'] = 'image/webp';
        $upload['url'] = self::replace_url_basename((string)$upload['url'], basename($saved['path']));

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
