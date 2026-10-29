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

namespace Silarhi\PicassoBundle\Transformer;

use function is_string;

use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureInterface;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;

use function strlen;

/**
 * Signs Glide URLs with an HMAC-SHA256 of the image path and its sorted query.
 *
 * Replaces Glide's own Signature, md5(key . ':' . path . '?' . query): a
 * key-prefixed hash is open to length extension, and Glide compares it with
 * "!==", which leaks timing. The signed string is unchanged, so a URL keeps
 * covering the same path and params. Signatures are always compared with
 * hash_equals().
 *
 * Several keys allow rotating them: the first one signs, every one validates.
 * With $acceptLegacy, requests signed the Glide way with any of them still
 * validate, so the URLs already in pages, HTTP caches and CDNs keep working;
 * only HMAC signatures are generated.
 */
final readonly class GlideSignature implements SignatureInterface
{
    /**
     * Minimum key length, in bytes, required from 3.0. A key at least as long
     * as the SHA-256 output is what HMAC asks for (RFC 2104), and what a
     * 32-character hexadecimal secret such as Symfony's generated APP_SECRET provides.
     */
    public const MIN_KEY_LENGTH = 32;

    /**
     * @var non-empty-list<string>
     */
    private array $signKeys;

    /**
     * @param string|array<mixed> $signKey      Secret, or non-empty list of secrets: the first one signs, all of them validate
     * @param bool                $acceptLegacy Whether requests signed with Glide's MD5 signature still validate
     *
     * @throws InvalidConfigurationException When there is no key, or a key is empty
     */
    public function __construct(
        string|array $signKey,
        private bool $acceptLegacy = true,
    ) {
        $signKeys = [];
        // Also reached at runtime, when the keys come from an environment variable
        foreach (is_string($signKey) ? [$signKey] : $signKey as $key) {
            if (!is_string($key) || '' === $key) {
                throw new InvalidConfigurationException('A Glide "sign_key" is empty: anyone could forge image URLs. Generate one with "php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\'" and set it as "sign_key" (e.g. in the PICASSO_SIGN_KEY environment variable).');
            }

            if (strlen($key) < self::MIN_KEY_LENGTH) {
                trigger_deprecation('silarhi/picasso-bundle', '2.7', 'A Glide "sign_key" shorter than %d characters is deprecated: 3.0 will require at least %d. Generate one with "php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\'" and put it in front of the current key ("sign_key: [new, current]"), so the published URLs keep working.', self::MIN_KEY_LENGTH, self::MIN_KEY_LENGTH);
            }

            $signKeys[] = $key;
        }

        if ([] === $signKeys) {
            throw new InvalidConfigurationException('The Glide "sign_key" list is empty: it needs at least one key, the first one signing image URLs.');
        }

        $this->signKeys = $signKeys;
    }

    /**
     * @param string               $path   The image path
     * @param array<string, mixed> $params The query params
     *
     * @return array<string, mixed> The params, with their signature in "s"
     */
    public function addSignature(mixed $path, array $params): array
    {
        // Untyped $path: Glide 2's interface declares no parameter types
        return [...$params, 's' => $this->generateSignature($path, $params)];
    }

    /**
     * @param string               $path   The image path
     * @param array<string, mixed> $params The query params, signature included in "s"
     *
     * @throws SignatureException When the signature is missing or does not match
     */
    public function validateRequest(mixed $path, array $params): void
    {
        if (!isset($params['s'])) {
            throw new SignatureException('Signature is missing.');
        }

        $signature = $params['s'];
        // "?s[]=…" gives an array, which hash_equals() would reject with a TypeError
        if (!is_string($signature)) {
            throw new SignatureException('Signature is not valid.');
        }

        $payload = $this->payload($path, $params);

        foreach ($this->signKeys as $signKey) {
            if (hash_equals(hash_hmac('sha256', $payload, $signKey), $signature)) {
                return;
            }

            if ($this->acceptLegacy && hash_equals(md5($signKey . ':' . $payload), $signature)) {
                return;
            }
        }

        throw new SignatureException('Signature is not valid.');
    }

    /**
     * The signature of an image path and its query params ("s" excluded), with the first key.
     *
     * @param array<string, mixed> $params
     */
    public function generateSignature(string $path, array $params): string
    {
        return hash_hmac('sha256', $this->payload($path, $params), $this->signKeys[0]);
    }

    /**
     * The signed string, Glide's: the path without its leading slash, then the
     * query without "s", sorted by key.
     *
     * @param array<string, mixed> $params
     */
    private function payload(string $path, array $params): string
    {
        unset($params['s']);
        ksort($params);

        return ltrim($path, '/') . '?' . http_build_query($params);
    }
}
