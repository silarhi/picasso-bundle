---
title: 'Upgrading from 1.x'
description: 'Every backward-incompatible change of PicassoBundle 2.0, and what to do about it.'
---

This guide lists every backward-incompatible change of PicassoBundle 2.0 and what to do about it. See the
[CHANGELOG](https://github.com/silarhi/picasso-bundle/blob/main/CHANGELOG.md#200---2026-10-02) for the full list of changes, including new features.

Most applications only need the first two steps: update the dependencies, then split the loaders that read several
directories or VichUploader mappings. Templates do not change when the former loader names are kept as chains.

## 1. Update the dependencies

```bash
composer require silarhi/picasso-bundle:^2.0
composer remove league/glide-symfony
```

`league/glide-symfony` is no longer used by the Glide transformer. Removing it unlocks Glide 4, which it does not
support.

## 2. Configuration

### One directory per filesystem loader

The `paths` list is removed: a filesystem loader reads the single directory set with `path`. Declare one loader per
directory, and keep the former name as a [chain loader](/sources/chain/) so templates keep working:

```diff
 picasso:
     loaders:
-        filesystem:
-            paths: ['%kernel.project_dir%/public/uploads', '%kernel.project_dir%/assets']
+        uploads: { type: filesystem, path: '%kernel.project_dir%/public/uploads' }
+        assets: { type: filesystem, path: '%kernel.project_dir%/assets' }
+        filesystem: { type: chain, loaders: [uploads, assets] }
```

A configuration still using `paths` fails at container build with this suggestion.

### One VichUploader mapping per vich loader

A vich loader serves a single mapping: the loader name when it is a mapping, else the `mapping` option, else the only
mapping. With several mappings, declare one loader per mapping and chain them under the former name:

```diff
 picasso:
     loaders:
-        vich: ~ # every VichUploader mapping
+        product_image: { type: vich }
+        user_avatar: { type: vich }
+        vich: { type: chain, loaders: [product_image, user_avatar] }
```

The upload field is now found from the mapping. The `field` context key is only needed when several fields of an entity
use the same mapping; in 1.x, omitting it picked the entity's first mapping. An entity with no matching field, or with
an ambiguous one, throws the new `InvalidImageReferenceException`.

### `picasso.url_encryption` is gone

`UrlEncryption`, its `picasso.url_encryption` service and `EncryptionException` are removed, along with the encrypted
`_metadata` query param of Glide URLs. Remove any reference to them (service wiring, `catch (EncryptionException)`).

Keep the same Glide `sign_key`: it decrypts the `_metadata` of 1.x URLs so they can be redirected (see
[step 4](#4-published-1x-urls)).

## 3. PHP code

These only concern code that implements bundle interfaces or instantiates bundle classes itself. Services wired by the
bundle are updated for you.

### `ServableLoaderInterface::getSource()`

`getSource()` takes no argument and returns an `ImageSourceInterface` (namespace `Silarhi\PicassoBundle\Source`): a
servable loader reads from a single source. Wrap a local path in `LocalImageSource` and a Flysystem storage in
`FlysystemImageSource`, or implement `ImageSourceInterface` (`exists()` + `readStream()`) for any other storage:

```diff
-public function getSource(array $metadata): FilesystemOperator|string
+public function getSource(): ImageSourceInterface
 {
-    return $this->storage; // or a local path
+    return new FlysystemImageSource($this->storage); // or new LocalImageSource($path)
 }
```

A custom loader that told several roots apart with `$metadata` must be split into one loader per root, chained if they
share a name.

### `Image::$metadata` removed

The `metadata` constructor argument and property of `Silarhi\PicassoBundle\Dto\Image` are replaced by `loader`
(`?string`, the loader that loaded the image when another one delegated to it). Drop `metadata:` from `new Image(...)`
calls and stop reading `$image->metadata`.

### New `UrlAliases` constructor argument

`GlideTransformer` and `ImageController` require a `Silarhi\PicassoBundle\Service\UrlAliases`.
Without configured [URL aliases](/reference/routes/#url-aliases), pass `new UrlAliases([], [])`:

```diff
 new GlideTransformer(
     $router,
-    $urlEncryption,
+    new UrlAliases([], []),
     $signKey,
     $cache,
     // ...
 );

 new ImageController(
     $transformerRegistry,
     $loaderRegistry,
+    ['max_age' => 31536000, 'immutable' => true, 'error_max_age' => null], // the cache_control config
+    new UrlAliases([], []),
     $stopwatch,
 );
```

`ImageController` also takes the `cache_control` configuration as its third argument. `LegacyUrlController` is new in
2.0 and internal.

### `VichMappingHelperInterface`

Only relevant if you implement or decorate it. `getFilePropertyName()` and `getUploadDestination()` are replaced by
`resolveField(object $entity, string $mapping, ?string $field): string`, and the `$field` argument of `readMimeType()`
and `readDimensions()` is now a non-nullable `string`.

## 4. Published 1.x URLs

Nothing to configure, but worth knowing before deploying:

- **1.x URLs carrying `_metadata`** (images from multi-directory or multi-mapping loaders) answer
  `301 Moved Permanently` to the same image and transformation under the loader now reading their source, whatever its
  name has become. Each redirect triggers a deprecation, so your logs tell when they stop being requested; support ends
  in 3.0. A source no loader reads any more answers `404`: declare a loader for it. See [1.x URLs](#1x-urls).
- **1.x URLs without `_metadata`** keep working as long as their loader name still exists (kept as a chain, or as a
  loader).
- New Glide URLs are stable across renders (no more random `_metadata`), so browsers and CDNs cache them.

## 5. HTTP responses and caching

### `immutable` by default, no `Expires`

Images served by the bundle controller now carry `immutable`, and the `Expires` header set by Glide is dropped:

```diff
-Cache-Control: max-age=31536000, public
-Expires: <one year from now>
+Cache-Control: immutable, max-age=31536000, public
```

To keep the 1.x headers, let the transformer set them:

```yaml
picasso:
    cache_control:
        max_age: ~
```

`cache_control.immutable: false` drops `immutable` only. If you replace source files behind unchanged paths, purge the
variants after each change (see [Cache Purge](/guides/cache-purge/)).

### `_untransformed` public-cache segment

Untransformed public-cache URLs (no transformation params) use the reserved params segment `_untransformed`. URLs with
an empty params segment now answer `404` instead of looping through redirects:

```diff
-/image/glide/uploads/photo.jpg/.jpg
+/image/glide/uploads/photo.jpg/_untransformed.jpg
```

In 1.x such URLs looped through redirects instead of serving the image, so no working URL is lost. Only code or web
server rules building these paths by hand need updating.

### Other behaviour changes

- Glide purges throw a `PurgeException` when the cache storage cannot delete the variants, instead of failing silently.
  Catch it where purges must not break the calling code.
- On a cache storage whose streams are not local files (an object store), Glide responses no longer carry a
  `Last-Modified` header.
- `FilesystemLoader::load()` treats paths escaping its base directory (`..`) as missing, as serving already did.

## 1.x URLs

Image URLs published by 1.x (in CDN caches, emails, search engines, saved pages) keep working. A 1.x loader reading several directories or VichUploader mappings put the source of each image in an encrypted `_metadata` query param. Since 2.0, that source names the loader reading it, so such a URL is answered with a `301 Moved Permanently` to the same image and transformation under that loader, whatever its loader name has become (a [chain](#chain-loader), another loader, or none). Nothing needs configuring:

- The source is matched against the `path` of filesystem loaders and the upload destination of vich loaders, also when the project moved to another directory since (deployments into a new release directory).
- When no loader reads a 1.x source any more, its URLs answer `404`: declare a loader for it.
- Each redirect triggers a deprecation, so the logs tell when 1.x URLs stop being requested. 1.x URLs without `_metadata` were already 2.0 URLs.
