<?php
declare(strict_types=1);

if (!defined('BACKEND_PATH')) return;

const JY_PWA_VERSION = '1.1.1';
const JY_PWA_STATIC = '/static/plugins/pwa';
const JY_PWA_DEFAULT_ICON_180 = JY_PWA_STATIC . '/icon-180.png';
const JY_PWA_DEFAULT_ICON_192 = JY_PWA_STATIC . '/icon-192.png';
const JY_PWA_DEFAULT_ICON_512 = JY_PWA_STATIC . '/icon-512.png';
const JY_PWA_DEFAULT_ICON_MASKABLE = JY_PWA_STATIC . '/icon-maskable-512.png';
const JY_PWA_MAX_SOURCE_PIXELS = 12000000;
const JY_PWA_MAX_SOURCE_BYTES = 16 * 1024 * 1024;

function jy_pwa_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function jy_pwa_t(string $source, mixed ...$args): string
{
    $translated = function_exists('__') ? __($source, 'pwa') : $source;
    if ($translated === $source) {
        $locale = function_exists('get_locale') ? strtolower(get_locale()) : 'en';
        $locale = explode('-', str_replace('_', '-', $locale), 2)[0];
        if (in_array($locale, ['id', 'de'], true)) {
            static $catalogs = [];
            if (!isset($catalogs[$locale])) {
                $file = __DIR__ . '/languages/' . $locale . '.php';
                $catalog = is_file($file) ? require $file : [];
                $catalogs[$locale] = is_array($catalog) ? $catalog : [];
            }
            $translated = (string)($catalogs[$locale][$source] ?? $source);
        }
    }
    return $args === [] ? $translated : sprintf($translated, ...$args);
}

function jy_pwa_setting(PDO $pdo, string $key, string $default = ''): string
{
    if (!function_exists('settings_get')) return $default;
    return (string)(settings_get($pdo, 'pwa_' . $key, $default) ?? $default);
}

function jy_pwa_site_default(PDO $pdo, string $key, string $default): string
{
    if (!function_exists('settings_get')) return $default;
    $value = trim((string)(settings_get($pdo, $key, $default) ?? $default));
    return $value !== '' ? $value : $default;
}

function jy_pwa_defaults(PDO $pdo): array
{
    $name = jy_pwa_site_default($pdo, 'site_title', 'Jyavani');
    $shortName = mb_substr($name, 0, 12, 'UTF-8');
    $description = jy_pwa_site_default($pdo, 'site_description', 'Install this site for quick, reliable access.');

    return [
        'name' => $name,
        'short_name' => $shortName,
        'description' => $description,
        'id' => '/',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'theme_color' => '#111827',
        'background_color' => '#ffffff',
        'icon_source_url' => '',
        'icon_source_id' => '0',
        'icon_mode' => 'crop',
        'icon_180_url' => JY_PWA_DEFAULT_ICON_180,
        'icon_180_id' => '0',
        'icon_192_url' => JY_PWA_DEFAULT_ICON_192,
        'icon_192_id' => '0',
        'icon_512_url' => JY_PWA_DEFAULT_ICON_512,
        'icon_512_id' => '0',
        'icon_maskable_url' => JY_PWA_DEFAULT_ICON_MASKABLE,
        'icon_maskable_id' => '0',
    ];
}

function jy_pwa_settings(PDO $pdo): array
{
    $settings = jy_pwa_defaults($pdo);
    foreach ($settings as $key => $default) {
        $settings[$key] = jy_pwa_setting($pdo, $key, $default);
    }
    // Preserve v1.0 icon settings until the administrator changes generator inputs.
    if ($settings['icon_source_url'] === '') {
        foreach ([
            'icon_512' => JY_PWA_DEFAULT_ICON_512,
            'icon_maskable' => JY_PWA_DEFAULT_ICON_MASKABLE,
            'icon_192' => JY_PWA_DEFAULT_ICON_192,
            'icon_180' => JY_PWA_DEFAULT_ICON_180,
        ] as $key => $default) {
            if ($settings[$key . '_url'] === '' || $settings[$key . '_url'] === $default) continue;
            $settings['icon_source_url'] = $settings[$key . '_url'];
            $settings['icon_source_id'] = $settings[$key . '_id'];
            break;
        }
    }
    return $settings;
}

function jy_pwa_request_origin(): string
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $scheme . '://' . ($host !== '' ? $host : 'localhost');
}

function jy_pwa_effective_port(array $parts): int
{
    if (isset($parts['port'])) return (int)$parts['port'];
    return strtolower((string)($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
}

function jy_pwa_normalize_path_url(string $value, bool $directory = false): ?string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, "\0") || str_starts_with($value, '//')) return null;

    if (preg_match('#^https?://#i', $value)) {
        $parts = parse_url($value);
        $origin = parse_url(jy_pwa_request_origin());
        if (!is_array($parts) || !is_array($origin)
            || strtolower((string)($parts['scheme'] ?? '')) !== strtolower((string)($origin['scheme'] ?? ''))
            || strtolower((string)($parts['host'] ?? '')) !== strtolower((string)($origin['host'] ?? ''))
            || jy_pwa_effective_port($parts) !== jy_pwa_effective_port($origin)) {
            return null;
        }
        $value = (string)($parts['path'] ?? '/');
        if (isset($parts['query']) && $parts['query'] !== '') $value .= '?' . $parts['query'];
    }

    if (!str_starts_with($value, '/') || preg_match('/[\r\n]/', $value)) return null;
    $parts = parse_url($value);
    if (!is_array($parts) || isset($parts['fragment'])) return null;
    $path = (string)($parts['path'] ?? '/');
    $decodedPath = rawurldecode($path);
    if (str_contains($decodedPath, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decodedPath)
        || preg_match('#(?:^|/)\.\.?(/|$)#', $decodedPath)) return null;
    if ($directory) {
        $path = rtrim($path, '/') . '/';
        return $path === '//' ? '/' : $path;
    }
    return $value;
}

function jy_pwa_normalize_image_url(string $value): ?string
{
    $url = jy_pwa_normalize_path_url($value);
    if ($url === null) return null;
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    if ($path === '' || str_starts_with($path, '/private/')) return null;
    return $url;
}

function jy_pwa_normalize_color(string $value): ?string
{
    $value = strtolower(trim($value));
    if (preg_match('/^#[0-9a-f]{6}$/', $value)) return $value;
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $match)) {
        return '#' . $match[1] . $match[1] . $match[2] . $match[2] . $match[3] . $match[3];
    }
    return null;
}

function jy_pwa_sensitive_path(PDO $pdo, string $url): bool
{
    $path = '/' . trim(strtolower(rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?? ''))), '/');
    $blocked = ['/admin', '/api', '/private'];
    foreach (['get_admin_path', 'get_login_path', 'get_register_path'] as $resolver) {
        if (!function_exists($resolver)) continue;
        try {
            $resolved = '/' . trim(strtolower((string)$resolver($pdo)), '/');
            if ($resolved !== '/') $blocked[] = $resolved;
        } catch (Throwable $error) {
            // Core route helpers may be unavailable during an incomplete install.
        }
    }
    foreach (array_unique($blocked) as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) return true;
    }
    return preg_match('#/(?:api|private)(?:/|$)#', $path) === 1;
}

function jy_pwa_public_icon_file(string $url): ?string
{
    if (!defined('PUBLIC_PATH')) return null;
    $public = realpath(PUBLIC_PATH);
    if ($public === false) return null;
    $path = rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) return null;
    $candidate = rtrim(PUBLIC_PATH, '/\\') . '/' . ltrim($path, '/');
    if (is_link($candidate)) return null;
    $real = realpath($candidate);
    if ($real === false || !is_file($real) || ($real !== $public && !str_starts_with($real, $public . DIRECTORY_SEPARATOR))) return null;
    return $real;
}

function jy_pwa_validate_icon_file(string $url, int $size, string $label, array &$errors): bool
{
    $file = jy_pwa_public_icon_file($url);
    if ($file === null || !is_readable($file)) {
        $errors[] = jy_pwa_t('%s must resolve to a readable file in the public web root.', $label);
        return false;
    }
    $image = @getimagesize($file);
    if (!is_array($image) || ($image[2] ?? null) !== IMAGETYPE_PNG || strtolower((string)($image['mime'] ?? '')) !== 'image/png') {
        $errors[] = jy_pwa_t('%s must be a valid PNG file.', $label);
        return false;
    }
    if ((int)$image[0] !== $size || (int)$image[1] !== $size) {
        $errors[] = jy_pwa_t('%s must be exactly %dx%d pixels.', $label, $size, $size);
        return false;
    }
    return true;
}

function jy_pwa_validate_media_source(PDO $pdo, int $id, string $url, array &$errors): array
{
    if ($id < 1 && trim($url) === '') return ['id' => '0', 'url' => '', 'file' => null];

    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('SELECT url, mime, visibility, storage_disk, access_scope FROM media WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $media = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $error) {
            $media = false;
        }
        if (!is_array($media)) {
            $errors[] = jy_pwa_t('The Media Library image was not found.');
            return ['id' => (string)$id, 'url' => $url, 'file' => null];
        }
        if (!str_starts_with(strtolower((string)($media['mime'] ?? '')), 'image/')) {
            $errors[] = jy_pwa_t('Choose an image from the Media Library.');
        }
        if ((string)($media['visibility'] ?? 'public') !== 'public'
            || (string)($media['storage_disk'] ?? 'public') !== 'public'
            || (string)($media['access_scope'] ?? 'public') !== 'public') {
            $errors[] = jy_pwa_t('The source image must be public media.');
        }
        $url = (string)($media['url'] ?? '');
    }

    $normalized = jy_pwa_normalize_image_url($url);
    if ($normalized === null) {
        $errors[] = jy_pwa_t('The source image must use a same-origin public URL.');
    } elseif (jy_pwa_sensitive_path($pdo, $normalized)) {
        $errors[] = jy_pwa_t('The source image cannot use an admin, authentication, private, or API URL.');
    } else {
        $url = $normalized;
        $file = jy_pwa_public_icon_file($url);
        if ($file === null || !is_readable($file)) {
            $errors[] = jy_pwa_t('The source image must resolve to a readable file in the public web root.');
        } else {
            $image = @getimagesize($file);
            $width = is_array($image) ? (int)($image[0] ?? 0) : 0;
            $height = is_array($image) ? (int)($image[1] ?? 0) : 0;
            if ($width < 1 || $height < 1 || !str_starts_with(strtolower((string)($image['mime'] ?? '')), 'image/')) {
                $errors[] = jy_pwa_t('The source image is not a supported image file.');
            } elseif (!jy_pwa_source_fits_limits($file, $width, $height)) {
                $errors[] = jy_pwa_t('The source image is too large to process safely.');
            } else {
                return ['id' => (string)max(0, $id), 'url' => $url, 'file' => $file];
            }
        }
    }
    return ['id' => (string)max(0, $id), 'url' => $url, 'file' => null];
}

function jy_pwa_memory_limit_bytes(): int
{
    $value = strtolower(trim((string)ini_get('memory_limit')));
    if ($value === '' || $value === '-1') return PHP_INT_MAX;
    $number = (float)$value;
    return (int)round($number * match (substr($value, -1)) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    });
}

function jy_pwa_source_fits_limits(string $file, int $width, int $height): bool
{
    $fileSize = @filesize($file);
    if ($fileSize === false || $fileSize > JY_PWA_MAX_SOURCE_BYTES || $width < 1 || $height < 1
        || $width * $height > JY_PWA_MAX_SOURCE_PIXELS) return false;
    $memoryLimit = jy_pwa_memory_limit_bytes();
    if ($memoryLimit === PHP_INT_MAX) return true;
    $available = max(0, $memoryLimit - memory_get_usage(true));
    return $width * $height * 6 + 8 * 1024 * 1024 <= $available;
}

function jy_pwa_generated_directory(): string
{
    if (!defined('PUBLIC_PATH')) throw new RuntimeException('Public web root is unavailable.');
    $public = realpath(PUBLIC_PATH);
    $pluginStatic = rtrim(PUBLIC_PATH, '/\\') . '/static/plugins/pwa';
    $pluginReal = realpath($pluginStatic);
    if ($public === false || $pluginReal === false || !is_dir($pluginReal)
        || ($pluginReal !== $public && !str_starts_with($pluginReal, $public . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('PWA static directory is unavailable.');
    }
    $directory = $pluginReal . '/generated';
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0755))) {
        throw new RuntimeException('Could not create the generated icon directory.');
    }
    $real = realpath($directory);
    if ($real === false || !str_starts_with($real, $pluginReal . DIRECTORY_SEPARATOR) || !is_writable($real)) {
        throw new RuntimeException('Generated icon directory is not writable.');
    }
    return $real;
}

function jy_pwa_render_icon(GdImage $source, int $size, string $mode, string $background, bool $maskable = false): GdImage
{
    if (!in_array($mode, ['crop', 'contain'], true) || jy_pwa_normalize_color($background) !== $background) {
        throw new InvalidArgumentException('Invalid icon generation options.');
    }
    $canvas = imagecreatetruecolor($size, $size);
    if (!$canvas instanceof GdImage) throw new RuntimeException('Could not create an icon canvas.');
    [$red, $green, $blue] = sscanf($background, '#%02x%02x%02x');
    $color = imagecolorallocate($canvas, (int)$red, (int)$green, (int)$blue);
    imagefill($canvas, 0, 0, $color);
    imagealphablending($canvas, true);

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    // A square with this side length fits inside the maskable 40%-radius circle.
    $box = $maskable ? (int)floor($size * 0.8 / sqrt(2)) : $size;
    $surface = imagecreatetruecolor($box, $box);
    if (!$surface instanceof GdImage) {
        imagedestroy($canvas);
        throw new RuntimeException('Could not create an icon surface.');
    }
    $surfaceColor = imagecolorallocate($surface, (int)$red, (int)$green, (int)$blue);
    imagefill($surface, 0, 0, $surfaceColor);
    imagealphablending($surface, true);
    $scale = $mode === 'contain'
        ? min($box / $sourceWidth, $box / $sourceHeight)
        : max($box / $sourceWidth, $box / $sourceHeight);
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));
    $targetX = (int)floor(($box - $targetWidth) / 2);
    $targetY = (int)floor(($box - $targetHeight) / 2);
    if (!imagecopyresampled($surface, $source, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)
        || !imagecopy($canvas, $surface, (int)floor(($size - $box) / 2), (int)floor(($size - $box) / 2), 0, 0, $box, $box)) {
        imagedestroy($surface);
        imagedestroy($canvas);
        throw new RuntimeException('Could not resize the source image.');
    }
    imagedestroy($surface);
    return $canvas;
}

function jy_pwa_generate_icons(string $sourceFile, string $mode, string $background, string $directory, string $urlBase = JY_PWA_STATIC . '/generated'): array
{
    $info = @getimagesize($sourceFile);
    if (!is_array($info) || !jy_pwa_source_fits_limits($sourceFile, (int)$info[0], (int)$info[1])) {
        throw new RuntimeException('The source image is too large or invalid.');
    }
    $loader = match ((int)($info[2] ?? 0)) {
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_GIF => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        IMAGETYPE_AVIF => 'imagecreatefromavif',
        default => '',
    };
    $source = $loader !== '' && function_exists($loader) ? @$loader($sourceFile) : false;
    if (!$source instanceof GdImage) throw new RuntimeException('The server cannot decode the source image.');

    $sourceHash = hash_file('sha256', $sourceFile);
    if (!is_string($sourceHash)) {
        imagedestroy($source);
        throw new RuntimeException('Could not hash the source image.');
    }
    $digest = substr(hash('sha256', "pwa-icons-v1\0" . $mode . "\0" . $background . "\0" . $sourceHash), 0, 16);
    $specifications = [
        'icon_180' => [180, false],
        'icon_192' => [192, false],
        'icon_512' => [512, false],
        'icon_maskable' => [512, true],
    ];
    $urls = [];
    try {
        foreach ($specifications as $key => [$size, $maskable]) {
            $suffix = $maskable ? 'maskable-512' : (string)$size;
            $filename = 'icon-' . $suffix . '-' . $digest . '.png';
            $destination = rtrim($directory, '/\\') . '/' . $filename;
            $existing = is_file($destination) ? @getimagesize($destination) : false;
            if (is_file($destination) && (!is_array($existing) || (int)$existing[0] !== $size || (int)$existing[1] !== $size
                || strtolower((string)($existing['mime'] ?? '')) !== 'image/png')) {
                @unlink($destination);
            }
            if (!is_file($destination)) {
                $icon = jy_pwa_render_icon($source, $size, $mode, $background, $maskable);
                $temporary = tempnam($directory, '.pwa-');
                if ($temporary === false || !imagepng($icon, $temporary, 6)) {
                    imagedestroy($icon);
                    if (is_string($temporary)) @unlink($temporary);
                    throw new RuntimeException('Could not write a generated icon.');
                }
                imagedestroy($icon);
                chmod($temporary, 0644);
                if (!rename($temporary, $destination)) {
                    @unlink($temporary);
                    throw new RuntimeException('Could not publish a generated icon.');
                }
            }
            $urls[$key . '_url'] = rtrim($urlBase, '/') . '/' . $filename;
        }
    } finally {
        imagedestroy($source);
    }
    return $urls;
}

function jy_pwa_prune_generated_icons(array $activeUrls, int $retainedSets = 3): void
{
    $directory = jy_pwa_generated_directory();
    $sets = [];
    foreach (glob($directory . '/icon-*.png') ?: [] as $file) {
        if (!preg_match('/^icon-(?:180|192|512|maskable-512)-([a-f0-9]{16})\.png$/', basename($file), $match)) continue;
        $sets[$match[1]]['files'][] = $file;
        $sets[$match[1]]['time'] = max((int)($sets[$match[1]]['time'] ?? 0), (int)filemtime($file));
    }
    uasort($sets, static fn(array $left, array $right): int => $right['time'] <=> $left['time']);
    $keep = [];
    foreach ($activeUrls as $url) {
        if (preg_match('/-([a-f0-9]{16})\.png$/', (string)parse_url((string)$url, PHP_URL_PATH), $match)) $keep[$match[1]] = true;
    }
    foreach (array_keys($sets) as $digest) {
        if (count($keep) >= max(1, $retainedSets)) break;
        $keep[$digest] = true;
    }
    foreach ($sets as $digest => $set) {
        if (isset($keep[$digest])) continue;
        foreach ($set['files'] as $file) @unlink($file);
    }
}

function jy_pwa_uninstall_cleanup(string $name): void
{
    if ($name !== 'pwa') return;
    try {
        $directory = jy_pwa_generated_directory();
        foreach (glob($directory . '/icon-*.png') ?: [] as $file) {
            if (preg_match('/^icon-(?:180|192|512|maskable-512)-[a-f0-9]{16}\.png$/', basename($file))) @unlink($file);
        }
        @rmdir($directory);
    } catch (Throwable $error) {
        error_log('[pwa] Generated icon cleanup failed: ' . $error->getMessage());
    }
}

function jy_pwa_manifest(PDO $pdo): array
{
    $settings = jy_pwa_settings($pdo);
    $icons = [
        ['src' => $settings['icon_180_url'], 'sizes' => '180x180', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $settings['icon_192_url'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $settings['icon_512_url'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ];
    if ($settings['icon_maskable_url'] !== '') {
        $icons[] = ['src' => $settings['icon_maskable_url'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
    }

    return [
        'id' => $settings['id'],
        'start_url' => $settings['start_url'],
        'scope' => $settings['scope'],
        'name' => $settings['name'],
        'short_name' => $settings['short_name'],
        'description' => $settings['description'],
        'display' => $settings['display'],
        'orientation' => $settings['orientation'],
        'theme_color' => $settings['theme_color'],
        'background_color' => $settings['background_color'],
        'icons' => $icons,
    ];
}

function jy_pwa_manifest_route(PDO $pdo): void
{
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    if ($path !== '/manifest.webmanifest') {
        http_response_code(404);
        return;
    }

    $json = json_encode(jy_pwa_manifest($pdo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $etag = '"' . hash('sha256', $json) . '"';
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=300, must-revalidate');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        return;
    }
    echo $json;
}

function jy_pwa_offline_route(PDO $pdo): void
{
    $path = rtrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'), '/');
    if ($path !== '/pwa-offline') {
        http_response_code(404);
        return;
    }
    $settings = jy_pwa_settings($pdo);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300, must-revalidate');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
    header('X-Content-Type-Options: nosniff');
    ?><!doctype html>
<html lang="<?= jy_pwa_h(function_exists('content_default_locale') ? content_default_locale() : 'en') ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="<?= jy_pwa_h($settings['theme_color']) ?>"><title><?= jy_pwa_h(jy_pwa_t('Offline')) ?> - <?= jy_pwa_h($settings['name']) ?></title>
<style>html{color-scheme:light dark}body{min-height:100vh;margin:0;display:grid;place-items:center;background:<?= jy_pwa_h($settings['background_color']) ?>;color:<?= jy_pwa_h($settings['theme_color']) ?>;font:16px/1.5 system-ui,sans-serif}.card{width:min(34rem,calc(100% - 3rem));text-align:center}.icon{width:96px;height:96px;border-radius:22px}h1{font-size:clamp(1.7rem,6vw,2.5rem);margin:.8rem 0 .35rem}p{margin:0;opacity:.75}</style></head>
<body><main class="card"><img class="icon" src="<?= jy_pwa_h($settings['icon_192_url']) ?>" alt=""><h1><?= jy_pwa_h(jy_pwa_t('You are offline')) ?></h1><p><?= jy_pwa_h(jy_pwa_t('Reconnect to the internet, then reload this page.')) ?></p></main></body></html><?php
}

function jy_pwa_service_worker(string $script): string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $script;
    $settings = jy_pwa_settings($pdo);
    $precache = array_values(array_unique(array_filter([
        '/pwa-offline/',
        '/manifest.webmanifest',
        $settings['icon_180_url'],
        $settings['icon_192_url'],
        $settings['icon_512_url'],
        $settings['icon_maskable_url'],
    ])));
    $revision = substr(hash('sha256', JY_PWA_VERSION . '|' . json_encode($settings)), 0, 16);
    $excludedPaths = ['/admin/', '/api/', '/private/'];
    foreach (['get_admin_path', 'get_login_path', 'get_register_path'] as $resolver) {
        if (!function_exists($resolver)) continue;
        try {
            $path = '/' . trim((string)$resolver($pdo), '/') . '/';
            if ($path !== '//') $excludedPaths[] = $path;
        } catch (Throwable $error) {
            // Keep the worker available while Core setup is incomplete.
        }
    }
    $config = json_encode([
        'cacheName' => 'jyavani-pwa-' . $revision,
        'cachePrefix' => 'jyavani-pwa-',
        'offlineUrl' => '/pwa-offline/',
        'manifestUrl' => '/manifest.webmanifest',
        'precache' => $precache,
        'excludedPaths' => array_values(array_unique($excludedPaths)),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $worker = file_get_contents(__DIR__ . '/assets/js/service-worker.js');
    if (!is_string($worker)) return $script;

    return $script . "\n;self.__JY_PWA_CONFIG = " . $config . ";\n" . $worker . "\ndelete self.__JY_PWA_CONFIG;\n";
}

function jy_pwa_manifest_url(string $url): string
{
    return '/manifest.webmanifest';
}

function jy_pwa_apple_icon_url(string $url): string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    return $pdo instanceof PDO ? jy_pwa_settings($pdo)['icon_180_url'] : JY_PWA_DEFAULT_ICON_180;
}

function jy_pwa_theme_color(string $color): string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    return $pdo instanceof PDO ? jy_pwa_settings($pdo)['theme_color'] : '#111827';
}

function jy_pwa_registration_script(): void
{
    echo '<script src="' . JY_PWA_STATIC . '/register.js?v=' . JY_PWA_VERSION . '" defer></script>' . PHP_EOL;
}

function jy_pwa_apple_metadata(): void
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $settings = jy_pwa_settings($pdo);
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . PHP_EOL;
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . PHP_EOL;
    echo '<meta name="apple-mobile-web-app-title" content="' . jy_pwa_h($settings['short_name']) . '">' . PHP_EOL;
}

function jy_pwa_admin_head(): void
{
    if (trim((string)($_GET['page'] ?? ''), '/') !== 'admin/settings/pwa') return;
    echo '<link rel="stylesheet" href="' . JY_PWA_STATIC . '/admin.css?v=' . JY_PWA_VERSION . '">' . PHP_EOL;
}

function jy_pwa_admin_footer(): void
{
    if (trim((string)($_GET['page'] ?? ''), '/') !== 'admin/settings/pwa') return;
    echo '<script src="' . JY_PWA_STATIC . '/admin.js?v=' . JY_PWA_VERSION . '"></script>' . PHP_EOL;
}

if (function_exists('register_frontend_route')) {
    register_frontend_route('manifest.webmanifest', 'jy_pwa_manifest_route');
    register_frontend_route('pwa-offline', 'jy_pwa_offline_route');
}

// Core 2.3.60 renders these values through filters so an active PWA owns one
// canonical manifest, Apple icon, and theme-color declaration.
add_filter('web_manifest_url', 'jy_pwa_manifest_url');
add_filter('apple_touch_icon_url', 'jy_pwa_apple_icon_url');
add_filter('theme_color', 'jy_pwa_theme_color');
add_filter('service_worker_script', 'jy_pwa_service_worker');
add_action('jy_head', 'jy_pwa_apple_metadata');
add_action('jy_footer', 'jy_pwa_registration_script');
add_action('admin_head', 'jy_pwa_admin_head');
add_action('admin_footer', 'jy_pwa_admin_footer');
add_action('plugin_uninstall', 'jy_pwa_uninstall_cleanup');
