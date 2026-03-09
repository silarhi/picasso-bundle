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

final readonly class UrlEncryption
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $derivedKey;

    public function __construct(string $key)
    {
        $this->derivedKey = hash('sha256', $key, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->derivedKey, \OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if (false === $ciphertext) {
            throw new EncryptionException('Encryption failed.');
        }

        return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
    }

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
