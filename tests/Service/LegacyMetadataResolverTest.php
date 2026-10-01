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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Service\LegacyMetadataResolver;
use Silarhi\PicassoBundle\Tests\Fixtures\LegacyMetadataToken;

class LegacyMetadataResolverTest extends TestCase
{
    private const KEY = 'sign-key';

    public function testDecodesTheRootOfAVichToken(): void
    {
        $token = LegacyMetadataToken::mint(self::KEY, ['upload_destination' => 'avatars.storage']);

        self::assertSame('avatars.storage', $this->resolver()->decodeRoot($token));
    }

    public function testDecodesTheRootOfAFilesystemToken(): void
    {
        $token = LegacyMetadataToken::mint(self::KEY, ['path' => '/app/public/uploads']);

        self::assertSame('/app/public/uploads', $this->resolver()->decodeRoot($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokenProvider(): iterable
    {
        yield 'not base64' => ['%%%'];
        yield 'too short' => ['abc'];
        yield 'another key' => [LegacyMetadataToken::mint('another-key', ['path' => '/app'])];
        yield 'no root' => [LegacyMetadataToken::mint(self::KEY, ['field' => 'imageFile'])];
    }

    #[DataProvider('invalidTokenProvider')]
    public function testRejectsInvalidTokens(string $token): void
    {
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Invalid metadata parameter.');

        $this->resolver()->decodeRoot($token);
    }

    public function testFindsTheLoaderReadingARoot(): void
    {
        $resolver = $this->resolver();

        self::assertSame('avatars', $resolver->loaderForRoot('avatars.storage'));
        self::assertSame('uploads', $resolver->loaderForRoot('/app/public/uploads'));
        self::assertNull($resolver->loaderForRoot('documents.storage'));
        self::assertNull($resolver->loaderForRoot('/elsewhere/uploads/other'));
    }

    public function testMatchesDirectoriesOfAnotherReleaseByTheirPathInTheProject(): void
    {
        // 1.x tokens carry absolute paths: a deployment into a new release directory changes them
        $resolver = new LegacyMetadataResolver(self::KEY, [
            '/var/www/releases/43/public' => 'public',
            '/var/www/releases/43/public/uploads' => 'uploads',
        ], '/var/www/releases/43');

        self::assertSame('uploads', $resolver->loaderForRoot('/var/www/releases/42/public/uploads'));
        self::assertSame('uploads', $resolver->loaderForRoot('/var/www/releases/42/public/uploads/'));
        self::assertSame('public', $resolver->loaderForRoot('/var/www/releases/42/public'));
        self::assertNull($resolver->loaderForRoot('/var/www/releases/42/var/uploads'));
    }

    public function testResolvesTheLoaderOfAToken(): void
    {
        self::assertSame('avatars', $this->resolver()->resolveLoader(LegacyMetadataToken::mint(self::KEY, ['upload_destination' => 'avatars.storage'])));
    }

    public function testRejectsATokenWhoseRootNoLoaderReads(): void
    {
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('No loader reads "/srv/old", the source of this 1.x URL. Declare one.');

        $this->resolver()->resolveLoader(LegacyMetadataToken::mint(self::KEY, ['path' => '/srv/old']));
    }

    private function resolver(): LegacyMetadataResolver
    {
        return new LegacyMetadataResolver(self::KEY, [
            'avatars.storage' => 'avatars',
            '/app/public/uploads' => 'uploads',
        ], '/app');
    }
}
