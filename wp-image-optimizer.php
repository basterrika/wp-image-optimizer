<?php
/**
 * Plugin Name: Image Optimizer
 * Description: Converts uploaded images to WebP (optimized) and replaces the original. Zero-config.
 * Version: 1.2.0
 * Author: Mikel
 * Author URI: https://basterrika.com
 * Update URI: https://github.com/basterrika/wp-image-optimizer
 * Requires PHP: 8.4
 * Requires at least: 7.0
 * Tested up to: 7.1
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Image_Optimizer {
    private const int MAX_IMAGE_DIMENSION = 2560;
    private const array CONVERTIBLE_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/heic' => true,
        'image/heif' => true,
        'image/webp' => true
    ];

    public static function boot(): void {
        // Also fires for sideloads.
        add_filter('wp_handle_upload', [self::class, 'maybe_convert_upload_to_webp'], 20);

        // Scaling happens before WebP save. Prevent core from adding a "-scaled" file.
        add_filter('big_image_size_threshold', '__return_false');
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
        $detected_mime = $upload['type'];

        if (!isset(self::CONVERTIBLE_MIME_TYPES[$detected_mime])) {
            return $upload;
        }

        $is_webp_ext = str_ends_with(strtolower($file), '.webp');

        // The editor keeps only the first frame. Never flatten an animation.
        if (self::is_animated($file, $detected_mime)) {
            return $upload;
        }

        $editor = wp_get_image_editor($file, ['output_mime_type' => 'image/webp']);

        if (is_wp_error($editor) || !$editor::supports_mime_type('image/webp')) {
            return $upload;
        }

        $output_format = wp_get_image_editor_output_format($file, 'image/webp');

        if (($output_format['image/webp'] ?? 'image/webp') !== 'image/webp') {
            return $upload;
        }

        // Stripping would drop the orientation tag of an image that couldn't be turned upright.
        if (is_wp_error($editor->maybe_exif_rotate())) {
            return $upload;
        }

        $was_resized = false;

        if (max($editor->get_size()) > self::MAX_IMAGE_DIMENSION) {
            $resized = $editor->resize(self::MAX_IMAGE_DIMENSION, self::MAX_IMAGE_DIMENSION);

            if (is_wp_error($resized)) {
                return $upload;
            }

            $was_resized = true;
        }

        self::strip_metadata($editor);

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

        return self::convert_to_webp($editor, $file, $upload, $was_resized);
    }

    private static function save_in_place(WP_Image_Editor $editor, string $file, array $upload): array {
        // Write next to the original, then swap atomically, so a failed or partial write never touches it.
        $temp = $file . '.tmp.webp';

        // Delete the temp file when the request ends, even if PHP crashes. After a successful save it's already gone.
        register_shutdown_function('wp_delete_file', $temp);

        $saved = $editor->save($temp, 'image/webp');

        if (!self::is_valid_webp($saved, $temp) || !rename($temp, $file)) {
            return $upload;
        }

        $upload['file'] = $file;
        $upload['type'] = 'image/webp';

        // URL stays the same (same filename)
        return $upload;
    }

    private static function convert_to_webp(WP_Image_Editor $editor, string $file, array $upload, bool $was_resized): array {
        $target = self::reserve_webp_target_path($file);

        if ($target === null) {
            return $upload;
        }

        $saved = $editor->save($target, 'image/webp');

        if (!self::is_valid_webp($saved, $target)) {
            // The target is a fresh .webp name, never the original.
            wp_delete_file($target);

            return $upload;
        }

        // Keep the original when conversion didn't shrink it (e.g. flat PNGs).
        // Only comparable when pixels are unchanged. A resize always wins.
        // On stat failure, fall through and keep the WebP.
        $original_size = filesize($file);

        if (
            !$was_resized &&
            $original_size !== false &&
            $saved['filesize'] >= $original_size
        ) {
            wp_delete_file($saved['path']);

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

    /**
     * Drops EXIF (GPS, camera serial…), XMP and IPTC. Keeps the ICC color profile. Sub-sizes are built from the
     * saved file, so they come out clean too. GD never carries metadata. Core's Imagick strip_meta() keeps
     * EXIF/XMP/IPTC on purpose. Opt out with `add_filter('wp_image_optimizer_strip_metadata', '__return_false')`.
     */
    private static function strip_metadata(WP_Image_Editor $editor): void {
        if (!$editor instanceof WP_Image_Editor_Imagick || !apply_filters('wp_image_optimizer_strip_metadata', true)) {
            return;
        }

        // The Imagick instance is protected. Bind to the editor to reach it.
        (function(): void {
            try {
                foreach ($this->image->getImageProfiles('*', false) as $name) {
                    if ($name !== 'icc' && $name !== 'icm') {
                        $this->image->removeImageProfile($name);
                    }
                }
            }
            catch (Throwable) {
                // The upload shouldn't fail over metadata.
            }
        })->call($editor);
    }

    /**
     * Saving can report success even when the WebP is not usable. Known cases:
     * - Some PNGs are saved as an empty file.
     * - If the server can't make WebP, WordPress quietly saves a JPEG or PNG instead.
     * - A full disk can leave a file that was cut off partway.
     *
     * A WebP file starts with "RIFF", its size, then "WEBP". If that size doesn't match the real one, it's incomplete.
     */
    private static function is_valid_webp(array|WP_Error $saved, string $expected_path): bool {
        if (is_wp_error($saved) || ($saved['mime-type'] ?? null) !== 'image/webp') {
            return false;
        }

        clearstatcache(true, $expected_path);

        $header = @file_get_contents($expected_path, false, null, 0, 12);

        return is_string($header)
               && strlen($header) === 12
               && str_starts_with($header, 'RIFF')
               && substr($header, 8, 4) === 'WEBP'
               && unpack('V', $header, 4)[1] + 8 === filesize($expected_path);
    }

    /**
     * Two uploads at the same moment (photo.jpg and photo.png) could both pick photo.webp.
     * Creating the empty file claims the name. If someone else got there first, pick the next free name.
     */
    private static function reserve_webp_target_path(string $original_path): ?string {
        $dir = dirname($original_path);
        $base = pathinfo($original_path, PATHINFO_FILENAME);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = trailingslashit($dir) . wp_unique_filename($dir, $base . '.webp');
            $handle = @fopen($path, 'xb');

            if ($handle !== false) {
                fclose($handle);

                return $path;
            }
        }

        return null;
    }

    /**
     * Unreadable files count as animated, so they are left untouched.
     */
    private static function is_animated(string $path, string $mime): bool {
        return match ($mime) {
            'image/webp' => self::is_animated_webp($path),
            'image/png' => self::is_animated_png($path),
            default => false,
        };
    }

    private static function is_animated_webp(string $path): bool {
        $header = @file_get_contents($path, false, null, 0, 21);

        if ($header === false || strlen($header) < 21) {
            return true;
        }

        // Extended format (VP8X) carries an animation flag. Simple VP8/VP8L files are always still.
        return substr($header, 12, 4) === 'VP8X' && (ord($header[20]) & 0x02) !== 0;
    }

    private static function is_animated_png(string $path): bool {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return true;
        }

        // APNG declares an acTL chunk before the first IDAT. Walk chunk headers only.
        fseek($handle, 8);
        $animated = false;

        while (strlen($chunk = (string)fread($handle, 8)) === 8) {
            $type = substr($chunk, 4, 4);

            if ($type === 'acTL' || $type === 'IDAT') {
                $animated = $type === 'acTL';
                break;
            }

            fseek($handle, unpack('N', $chunk)[1] + 4, SEEK_CUR);
        }

        fclose($handle);

        return $animated;
    }
}

register_activation_hook(__FILE__, [WP_Image_Optimizer::class, 'activate']);
WP_Image_Optimizer::boot();
