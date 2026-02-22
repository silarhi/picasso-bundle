<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Service\UrlEncryption;

class UrlEncryptionTest extends TestCase
{
    private const KEY = 'test-secret-key';

    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = '/var/uploads/images';
        $encrypted = UrlEncryption::encrypt($plaintext, self::KEY);

        self::assertNotSame($plaintext, $encrypted);
        self::assertSame($plaintext, UrlEncryption::decrypt($encrypted, self::KEY));
    }

    public function testEncryptProducesUrlSafeOutput(): void
    {
        $encrypted = UrlEncryption::encrypt('/some/path/with spaces', self::KEY);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encrypted);
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $plaintext = '/var/uploads';
        $a = UrlEncryption::encrypt($plaintext, self::KEY);
        $b = UrlEncryption::encrypt($plaintext, self::KEY);

        self::assertNotSame($a, $b);
        self::assertSame($plaintext, UrlEncryption::decrypt($a, self::KEY));
        self::assertSame($plaintext, UrlEncryption::decrypt($b, self::KEY));
    }

    public function testDecryptWithWrongKeyThrows(): void
    {
        $encrypted = UrlEncryption::encrypt('/var/uploads', self::KEY);

        $this->expectException(\RuntimeException::class);
        UrlEncryption::decrypt($encrypted, 'wrong-key');
    }

    public function testDecryptWithTamperedDataThrows(): void
    {
        $encrypted = UrlEncryption::encrypt('/var/uploads', self::KEY);
        $tampered = $encrypted.'x';

        $this->expectException(\RuntimeException::class);
        UrlEncryption::decrypt($tampered, self::KEY);
    }

    public function testDecryptWithTooShortDataThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        UrlEncryption::decrypt('abc', self::KEY);
    }
}
