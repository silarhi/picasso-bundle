<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
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

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $plaintext = '/var/uploads';
        $a = $this->encryption->encrypt($plaintext);
        $b = $this->encryption->encrypt($plaintext);

        self::assertNotSame($a, $b);
        self::assertSame($plaintext, $this->encryption->decrypt($a));
        self::assertSame($plaintext, $this->encryption->decrypt($b));
    }

    public function testDecryptWithWrongKeyThrows(): void
    {
        $encrypted = $this->encryption->encrypt('/var/uploads');
        $wrongKey = new UrlEncryption('wrong-key');

        $this->expectException(\RuntimeException::class);
        $wrongKey->decrypt($encrypted);
    }

    public function testDecryptWithTamperedDataThrows(): void
    {
        $encrypted = $this->encryption->encrypt('/var/uploads');
        $tampered = $encrypted.'x';

        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt($tampered);
    }

    public function testDecryptWithTooShortDataThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt('abc');
    }
}
