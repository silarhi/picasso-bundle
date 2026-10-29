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

namespace Silarhi\PicassoBundle\Tests\Transformer;

use League\Glide\Signatures\Signature;
use League\Glide\Signatures\SignatureException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Tests\Fixtures\CollectsDeprecationsTrait;
use Silarhi\PicassoBundle\Transformer\GlideSignature;

use function strlen;

class GlideSignatureTest extends TestCase
{
    use CollectsDeprecationsTrait;

    private const SIGN_KEY = 'glide-signature-test-key-0123456789abcdef';
    private const NEW_KEY = 'glide-signature-new-key-0123456789abcdef';

    /**
     * @return iterable<string, array{string|array<mixed>, string}>
     */
    public static function missingKeyProvider(): iterable
    {
        yield 'empty, e.g. PICASSO_SIGN_KEY=' => ['', 'A Glide "sign_key" is empty'];
        yield 'empty list, e.g. PICASSO_SIGN_KEYS= with csv:' => [[], 'The Glide "sign_key" list is empty'];
        yield 'empty key in a list' => [[self::SIGN_KEY, ''], 'A Glide "sign_key" is empty'];
        yield 'not a string' => [[self::SIGN_KEY, 42], 'A Glide "sign_key" is empty'];
    }

    /**
     * @param string|array<mixed> $signKey
     */
    #[DataProvider('missingKeyProvider')]
    public function testMissingKeyIsRejected(string|array $signKey, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        new GlideSignature($signKey);
    }

    public function testShortKeyIsDeprecatedButStillSigns(): void
    {
        $shortKey = str_repeat('k', GlideSignature::MIN_KEY_LENGTH - 1);

        [$signature, $deprecations] = self::collectDeprecations(static fn (): GlideSignature => new GlideSignature([self::SIGN_KEY, $shortKey]));

        self::assertSame(['Since silarhi/picasso-bundle 2.7: A Glide "sign_key" shorter than 32 characters is deprecated: 3.0 will require at least 32. Generate one with "php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\'" and put it in front of the current key ("sign_key: [new, current]"), so the published URLs keep working.'], $deprecations);
        $signature->validateRequest('photo.jpg', ['s' => hash_hmac('sha256', 'photo.jpg?', $shortKey)]);
    }

    public function testKeyOfTheMinimumLengthIsNotDeprecated(): void
    {
        [$signature, $deprecations] = self::collectDeprecations(static fn (): GlideSignature => new GlideSignature(str_repeat('k', GlideSignature::MIN_KEY_LENGTH)));

        self::assertSame([], $deprecations);
        self::assertSame(64, strlen($signature->generateSignature('photo.jpg', [])));
    }

    public function testFirstKeySignsAndEveryKeyValidates(): void
    {
        $rotated = new GlideSignature([self::NEW_KEY, self::SIGN_KEY], acceptLegacy: false);

        self::assertSame((new GlideSignature(self::NEW_KEY))->generateSignature('photo.jpg', ['w' => '300']), $rotated->generateSignature('photo.jpg', ['w' => '300']));

        // A URL published with the previous key keeps working during the rotation
        $rotated->validateRequest('photo.jpg', (new GlideSignature(self::SIGN_KEY))->addSignature('photo.jpg', ['w' => '300']));
        $rotated->validateRequest('photo.jpg', $rotated->addSignature('photo.jpg', ['w' => '300']));
        $this->addToAssertionCount(2);
    }

    public function testUrlSignedWithARemovedKeyIsRejected(): void
    {
        $published = (new GlideSignature([self::NEW_KEY, self::SIGN_KEY]))->addSignature('photo.jpg', ['w' => '300']);
        $legacy = (new Signature(self::SIGN_KEY))->addSignature('photo.jpg', ['w' => '300']);
        $afterRotation = new GlideSignature(self::NEW_KEY);

        // Signed with the new key: still valid
        $afterRotation->validateRequest('photo.jpg', $published);

        foreach ([(new GlideSignature(self::SIGN_KEY))->addSignature('photo.jpg', ['w' => '300']), $legacy] as $params) {
            try {
                $afterRotation->validateRequest('photo.jpg', $params);
                self::fail('A URL signed with a removed key must be rejected.');
            } catch (SignatureException $e) {
                self::assertSame('Signature is not valid.', $e->getMessage());
            }
        }
    }

    public function testLegacyMd5SignatureOfAnyKeyIsAcceptedWhileLegacySignaturesAreOn(): void
    {
        $legacy = (new Signature(self::SIGN_KEY))->addSignature('photo.jpg', ['w' => '300']);

        (new GlideSignature([self::NEW_KEY, self::SIGN_KEY]))->validateRequest('photo.jpg', $legacy);
        $this->addToAssertionCount(1);

        $this->expectException(SignatureException::class);
        (new GlideSignature([self::NEW_KEY, self::SIGN_KEY], acceptLegacy: false))->validateRequest('photo.jpg', $legacy);
    }

    public function testSignatureIsAnHmacSha256OfThePathAndTheSortedQuery(): void
    {
        $signature = new GlideSignature(self::SIGN_KEY);

        self::assertSame(
            hash_hmac('sha256', 'uploads/photo.jpg?fm=webp&w=300', self::SIGN_KEY),
            $signature->generateSignature('/uploads/photo.jpg', ['w' => 300, 'fm' => 'webp', 's' => 'ignored']),
        );
    }

    public function testAddedSignatureValidates(): void
    {
        $signature = new GlideSignature(self::SIGN_KEY, acceptLegacy: false);
        $params = $signature->addSignature('uploads/photo.jpg', ['w' => '300', 'fm' => 'webp']);

        self::assertSame(['w' => '300', 'fm' => 'webp', 's' => $signature->generateSignature('uploads/photo.jpg', ['w' => '300', 'fm' => 'webp'])], $params);

        $signature->validateRequest('uploads/photo.jpg', $params);
        // Leading slash and param order do not matter, as with Glide's signature
        $signature->validateRequest('/uploads/photo.jpg', ['s' => $params['s'], 'fm' => 'webp', 'w' => '300']);
        $this->addToAssertionCount(2);
    }

    public function testLegacyMd5SignatureIsAcceptedByDefault(): void
    {
        $legacy = (new Signature(self::SIGN_KEY))->addSignature('uploads/photo.jpg', ['w' => '300']);

        (new GlideSignature(self::SIGN_KEY))->validateRequest('uploads/photo.jpg', $legacy);
        $this->addToAssertionCount(1);
    }

    public function testLegacyMd5SignatureIsRejectedWhenLegacySignaturesAreOff(): void
    {
        $legacy = (new Signature(self::SIGN_KEY))->addSignature('uploads/photo.jpg', ['w' => '300']);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Signature is not valid.');

        (new GlideSignature(self::SIGN_KEY, acceptLegacy: false))->validateRequest('uploads/photo.jpg', $legacy);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function tamperedRequestProvider(): iterable
    {
        $signature = (new GlideSignature(self::SIGN_KEY))->generateSignature('uploads/photo.jpg', ['w' => '300']);
        $hmac = ['w' => '300', 's' => $signature];
        $legacy = (new Signature(self::SIGN_KEY))->addSignature('uploads/photo.jpg', ['w' => '300']);

        yield 'other param value' => ['uploads/photo.jpg', [...$hmac, 'w' => '3000']];
        yield 'added param' => ['uploads/photo.jpg', [...$hmac, 'h' => '10']];
        yield 'other path' => ['uploads/other.jpg', $hmac];
        yield 'one character changed' => ['uploads/photo.jpg', [...$hmac, 's' => substr($signature, 0, -1) . (str_ends_with($signature, '0') ? '1' : '0')]];
        yield 'truncated' => ['uploads/photo.jpg', [...$hmac, 's' => substr($signature, 0, 32)]];
        yield 'legacy signature of other params' => ['uploads/photo.jpg', [...$legacy, 'w' => '3000']];
        yield 'signed with another key' => ['uploads/photo.jpg', (new GlideSignature(strrev(self::SIGN_KEY)))->addSignature('uploads/photo.jpg', ['w' => '300'])];
        yield 'not a string' => ['uploads/photo.jpg', [...$hmac, 's' => [$signature]]];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('tamperedRequestProvider')]
    public function testTamperedRequestIsRejected(string $path, array $params): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Signature is not valid.');

        (new GlideSignature(self::SIGN_KEY))->validateRequest($path, $params);
    }

    public function testMissingSignatureIsRejected(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Signature is missing.');

        (new GlideSignature(self::SIGN_KEY))->validateRequest('uploads/photo.jpg', ['w' => '300']);
    }
}
