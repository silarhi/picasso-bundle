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

    public function testAnyNonEmptyKeyIsAcceptedWithoutDeprecation(): void
    {
        // Like Symfony's secrets: no minimum length, only a recommendation
        [$signature, $deprecations] = self::collectDeprecations(static fn (): GlideSignature => new GlideSignature([self::SIGN_KEY, 'k']));

        self::assertSame([], $deprecations);
        self::assertSame(43, strlen($signature->generateSignature('photo.jpg', [])));
        $signature->validateRequest('photo.jpg', ['s' => $this->base64UrlHmac('photo.jpg?', 'k')]);
    }

    public function testFirstKeySignsAndEveryKeyValidates(): void
    {
        $rotated = new GlideSignature([self::NEW_KEY, self::SIGN_KEY]);

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

    public function testLegacyMd5SignatureOfAnyKeyIsAccepted(): void
    {
        $signature = new GlideSignature([self::NEW_KEY, self::SIGN_KEY]);

        // Until 3.0, URLs signed the Glide way keep working, whichever listed key signed them
        $signature->validateRequest('photo.jpg', (new Signature(self::NEW_KEY))->addSignature('photo.jpg', ['w' => '300']));
        $signature->validateRequest('photo.jpg', (new Signature(self::SIGN_KEY))->addSignature('photo.jpg', ['w' => '300']));
        $this->addToAssertionCount(2);
    }

    public function testSignatureIsAnHmacSha256OfThePathAndTheSortedQuery(): void
    {
        $signature = new GlideSignature(self::SIGN_KEY);

        self::assertSame(
            $this->base64UrlHmac('uploads/photo.jpg?fm=webp&w=300', self::SIGN_KEY),
            $signature->generateSignature('/uploads/photo.jpg', ['w' => 300, 'fm' => 'webp', 's' => 'ignored']),
        );
    }

    public function testAddedSignatureValidates(): void
    {
        $signature = new GlideSignature(self::SIGN_KEY);
        $params = $signature->addSignature('uploads/photo.jpg', ['w' => '300', 'fm' => 'webp']);

        self::assertSame(['w' => '300', 'fm' => 'webp', 's' => $signature->generateSignature('uploads/photo.jpg', ['w' => '300', 'fm' => 'webp'])], $params);

        $signature->validateRequest('uploads/photo.jpg', $params);
        // Leading slash and param order do not matter, as with Glide's signature
        $signature->validateRequest('/uploads/photo.jpg', ['s' => $params['s'], 'fm' => 'webp', 'w' => '300']);
        $this->addToAssertionCount(2);
    }

    public function testLegacyMd5SignatureIsAccepted(): void
    {
        $legacy = (new Signature(self::SIGN_KEY))->addSignature('uploads/photo.jpg', ['w' => '300']);

        (new GlideSignature(self::SIGN_KEY))->validateRequest('uploads/photo.jpg', $legacy);
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, array<mixed>}>
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
     * @param array<mixed> $params
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function signedPathProvider(): iterable
    {
        for ($width = 1; $width <= 64; ++$width) {
            yield 'w=' . $width => ['uploads/photo.jpg?w=' . $width];
        }
    }

    /**
     * Encoded like Symfony's UriSigner: 43 URL-safe characters, never "+", "/" nor "=".
     */
    #[DataProvider('signedPathProvider')]
    public function testSignatureIsBase64UrlWithoutPadding(string $payload): void
    {
        $signature = (new GlideSignature(self::SIGN_KEY))->generateSignature('uploads/photo.jpg', ['w' => substr($payload, strlen('uploads/photo.jpg?w='))]);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $signature);
        self::assertSame(rawurlencode($signature), $signature, 'The router and the URL templates must leave it unencoded.');
        self::assertSame(hash_hmac('sha256', $payload, self::SIGN_KEY, true), base64_decode(strtr($signature, '-_', '+/'), true));
    }

    private function base64UrlHmac(string $payload, string $key): string
    {
        return strtr(rtrim(base64_encode(hash_hmac('sha256', $payload, $key, true)), '='), ['/' => '_', '+' => '-']);
    }
}
