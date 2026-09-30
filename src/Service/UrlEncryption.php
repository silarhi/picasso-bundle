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

use Silarhi\PicassoBundle\Exception\EncryptionException;

use function strlen;

/**
 * Deterministic authenticated encryption for values embedded in image URLs.
 *
 * The same plaintext always yields the same token, so a URL generated twice
 * (twice in a page, or across requests) is byte-identical: browsers and CDNs
 * can cache it, and the Glide signature computed over it stays stable. The
 * AES-GCM nonce is a synthetic IV (an HMAC of the plaintext under a separate
 * key) rather than a random one; a nonce only repeats for an identical
 * plaintext, which then encrypts to the identical token, so the only thing
 * revealed is that two URLs carry the same value — which is the point.
 *
 * The token format (nonce, tag, ciphertext) is unchanged and decryption reads
 * the nonce from the token, so tokens minted with random nonces still decrypt.
 */
final readonly class UrlEncryption
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $derivedKey;
    private string $ivKey;

    public function __construct(string $key)
    {
        $this->derivedKey = hash('sha256', $key, true);
        $this->ivKey = hash_hmac('sha256', 'picasso.url_encryption.iv', $key, true);
    }

    /**
     * @throws EncryptionException
     */
    public function encrypt(string $plaintext): string
    {
        $iv = substr(hash_hmac('sha256', $plaintext, $this->ivKey, true), 0, self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->derivedKey, \OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if (false === $ciphertext) {
            throw new EncryptionException('Encryption failed.');
        }

        return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
    }

    /**
     * @throws EncryptionException
     */
    public function decrypt(string $encoded): string
    {
        $data = base64_decode(strtr($encoded, '-_', '+/'), true);

        if (false === $data || strlen($data) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new EncryptionException('Decryption failed: invalid data.');
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->derivedKey, \OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $plaintext) {
            throw new EncryptionException('Decryption failed: invalid key or tampered data.');
        }

        return $plaintext;
    }
}
