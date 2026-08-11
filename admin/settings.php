<?php
declare(strict_types=1);

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    echo '<p>' . jy_pwa_h(jy_pwa_t('Database not available.')) . '</p>';
    return;
}

$settings = jy_pwa_settings($pdo);
$errors = [];
$saved = false;
$displayOptions = ['standalone', 'minimal-ui', 'fullscreen', 'browser'];
$orientationOptions = ['any', 'natural', 'portrait', 'portrait-primary', 'portrait-secondary', 'landscape', 'landscape-primary', 'landscape-secondary'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = jy_pwa_t('Invalid CSRF token.');
    }

    $candidate = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'short_name' => trim((string)($_POST['short_name'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'id' => trim((string)($_POST['id'] ?? '')),
        'start_url' => trim((string)($_POST['start_url'] ?? '')),
        'scope' => trim((string)($_POST['scope'] ?? '')),
        'display' => trim((string)($_POST['display'] ?? '')),
        'orientation' => trim((string)($_POST['orientation'] ?? '')),
        'theme_color' => trim((string)($_POST['theme_color'] ?? '')),
        'background_color' => trim((string)($_POST['background_color'] ?? '')),
    ];

    $nameLength = mb_strlen($candidate['name'], 'UTF-8');
    $shortLength = mb_strlen($candidate['short_name'], 'UTF-8');
    $descriptionLength = mb_strlen($candidate['description'], 'UTF-8');
    if ($nameLength < 1 || $nameLength > 45) $errors[] = jy_pwa_t('App name must contain 1 to 45 characters.');
    if ($shortLength < 1 || $shortLength > 12) $errors[] = jy_pwa_t('Short name must contain 1 to 12 characters.');
    if ($descriptionLength < 1 || $descriptionLength > 300) $errors[] = jy_pwa_t('Description must contain 1 to 300 characters.');

    foreach (['id' => false, 'start_url' => false, 'scope' => true] as $field => $directory) {
        $normalized = jy_pwa_normalize_path_url($candidate[$field], $directory);
        if ($normalized === null) {
            $errors[] = jy_pwa_t('%s must be a same-origin absolute path.', $field);
        } else {
            $candidate[$field] = $normalized;
            if ($field !== 'scope' && jy_pwa_sensitive_path($pdo, $normalized)) {
                $errors[] = jy_pwa_t('%s cannot point to an admin, authentication, private, or API route.', $field);
            }
        }
    }
    $scopePath = (string)(parse_url($candidate['scope'], PHP_URL_PATH) ?? '');
    $startPath = (string)(parse_url($candidate['start_url'], PHP_URL_PATH) ?? '');
    if ($scopePath !== '' && $startPath !== '' && !str_starts_with($startPath, $scopePath)) {
        $errors[] = jy_pwa_t('Start URL must be inside the configured scope.');
    }
    if (!in_array($candidate['display'], $displayOptions, true)) $errors[] = jy_pwa_t('Invalid display mode.');
    if (!in_array($candidate['orientation'], $orientationOptions, true)) $errors[] = jy_pwa_t('Invalid orientation.');

    foreach (['theme_color' => jy_pwa_t('Theme color'), 'background_color' => jy_pwa_t('Background color')] as $field => $label) {
        $normalized = jy_pwa_normalize_color($candidate[$field]);
        if ($normalized === null) $errors[] = jy_pwa_t('%s must be a hexadecimal color.', $label);
        else $candidate[$field] = $normalized;
    }

    $candidate['icon_mode'] = trim((string)($_POST['icon_mode'] ?? 'crop'));
    if (!in_array($candidate['icon_mode'], ['crop', 'contain'], true)) {
        $errors[] = jy_pwa_t('Invalid icon fit mode.');
    }
    $source = jy_pwa_validate_media_source(
        $pdo,
        max(0, (int)($_POST['icon_source_id'] ?? 0)),
        trim((string)($_POST['icon_source_url'] ?? '')),
        $errors
    );
    $candidate['icon_source_id'] = $source['id'];
    $candidate['icon_source_url'] = $source['url'];
    $regenerateIcons = (string)($_POST['regenerate_icons'] ?? '') === '1'
        || $candidate['icon_source_id'] !== $settings['icon_source_id']
        || $candidate['icon_source_url'] !== $settings['icon_source_url']
        || $candidate['icon_mode'] !== $settings['icon_mode']
        || $candidate['background_color'] !== $settings['background_color'];

    if ($errors === []) {
        try {
            if (!$regenerateIcons) {
                $generated = [];
                foreach (['icon_180', 'icon_192', 'icon_512', 'icon_maskable'] as $key) {
                    $generated[$key . '_url'] = $settings[$key . '_url'];
                    $candidate[$key . '_id'] = $settings[$key . '_id'];
                }
            } elseif ($source['file'] === null) {
                $generated = [
                    'icon_180_url' => JY_PWA_DEFAULT_ICON_180,
                    'icon_192_url' => JY_PWA_DEFAULT_ICON_192,
                    'icon_512_url' => JY_PWA_DEFAULT_ICON_512,
                    'icon_maskable_url' => JY_PWA_DEFAULT_ICON_MASKABLE,
                ];
            } else {
                $generated = jy_pwa_generate_icons(
                    $source['file'],
                    $candidate['icon_mode'],
                    $candidate['background_color'],
                    jy_pwa_generated_directory()
                );
            }
            $candidate = array_merge($candidate, $generated);
            if ($regenerateIcons) {
                foreach (['icon_180', 'icon_192', 'icon_512', 'icon_maskable'] as $key) {
                    $candidate[$key . '_id'] = $source['id'];
                }
            }
        } catch (Throwable $error) {
            error_log('[pwa] Icon generation failed: ' . $error->getMessage());
            $errors[] = jy_pwa_t('The PWA icons could not be generated. Check that GD is available and the generated icon directory is writable.');
        }
    }

    $settings = array_merge($settings, $candidate);
    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            foreach ($candidate as $key => $value) {
                if (!settings_set($pdo, 'pwa_' . $key, (string)$value, 1)) {
                    throw new RuntimeException('Could not save ' . $key);
                }
            }
            $pdo->commit();
            $saved = true;
            try {
                jy_pwa_prune_generated_icons([
                    $candidate['icon_180_url'],
                    $candidate['icon_192_url'],
                    $candidate['icon_512_url'],
                    $candidate['icon_maskable_url'],
                ]);
            } catch (Throwable $cleanupError) {
                error_log('[pwa] Generated icon pruning failed: ' . $cleanupError->getMessage());
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[pwa] Settings save failed: ' . $error->getMessage());
            $errors[] = jy_pwa_t('The PWA settings could not be saved.');
        }
    }
}

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocalhost = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/', $host) === 1;
$secureContext = $isHttps || $isLocalhost;
$manifestUrl = '/manifest.webmanifest';
$workerUrl = '/sw.js';
?>
<div class="pwa-admin" id="pwa-admin"
     data-worker-unsupported="<?= jy_pwa_h(jy_pwa_t('Unsupported browser')) ?>"
     data-worker-registered="<?= jy_pwa_h(jy_pwa_t('Registered')) ?>"
     data-worker-visit="<?= jy_pwa_h(jy_pwa_t('Visit the public site')) ?>"
     data-worker-error="<?= jy_pwa_h(jy_pwa_t('Could not check')) ?>">
  <header class="pwa-hero">
    <div>
      <span class="pwa-eyebrow"><?= jy_pwa_h(jy_pwa_t('Installability')) ?></span>
      <h2><?= jy_pwa_h(jy_pwa_t('Progressive Web App')) ?></h2>
      <p><?= jy_pwa_h(jy_pwa_t('Configure the install experience, app icons, colors, and offline fallback for the public site.')) ?></p>
    </div>
    <div class="pwa-hero-mark"><img src="<?= jy_pwa_h($settings['icon_192_url']) ?>" alt=""></div>
  </header>

  <?php if ($saved): ?><div class="pwa-notice pwa-notice--success" role="status"><?= jy_pwa_h(jy_pwa_t('PWA settings saved. The service worker cache revision was updated automatically.')) ?></div><?php endif; ?>
  <?php if ($errors !== []): ?><div class="pwa-notice pwa-notice--error" role="alert"><strong><?= jy_pwa_h(jy_pwa_t('Please correct these settings:')) ?></strong><ul><?php foreach ($errors as $error): ?><li><?= jy_pwa_h((string)$error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="pwa-status-grid" aria-label="<?= jy_pwa_h(jy_pwa_t('PWA status')) ?>">
    <article class="pwa-status <?= $secureContext ? 'is-good' : 'is-bad' ?>"><span><?= jy_pwa_h(jy_pwa_t('Secure context')) ?></span><strong><?= jy_pwa_h($secureContext ? jy_pwa_t('Ready') : jy_pwa_t('HTTPS required')) ?></strong><small><?= jy_pwa_h(jy_pwa_t('Service workers require HTTPS except on localhost.')) ?></small></article>
    <article class="pwa-status is-good"><span><?= jy_pwa_h(jy_pwa_t('Manifest')) ?></span><strong><a href="<?= $manifestUrl ?>" target="_blank" rel="noopener"><?= jy_pwa_h(jy_pwa_t('Open manifest')) ?></a></strong><small><?= jy_pwa_h(jy_pwa_t('Served dynamically as application/manifest+json.')) ?></small></article>
    <article class="pwa-status" id="pwa-worker-status"><span><?= jy_pwa_h(jy_pwa_t('Service worker')) ?></span><strong><?= jy_pwa_h(jy_pwa_t('Checking...')) ?></strong><small><a href="<?= $workerUrl ?>" target="_blank" rel="noopener">/sw.js</a></small></article>
  </section>

  <form method="post" class="pwa-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= jy_pwa_h(function_exists('csrf_token') ? csrf_token() : '') ?>">
    <input type="hidden" name="regenerate_icons" value="0" data-regenerate-icons>

    <section class="pwa-panel">
      <div class="pwa-panel-title"><span>01</span><div><h3><?= jy_pwa_h(jy_pwa_t('App identity')) ?></h3><p><?= jy_pwa_h(jy_pwa_t('Names shown in browser install prompts and on the home screen.')) ?></p></div></div>
      <div class="pwa-fields">
        <label><span><?= jy_pwa_h(jy_pwa_t('App name')) ?></span><input name="name" maxlength="45" required value="<?= jy_pwa_h($settings['name']) ?>"><small><?= jy_pwa_h(jy_pwa_t('Maximum 45 characters.')) ?></small></label>
        <label><span><?= jy_pwa_h(jy_pwa_t('Short name')) ?></span><input name="short_name" maxlength="12" required value="<?= jy_pwa_h($settings['short_name']) ?>"><small><?= jy_pwa_h(jy_pwa_t('Maximum 12 characters for compact labels.')) ?></small></label>
        <label class="pwa-field-wide"><span><?= jy_pwa_h(jy_pwa_t('Description')) ?></span><textarea name="description" maxlength="300" rows="3" required><?= jy_pwa_h($settings['description']) ?></textarea></label>
      </div>
    </section>

    <section class="pwa-panel">
      <div class="pwa-panel-title"><span>02</span><div><h3><?= jy_pwa_h(jy_pwa_t('Launch behavior')) ?></h3><p><?= jy_pwa_h(jy_pwa_t('All URLs must be same-origin absolute paths.')) ?></p></div></div>
      <div class="pwa-fields pwa-fields--three">
        <label><span><?= jy_pwa_h(jy_pwa_t('App ID')) ?></span><input name="id" required value="<?= jy_pwa_h($settings['id']) ?>"><small>/</small></label>
        <label><span><?= jy_pwa_h(jy_pwa_t('Start URL')) ?></span><input name="start_url" required value="<?= jy_pwa_h($settings['start_url']) ?>"><small>/</small></label>
        <label><span><?= jy_pwa_h(jy_pwa_t('Scope')) ?></span><input name="scope" required value="<?= jy_pwa_h($settings['scope']) ?>"><small><?= jy_pwa_h(jy_pwa_t('Must contain the start URL.')) ?></small></label>
        <label><span><?= jy_pwa_h(jy_pwa_t('Display')) ?></span><select name="display"><?php foreach ($displayOptions as $option): ?><option value="<?= $option ?>" <?= $settings['display'] === $option ? 'selected' : '' ?>><?= jy_pwa_h(jy_pwa_t($option)) ?></option><?php endforeach; ?></select></label>
        <label><span><?= jy_pwa_h(jy_pwa_t('Orientation')) ?></span><select name="orientation"><?php foreach ($orientationOptions as $option): ?><option value="<?= $option ?>" <?= $settings['orientation'] === $option ? 'selected' : '' ?>><?= jy_pwa_h(jy_pwa_t($option)) ?></option><?php endforeach; ?></select></label>
      </div>
    </section>

    <section class="pwa-panel">
      <div class="pwa-panel-title"><span>03</span><div><h3><?= jy_pwa_h(jy_pwa_t('Colors')) ?></h3><p><?= jy_pwa_h(jy_pwa_t('Used by the browser chrome and app launch surface.')) ?></p></div></div>
      <div class="pwa-color-grid">
        <?php foreach (['theme_color' => jy_pwa_t('Theme color'), 'background_color' => jy_pwa_t('Background color')] as $field => $label): ?>
          <label><span><?= jy_pwa_h($label) ?></span><div class="pwa-color-control"><input type="color" data-color-for="<?= $field ?>" value="<?= jy_pwa_h($settings[$field]) ?>"><input name="<?= $field ?>" id="<?= $field ?>" pattern="#[0-9A-Fa-f]{6}" required value="<?= jy_pwa_h($settings[$field]) ?>"></div></label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="pwa-panel">
      <div class="pwa-panel-title"><span>04</span><div><h3><?= jy_pwa_h(jy_pwa_t('App icon generator')) ?></h3><p><?= jy_pwa_h(jy_pwa_t('Choose one public Media Library image and generate every PNG icon automatically.')) ?></p></div></div>
      <div class="pwa-icon-builder" data-icon-source data-default-preview="<?= jy_pwa_h(JY_PWA_DEFAULT_ICON_512) ?>">
        <div class="pwa-source-preview"><img src="<?= jy_pwa_h($settings['icon_source_url'] !== '' ? $settings['icon_source_url'] : $settings['icon_512_url']) ?>" alt="" data-source-preview></div>
        <div class="pwa-source-controls">
          <label><span><?= jy_pwa_h(jy_pwa_t('Source image')) ?></span>
            <input type="hidden" name="icon_source_id" value="<?= (int)$settings['icon_source_id'] ?>" data-media-id>
            <input type="text" name="icon_source_url" value="<?= jy_pwa_h($settings['icon_source_url']) ?>" data-media-url aria-label="<?= jy_pwa_h(jy_pwa_t('Source image URL')) ?>">
            <small><?= jy_pwa_h(jy_pwa_t('Choose any public image. Its dimensions and aspect ratio do not need to match the generated icons.')) ?></small>
          </label>
          <div class="pwa-icon-actions"><button class="pwa-button pwa-button--secondary" type="button" data-choose-media><?= jy_pwa_h(jy_pwa_t('Choose image')) ?></button><button class="pwa-button pwa-button--quiet" type="button" data-clear-media><?= jy_pwa_h(jy_pwa_t('Use bundled default')) ?></button></div>
          <fieldset class="pwa-fit-options"><legend><?= jy_pwa_h(jy_pwa_t('Image fit')) ?></legend>
            <label><input type="radio" name="icon_mode" value="crop" <?= $settings['icon_mode'] === 'crop' ? 'checked' : '' ?>><span><strong><?= jy_pwa_h(jy_pwa_t('Crop to fill')) ?></strong><small><?= jy_pwa_h(jy_pwa_t('Fills the icon and crops overflow from the center.')) ?></small></span></label>
            <label><input type="radio" name="icon_mode" value="contain" <?= $settings['icon_mode'] === 'contain' ? 'checked' : '' ?>><span><strong><?= jy_pwa_h(jy_pwa_t('Fit with padding')) ?></strong><small><?= jy_pwa_h(jy_pwa_t('Keeps the whole image and fills empty space with the background color.')) ?></small></span></label>
          </fieldset>
        </div>
      </div>
      <div class="pwa-generated-icons" aria-label="<?= jy_pwa_h(jy_pwa_t('Generated icons')) ?>">
        <?php foreach ([
            'icon_180' => [jy_pwa_t('Apple touch icon'), '180x180'],
            'icon_192' => [jy_pwa_t('Standard icon'), '192x192'],
            'icon_512' => [jy_pwa_t('Large icon'), '512x512'],
            'icon_maskable' => [jy_pwa_t('Maskable icon'), '512x512'],
        ] as $key => [$label, $dimensions]): ?>
          <article><img src="<?= jy_pwa_h($settings[$key . '_url']) ?>" alt=""><span><strong><?= jy_pwa_h($label) ?></strong><small><?= $dimensions ?> PNG</small></span></article>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="pwa-actions"><button class="pwa-button pwa-button--primary" type="submit"><?= jy_pwa_h(jy_pwa_t('Save PWA settings')) ?></button></div>
  </form>

  <section class="pwa-guide">
    <h3><?= jy_pwa_h(jy_pwa_t('Install guidance')) ?></h3>
    <ol><li><?= jy_pwa_h(jy_pwa_t('Serve the public site over HTTPS.')) ?></li><li><?= jy_pwa_h(jy_pwa_t('Visit the public site once so /sw.js can register and warm the offline cache.')) ?></li><li><?= jy_pwa_h(jy_pwa_t('Use the browser install button, or Add to Home Screen on iOS Safari.')) ?></li><li><?= jy_pwa_h(jy_pwa_t('After changing icons, revisit the site and allow the updated worker to activate. Browsers may retain installed icons until the app is reinstalled.')) ?></li></ol>
    <p><?= jy_pwa_h(jy_pwa_t('The plugin never caches dashboard, private, API, cross-origin, or non-GET traffic. Public navigations use the network and fall back to the offline page only when unreachable.')) ?></p>
  </section>
</div>
