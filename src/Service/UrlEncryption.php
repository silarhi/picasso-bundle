<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Service;

final class UrlEncryption
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function __construct(
        private readonly string $key,
    ) {
    }

    public function encrypt(string $plaintext): string
    {
        $derivedKey = hash('sha256', $this->key, true);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $derivedKey, \OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if (false === $ciphertext) {
            throw new \RuntimeException('Encryption failed.');
        }

        return rtrim(strtr(base64_encode($iv.$tag.$ciphertext), '+/', '-_'), '=');
    }

    public function decrypt(string $encoded): string
    {
        $derivedKey = hash('sha256', $this->key, true);
        $data = base64_decode(strtr($encoded, '-_', '+/'), true);

        if (false === $data || \strlen($data) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('Decryption failed: invalid data.');
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $derivedKey, \OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $plaintext) {
            throw new \RuntimeException('Decryption failed: invalid key or tampered data.');
        }

        return $plaintext;
    }
}
