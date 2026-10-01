<?php

declare(strict_types=1);

/*
 * This file is part of the Picasso Bundle package.
 *
 * (c) SILARHI <dev@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silarhi\PicassoBundle\Service;

use function is_array;
use function is_string;

use JsonException;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;

use function sprintf;
use function strlen;

/**
 * Finds the loader that now reads the source a 1.x Glide URL pointed at.
 *
 * In 1.x, a loader reading several roots (filesystem "paths", or every
 * VichUploader mapping) put the root of each image in an encrypted "_metadata"
 * URL param: {"path": "<directory>"} or {"upload_destination": "<directory or
 * Flysystem storage>"}. Since 2.0 each loader reads a single root, so a root
 * identifies a loader. Roots are matched exactly, then, for directories inside
 * the project, by their path relative to it: deployments into a new release
 * directory change the absolute path baked into 1.x tokens.
 *
 * @internal Removed with 1.x URL support
 */
final readonly class LegacyMetadataResolver
{
    public const SERVICE = '.picasso.legacy_metadata_resolver';

    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $derivedKey;

    /**
     * @param string                $key           Sign key of the Glide transformer that minted 1.x URLs
     * @param array<string, string> $loadersByRoot Root (directory or Flysystem storage name) → loader name
     */
    public function __construct(
        string $key,
        private array $loadersByRoot,
        private string $projectDir,
    ) {
        $this->derivedKey = hash('sha256', $key, true);
    }

    /**
     * The loader now reading the source of a 1.x "_metadata" token.
     *
     * @throws ImageNotFoundException When the token is invalid, or no loader reads its root
     */
    public function resolveLoader(string $token): string
    {
        $root = $this->decodeRoot($token);

        return $this->loaderForRoot($root)
            ?? throw new ImageNotFoundException(sprintf('No loader reads "%s", the source of this 1.x URL. Declare one.', $root));
    }

    /**
     * Decrypts a 1.x "_metadata" token into the root it carries.
     *
     * @throws ImageNotFoundException When the token is not a valid 1.x metadata token
     */
    public function decodeRoot(string $token): string
    {
        $data = base64_decode(strtr($token, '-_', '+/'), true);

        if (false === $data || strlen($data) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new ImageNotFoundException('Invalid metadata parameter.');
        }

        $plaintext = openssl_decrypt(
            substr($data, self::IV_LENGTH + self::TAG_LENGTH),
            self::CIPHER,
            $this->derivedKey,
            \OPENSSL_RAW_DATA,
            substr($data, 0, self::IV_LENGTH),
            substr($data, self::IV_LENGTH, self::TAG_LENGTH),
        );

        try {
            $metadata = false !== $plaintext ? json_decode($plaintext, true, flags: \JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new ImageNotFoundException('Invalid metadata parameter.', $e->getCode(), previous: $e);
        }

        $root = is_array($metadata) ? ($metadata['upload_destination'] ?? $metadata['path'] ?? null) : null;

        if (!is_string($root)) {
            throw new ImageNotFoundException('Invalid metadata parameter.');
        }

        return $root;
    }

    /**
     * The loader reading the given root, or null when no loader does.
     */
    public function loaderForRoot(string $root): ?string
    {
        if (isset($this->loadersByRoot[$root])) {
            return $this->loadersByRoot[$root];
        }

        $root = rtrim($root, '/');
        $match = null;
        $matchLength = 0;

        foreach ($this->loadersByRoot as $loaderRoot => $loader) {
            $relative = $this->relativeToProject($loaderRoot);

            if (null !== $relative && strlen($relative) > $matchLength && str_ends_with($root, '/' . $relative)) {
                $match = $loader;
                $matchLength = strlen($relative);
            }
        }

        return $match;
    }

    private function relativeToProject(string $root): ?string
    {
        $projectDir = rtrim($this->projectDir, '/') . '/';

        if ('/' === $projectDir || !str_starts_with($root, $projectDir)) {
            return null;
        }

        $relative = trim(substr($root, strlen($projectDir)), '/');

        return '' !== $relative ? $relative : null;
    }
}
