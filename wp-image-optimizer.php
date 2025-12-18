<?php
/**
 * Plugin Name: Image Optimizer
 * Description: Converts uploaded images to WebP and replaces the original file (no duplicates). Zero-config.
 * Version: 0.1.0
 * Author: Mikel
 * Author URI: https://basterrika.com
 *
 * Requires PHP: 8.4
 * Requires at least: 6.5
 * Tested up to: 6.9
 *
 *  Text Domain: wpio
 *  Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Image_Optimizer {
    private const int DEFAULT_WEBP_QUALITY_PHOTO = 82; // Visually-lossless-ish for photos
    private const int DEFAULT_WEBP_QUALITY_ALPHA = 100; // Preserve edges/alpha better
    private const true DISABLE_BIG_IMAGE_SCALING = true; // Avoid "-scaled" originals

    private static array $convertible_mime_types = [
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

        $supports = wp_image_editor_supports([
            'mime_type' => 'image/webp',
        ]);

        if (!$supports) {
            self::fail_activation('This server cannot generate WebP images (GD/Imagick WebP support missing).');
        }
    }

    private static function fail_activation(string $message): void {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html($message),
            esc_html__('WP Image Optimizer', 'wpio'),
            ['back_link' => true]
        );
    }

    public static function maybe_convert_upload_to_webp(array $upload): array {
        if (empty($upload['file']) || empty($upload['type'])) {
            return $upload;
        }

        $file = $upload['file'];
        $mime = $upload['type'];

        if ($mime === 'image/webp' || str_ends_with(strtolower($file), '.webp')) {
            return $upload;
        }

        // Only handle known raster types.
        $convertible = (array)apply_filters('wpio_convertible_mime_types', self::$convertible_mime_types);
        if (empty($convertible[$mime])) {
            return $upload;
        }

        // Skip animated GIFs (core editors typically flatten them)
        if ($mime === 'image/gif' && self::is_animated_gif($file)) {
            return $upload;
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return $upload; // Fail silently: do not break uploads
        }

        // Fix orientation for JPEG-like sources when possible (EXIF)
        self::maybe_fix_exif_orientation($file, $mime, $editor);

        // Strip metadata where editor supports it
        if (method_exists($editor, 'strip_meta')) {
            try {
                $editor->strip_meta();
            }
            catch (Throwable) {
                // ignore
            }
        }

        $quality = self::choose_quality($file, $mime);

        /**
         * Filter: adjust WebP quality (0-100).
         *
         * @param int $quality
         * @param string $mime
         * @param string $file
         */
        $quality = (int)apply_filters('wpio_webp_quality', $quality, $mime, $file);
        $quality = max(0, min(100, $quality));

        $target = self::replace_extension_with_webp($file);

        // Save WebP
        $saved = $editor->save($target, 'image/webp', ['quality' => $quality]);
        if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) {
            return $upload;
        }

        // Remove original (no double storage).
        self::safe_unlink($file);

        // Update upload info
        $upload['file'] = $saved['path'];
        $upload['type'] = 'image/webp';
        $upload['url'] = self::replace_url_extension_with_webp($upload['url']);

        return $upload;
    }

    private static function choose_quality(string $file, string $mime): int {
        // Prefer max quality for alpha/transparency sources.
        if ($mime === 'image/png') {
            return self::png_has_alpha($file) ? self::DEFAULT_WEBP_QUALITY_ALPHA : self::DEFAULT_WEBP_QUALITY_PHOTO;
        }

        if ($mime === 'image/gif') {
            return self::DEFAULT_WEBP_QUALITY_ALPHA;
        }

        return self::DEFAULT_WEBP_QUALITY_PHOTO;
    }

    private static function replace_extension_with_webp(string $path): string {
        $dir = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return $dir . DIRECTORY_SEPARATOR . $name . '.webp';
    }

    private static function replace_url_extension_with_webp(string $url): string {
        $parts = wp_parse_url($url);

        if (!is_array($parts) || empty($parts['path'])) {
            // Fallback: basic replace
            return preg_replace('~\.[a-zA-Z0-9]+$~', '.webp', $url) ?? $url;
        }

        $path = (string)$parts['path'];
        $path = preg_replace('~\.[a-zA-Z0-9]+$~', '.webp', $path) ?? $path;

        // Rebuild URL minimally.
        $rebuilt = '';
        if (!empty($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }

        if (!empty($parts['user'])) {
            $rebuilt .= $parts['user'];

            if (!empty($parts['pass'])) {
                $rebuilt .= ':' . $parts['pass'];
            }

            $rebuilt .= '@';
        }

        if (!empty($parts['host'])) {
            $rebuilt .= $parts['host'];
        }

        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }

        $rebuilt .= $path;

        if (!empty($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt ?: $url;
    }

    private static function safe_unlink(string $path): void {
        if (!is_file($path)) {
            return;
        }

        try {
            @unlink($path);
        }
        catch (Throwable) {
            // ignore
        }
    }

    private static function png_has_alpha(string $path): bool {
        if (!function_exists('imagecreatefrompng')) {
            return true; // safer default: preserve quality
        }

        try {
            $im = @imagecreatefrompng($path);
            if (!$im) {
                return true;
            }

            // If the image has a transparent color index, treat as alpha-like.
            $transparentIndex = imagecolortransparent($im);
            if ($transparentIndex >= 0) {
                imagedestroy($im);

                return true;
            }

            // Check alpha channel quickly by sampling a small grid.
            $w = imagesx($im);
            $h = imagesy($im);

            $steps = 8; // 8x8 samples
            for ($xi = 0; $xi <= $steps; $xi++) {
                for ($yi = 0; $yi <= $steps; $yi++) {
                    $x = (int)floor(($w - 1) * ($xi / $steps));
                    $y = (int)floor(($h - 1) * ($yi / $steps));

                    $rgba = imagecolorat($im, $x, $y);

                    // For truecolor PNG, alpha is highest 7 bits of the 32-bit int (0 opaque, 127 transparent).
                    $alpha = ($rgba & 0x7F000000) >> 24;

                    if ($alpha > 0) {
                        imagedestroy($im);

                        return true;
                    }
                }
            }

            imagedestroy($im);

            return false;
        }
        catch (Throwable) {
            return true;
        }
    }

    private static function is_animated_gif(string $path): bool {
        // Look for multiple frame headers.
        // Based on the common pattern of graphic control extension + image descriptor.
        $contents = @file_get_contents($path, false, null, 0, 1024 * 200); // read first 200KB

        if ($contents === false) {
            return false;
        }

        $count = 0;
        $pattern = "\x00\x21\xF9\x04"; // Graphic Control Extension
        $pos = 0;

        while (true) {
            $pos = strpos($contents, $pattern, $pos);

            if ($pos === false) {
                break;
            }

            $count++;

            if ($count > 1) {
                return true;
            }

            $pos += 4;
        }

        return false;
    }

    private static function maybe_fix_exif_orientation(string $file, string $mime, object $editor): void {
        // Only relevant for JPEG-ish sources; HEIC might carry orientation too but handling varies per server/editor.
        if ($mime !== 'image/jpeg') {
            return;
        }

        if (!function_exists('exif_read_data')) {
            return;
        }

        try {
            $exif = @exif_read_data($file);
            if (!is_array($exif) || empty($exif['Orientation'])) {
                return;
            }

            $orientation = (int)$exif['Orientation'];
            $rotate = match ($orientation) {
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
