<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$GLOBALS['_test_hooks'] = [];
$GLOBALS['_test_locale'] = 'en';
define('BACKEND_PATH', '/tmp');
define('PUBLIC_PATH', $root);
function __(string $value, string $scope = 'default'): string { return $value; }
function get_locale(): string { return (string)$GLOBALS['_test_locale']; }
function add_filter(string $name, callable $callback): void { $GLOBALS['_test_hooks'][$name][] = $callback; }
function add_action(string $name, callable $callback): void { $GLOBALS['_test_hooks'][$name][] = $callback; }
function register_frontend_route(string $name, callable|string $callback): void { $GLOBALS['_test_routes'][$name] = $callback; }
require_once $root . '/plugin.php';

$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$manifestObject = json_decode((string)file_get_contents($root . '/plugin.json'), false, 512, JSON_THROW_ON_ERROR);
$check(($manifest['name'] ?? null) === 'pwa', 'plugin slug is pwa');
$check(($manifest['version'] ?? null) === '1.0.0', 'plugin version is 1.0.0');
$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.60', 'Jyavani requirement is explicit');
$check(in_array('mbstring', $manifest['requires']['extensions'] ?? [], true), 'mbstring requirement is explicit');
$check(array_key_exists('plugins', $manifest['requires'] ?? []), 'requires.plugins exists');
$check(($manifestObject->requires->plugins ?? null) instanceof stdClass, 'requires.plugins uses the dependency-map object contract');
$check(!array_key_exists('assets', $manifest), 'admin CSS and JS are not global plugin assets');
$check(($manifest['github_url'] ?? null) === 'https://github.com/adammuizweb/pwa', 'repository URL is canonical');
$check(isset($GLOBALS['_test_routes']['manifest.webmanifest']), 'dynamic manifest route is registered');
$check(isset($GLOBALS['_test_hooks']['service_worker_script']), 'service worker contribution is registered');
$check(isset($GLOBALS['_test_hooks']['web_manifest_url']), 'Core web_manifest_url filter is registered');
$check(isset($GLOBALS['_test_hooks']['apple_touch_icon_url']), 'Core apple_touch_icon_url filter is registered');
$check(isset($GLOBALS['_test_hooks']['theme_color']), 'Core theme_color filter is registered');
$check(isset($GLOBALS['_test_hooks']['admin_head'], $GLOBALS['_test_hooks']['admin_footer']), 'admin assets use page-scoped hooks');

$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'example.test';
$check(jy_pwa_normalize_path_url('https://example.test/app?source=pwa') === '/app?source=pwa', 'same-origin absolute URLs normalize to paths');
$check(jy_pwa_normalize_path_url('https://example.test:443/app') === '/app', 'explicit HTTPS default port matches an implicit port');
$_SERVER['HTTP_HOST'] = 'example.test:443';
$check(jy_pwa_normalize_path_url('https://example.test/app') === '/app', 'implicit HTTPS port matches the origin default port');
$_SERVER['HTTP_HOST'] = 'example.test';
$check(jy_pwa_normalize_path_url('https://other.test/app') === null, 'cross-origin URLs are rejected');
$check(jy_pwa_normalize_path_url('/%2e%2e/private/file.png') === null, 'encoded traversal paths are rejected');
$check(jy_pwa_normalize_color('#abc') === '#aabbcc', 'short hexadecimal colors normalize');
$check(jy_pwa_normalize_color('red') === null, 'non-hexadecimal colors are rejected');
$GLOBALS['_test_locale'] = 'id';
$check(jy_pwa_t('You are offline') === 'Anda sedang luring', 'Indonesian plugin catalog is loaded safely');
$GLOBALS['_test_locale'] = 'de';
$check(jy_pwa_t('You are offline') === 'Du bist offline', 'German plugin catalog is loaded safely');
$GLOBALS['_test_locale'] = 'en';

$iconErrors = [];
$check(jy_pwa_validate_icon_file('/assets/icons/icon-192.png', 192, 'Icon', $iconErrors), 'local public PNG passes file and dimension inspection');
$iconErrors = [];
$check(!jy_pwa_validate_icon_file('/assets/icons/icon-192.png', 512, 'Icon', $iconErrors), 'wrong local PNG dimensions are rejected');
$iconErrors = [];
$check(!jy_pwa_validate_icon_file('/assets/icons/missing.png', 192, 'Icon', $iconErrors), 'inaccessible local PNG URLs are rejected');
$testPdo = new class extends PDO { public function __construct() {} };
$defaults = jy_pwa_defaults($testPdo);
$check(($defaults['icon_192_url'] ?? '') === '/static/plugins/pwa/icon-192.png'
    && ($defaults['icon_512_url'] ?? '') === '/static/plugins/pwa/icon-512.png', 'Browser Push shared *_url setting contract is present');
$webManifest = jy_pwa_manifest($testPdo);
$check(isset($webManifest['id'], $webManifest['start_url'], $webManifest['scope']), 'web manifest has explicit identity and launch paths');
$iconErrors = [];
jy_pwa_validate_media_icon($testPdo, 0, '/assets/icons/icon-192.png', 192, 'Icon', false, $iconErrors);
$check($iconErrors === [], 'id=0 local PNG is inspected instead of trusted by suffix');
$iconErrors = [];
jy_pwa_validate_media_icon($testPdo, 0, '/assets/icons/icon-192.png', 512, 'Icon', false, $iconErrors);
$check($iconErrors !== [], 'id=0 local PNG with wrong dimensions is rejected');
$iconErrors = [];
jy_pwa_validate_media_icon($testPdo, 0, '/assets/icons/missing.png', 192, 'Icon', false, $iconErrors);
$check($iconErrors !== [], 'id=0 inaccessible PNG URL is rejected');

foreach ($manifest['static']['copy'] ?? [] as $entry) {
    $check(str_starts_with((string)($entry['to'] ?? ''), 'static/plugins/pwa/'), 'static destination is in the pwa namespace');
    $check(is_file($root . '/' . (string)($entry['from'] ?? '')), 'static source exists: ' . (string)($entry['from'] ?? ''));
}

foreach (['icon.png' => [512, 512], 'assets/icons/icon-180.png' => [180, 180], 'assets/icons/icon-192.png' => [192, 192], 'assets/icons/icon-512.png' => [512, 512], 'assets/icons/icon-maskable-512.png' => [512, 512]] as $file => $expected) {
    $size = is_file($root . '/' . $file) ? getimagesize($root . '/' . $file) : false;
    $check(is_array($size) && [$size[0], $size[1]] === $expected && ($size['mime'] ?? '') === 'image/png', $file . ' has the expected PNG dimensions');
}
$opaquePng = static function (string $file): bool {
    $data = (string)file_get_contents($file);
    $colorType = strlen($data) > 25 ? ord($data[25]) : -1;
    return in_array($colorType, [0, 2, 3], true) && !str_contains($data, 'tRNS');
};
$check($opaquePng($root . '/assets/icons/icon-180.png'), 'Apple icon is opaque and full-bleed');
$check($opaquePng($root . '/assets/icons/icon-maskable-512.png'), 'maskable icon is opaque and full-bleed');

$worker = (string)file_get_contents($root . '/assets/js/service-worker.js');
$check(str_contains($worker, "request.method !== 'GET'"), 'worker excludes non-GET requests');
$check(str_contains($worker, 'url.origin !== self.location.origin'), 'worker excludes cross-origin requests');
$check(str_contains($worker, 'sensitiveRequest(url)'), 'worker excludes admin, private, authentication, and API paths');
$check(str_contains($worker, 'configuredAssetRequest(url)'), 'worker serves only configured precached icons');
$check(str_contains($worker, "addEventListener('message'"), 'worker cache warming uses a message event');
$check(!str_contains($worker, "addEventListener('install'") && !str_contains($worker, "addEventListener('activate'"), 'worker leaves install and activate lifecycle to Core');
$check(substr_count($worker, '.then(() => response)') === 2, 'runtime cache writes are awaited before responses resolve');
$check(!str_contains($worker, "addEventListener('push'"), 'worker does not own sibling push events');

$registration = (string)file_get_contents($root . '/assets/js/register.js');
$check(str_contains($registration, "addEventListener('updatefound'"), 'registration observes worker updates');
$check(str_contains($registration, "addEventListener('statechange'"), 'registration waits for the new worker to activate');
$check(str_contains($registration, "addEventListener('controllerchange'"), 'registration warms a newly controlling worker');
$check(substr_count($registration, 'new WeakSet()') === 2, 'registration deduplicates observed and warmed workers');
$check(str_contains($registration, 'warmedWorkers.delete(worker)'), 'failed warm messages remain retryable');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " contract check(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
