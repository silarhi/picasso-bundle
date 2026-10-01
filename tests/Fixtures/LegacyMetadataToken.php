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

namespace Silarhi\PicassoBundle\Tests\Fixtures;

use function assert;

/**
 * Mints "_metadata" tokens the way 1.x UrlEncryption did (AES-256-GCM, random nonce).
 */
final class LegacyMetadataToken
{
    /**
     * @param array<string, string> $metadata
     */
    public static function mint(string $key, array $metadata): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($metadata, \JSON_THROW_ON_ERROR), 'aes-256-gcm', hash('sha256', $key, true), \OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        assert(false !== $ciphertext);

        return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
    }
}
