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
use SensitiveParameter;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;

/**
 * Signs Glide URLs with an HMAC-SHA256 of the image path and its sorted query,
 * base64url-encoded without padding (43 characters), like Symfony's UriSigner.
 *
 * Replaces Glide's own Signature, md5(key . ':' . path . '?' . query): a
 * key-prefixed hash is open to length extension, and Glide compares it with
 * "!==", which leaks timing. The signed string is unchanged, so a URL keeps
 * covering the same path and params. Signatures are always compared with
 * hash_equals().
 *
 * Several keys allow rotating them, as with Symfony's secrets: the first one
 * signs, every one validates. Requests signed the Glide way with any of them
 * still validate until 3.0, so the URLs already in pages, HTTP caches and CDNs
 * keep working; only HMAC signatures are generated.
 */
final readonly class GlideSignature implements SignatureInterface
{
    /**
     * @var non-empty-list<string>
     */
    private array $signKeys;

    /**
     * @param string|array<mixed> $signKey Secret, or non-empty list of secrets: the first one signs, all of them validate
     *
     * @throws InvalidConfigurationException When there is no key, or a key is empty
     */
    public function __construct(
        #[SensitiveParameter] string|array $signKey,
    ) {
        $signKeys = [];
        // Also reached at runtime, when the keys come from an environment variable
        foreach (is_string($signKey) ? [$signKey] : $signKey as $key) {
            if (!is_string($key) || '' === $key) {
                throw new InvalidConfigurationException('A Glide "sign_key" is empty: anyone could forge image URLs. Generate one with "php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\'" and set it as "sign_key" (e.g. in the PICASSO_SIGN_KEY environment variable).');
            }

            $signKeys[] = $key;
        }

        if ([] === $signKeys) {
            throw new InvalidConfigurationException('The Glide "sign_key" list is empty: it needs at least one key, the first one signing image URLs.');
        }

        $this->signKeys = $signKeys;
    }

    /**
     * @param string       $path   The image path
     * @param array<mixed> $params The query params
     *
     * @return array<string, mixed> The params, with their signature in "s"
     */
    public function addSignature(mixed $path, array $params): array
    {
        // Untyped $path: Glide 2's interface declares no parameter types. Glide 3 declares a plain array of params,
        // Glide 4 returns string keys: query keys are, the cast is a no-op at runtime
        $signed = [];
        foreach ($params as $key => $value) {
            $signed[(string) $key] = $value;
        }
        $signed['s'] = $this->generateSignature($path, $params);

        return $signed;
    }

    /**
     * @param string       $path   The image path
     * @param array<mixed> $params The query params, signature included in "s"
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
            if (hash_equals($this->hmac($payload, $signKey), $signature)) {
                return;
            }

            // Glide's MD5, in hexadecimal as Glide wrote it, validated until 3.0
            if (hash_equals(md5($signKey . ':' . $payload), $signature)) {
                return;
            }
        }

        throw new SignatureException('Signature is not valid.');
    }

    /**
     * The signature of an image path and its query params ("s" excluded), with the first key.
     *
     * @param array<mixed> $params
     */
    public function generateSignature(string $path, array $params): string
    {
        return $this->hmac($this->payload($path, $params), $this->signKeys[0]);
    }

    /**
     * The HMAC-SHA256 of a payload, base64url-encoded without padding, as
     * Symfony's UriSigner encodes it: URL-safe, so the router leaves it untouched.
     */
    private function hmac(string $payload, #[SensitiveParameter] string $signKey): string
    {
        return strtr(rtrim(base64_encode(hash_hmac('sha256', $payload, $signKey, true)), '='), ['/' => '_', '+' => '-']);
    }

    /**
     * The signed string, Glide's: the path without its leading slash, then the
     * query without "s", sorted by key.
     *
     * @param array<mixed> $params
     */
    private function payload(string $path, array $params): string
    {
        unset($params['s']);
        ksort($params);

        return ltrim($path, '/') . '?' . http_build_query($params);
    }
}
