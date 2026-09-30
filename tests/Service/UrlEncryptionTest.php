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

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Exception\EncryptionException;
use Silarhi\PicassoBundle\Service\UrlEncryption;

class UrlEncryptionTest extends TestCase
{
    private const KEY = 'test-secret-key';

    private UrlEncryption $encryption;

    protected function setUp(): void
    {
        $this->encryption = new UrlEncryption(self::KEY);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = '/var/uploads/images';
        $encrypted = $this->encryption->encrypt($plaintext);

        self::assertNotSame($plaintext, $encrypted);
        self::assertSame($plaintext, $this->encryption->decrypt($encrypted));
    }

    public function testEncryptProducesUrlSafeOutput(): void
    {
        $encrypted = $this->encryption->encrypt('/some/path/with spaces');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encrypted);
    }

    public function testEncryptIsDeterministic(): void
    {
        $plaintext = '/var/uploads';

        self::assertSame($this->encryption->encrypt($plaintext), $this->encryption->encrypt($plaintext));
        self::assertSame($this->encryption->encrypt($plaintext), (new UrlEncryption(self::KEY))->encrypt($plaintext));
    }

    public function testDifferentPlaintextsProduceDifferentCiphertexts(): void
    {
        self::assertNotSame($this->encryption->encrypt('/var/uploads'), $this->encryption->encrypt('/var/uploads2'));
    }

    public function testDifferentKeysProduceDifferentCiphertexts(): void
    {
        self::assertNotSame($this->encryption->encrypt('/var/uploads'), (new UrlEncryption('another-key'))->encrypt('/var/uploads'));
    }

    public function testDecryptsTokensMintedWithARandomNonce(): void
    {
        // Tokens generated before encryption became deterministic used a random
        // nonce; URLs already published with them must keep working.
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt('/var/uploads', 'aes-256-gcm', hash('sha256', self::KEY, true), \OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        self::assertIsString($ciphertext);
        $legacy = rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');

        self::assertSame('/var/uploads', $this->encryption->decrypt($legacy));
    }

    public function testDecryptWithWrongKeyThrows(): void
    {
        $encrypted = $this->encryption->encrypt('/var/uploads');
        $wrongKey = new UrlEncryption('wrong-key');

        $this->expectException(EncryptionException::class);
        $wrongKey->decrypt($encrypted);
    }

    public function testDecryptWithTamperedDataThrows(): void
    {
        $encrypted = $this->encryption->encrypt('/var/uploads');
        $tampered = $encrypted . 'x';

        $this->expectException(EncryptionException::class);
        $this->encryption->decrypt($tampered);
    }

    public function testDecryptWithTooShortDataThrows(): void
    {
        $this->expectException(EncryptionException::class);
        $this->encryption->decrypt('abc');
    }
}
