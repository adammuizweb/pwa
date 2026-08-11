# Jyavani Progressive Web App

PWA v1.0.0 makes a Jyavani frontend installable without taking ownership of Core paths or another plugin's service worker handlers.

## Requirements

- Jyavani 2.3.60 or newer
- PHP 8.1 or newer with PDO, JSON, and mbstring
- HTTPS in production (browsers allow service workers on localhost for development)

The plugin uses the Jyavani 2.3.60 frontend metadata filters (`web_manifest_url`, `apple_touch_icon_url`, and `theme_color`) and the composed `service_worker_script` filter.

## Install

1. Build a flat ZIP whose root contains `plugin.json`.
2. Upload it in Jyavani's Plugin Manager and activate it.
3. Open **Settings > Progressive Web App**.
4. Save the app identity, launch paths, colors, and public PNG icons.
5. Visit the public frontend over HTTPS to register `/sw.js`.

Bundled defaults are copied only to `/static/plugins/pwa/`. The Apple and maskable defaults are opaque, full-bleed PNGs. Custom icons must resolve to readable same-origin files beneath `PUBLIC_PATH`; the plugin inspects their actual PNG MIME/type and exact 180x180, 192x192, or 512x512 dimensions. Media Library records must additionally be public on the public storage disk. A `.png` suffix by itself is never accepted.

Icon settings use the shared `pwa_icon_192_url` and `pwa_icon_512_url` contract consumed by Browser Push. The Apple and maskable equivalents are `pwa_icon_180_url` and `pwa_icon_maskable_url`.

## Runtime behavior

- `/manifest.webmanifest` is generated from current settings with `application/manifest+json`, an ETag, and explicit `id`, `start_url`, and `scope`.
- `/sw.js`, worker install/activate lifecycle, and the inactive no-op worker remain owned by Core. Disabling or removing this plugin therefore causes the registered worker to update to Core's no-op script instead of leaving a plugin-owned worker endpoint behind.
- This plugin contributes one fetch handler plus a message handler. Event-only extensions such as Browser Push can share the worker, but another plugin that calls `respondWith()` for the same fetches would conflict; only one fetch/cache owner should be active.
- Frontend registration sends `JY_PWA_WARM_CACHE` to the initial active worker and observes `updatefound`, worker `statechange`, and `controllerchange` so each newly activated revision warms its own cache exactly once. The awaited message task warms configured icons, manifest, and offline resources and removes only obsolete caches beginning with `jyavani-pwa-`.
- Configured icon GET requests use cache-first behavior. The manifest uses network-first behavior, and every cache write is included in the response or message promise.
- Navigations always use the network and receive the cached `/pwa-offline/` page only on a network failure.
- Cross-origin, non-GET, Core/dashboard static assets, dashboard pages, private, API, and other dynamic requests are not cached.

## Admin assets and translations

Plugin CSS and JavaScript are emitted only on `admin/settings/pwa`; they are not declared as global plugin assets. Jyavani 2.3.60 does not provide page-scoped Core dependency loading, so the documented `modal-helpers` and `media-selector` dependency IDs remain manifest dependencies and add their small Core scripts wherever active plugin dependencies are aggregated. This is the residual cost of retaining the Media Library picker without copying Core assets.

Admin and offline strings first use Core `__($source, 'pwa')`, then fall back to plugin-owned Indonesian and German catalogs. The plugin does not insert or modify Core translation rows.

## Development checks

```sh
php tests/contract.php
node tests/register.test.js
php -l plugin.php
php -l admin/settings.php
node --check assets/js/admin.js
node --check assets/js/register.js
node --check assets/js/service-worker.js
```

## License

MIT, copyright Adam Muiz.
