<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\FilesystemLoader;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\MetadataGuesser;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

class PicassoBundleTest extends TestCase
{
    private static KernelInterface $kernel;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new PicassoTestKernel('test', true);
        self::$kernel->boot();

        // Ensure the Glide cache directory exists before image processing tests
        $glideCache = self::$kernel->getCacheDir().'/glide';
        if (!is_dir($glideCache)) {
            mkdir($glideCache, 0777, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }

    /**
     * Returns the test service container which can access both public and
     * non-inlined private services via Symfony's TestContainer.
     */
    private static function getTestContainer(): ContainerInterface
    {
        $container = self::$kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    public function testContainerHasLoaderRegistry(): void
    {
        $container = self::getTestContainer();

        self::assertTrue($container->has('picasso.loader_registry'));
        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);
    }

    public function testContainerHasTransformerRegistry(): void
    {
        $container = self::getTestContainer();

        self::assertTrue($container->has('picasso.transformer_registry'));
        $registry = $container->get('picasso.transformer_registry');
        self::assertInstanceOf(TransformerRegistry::class, $registry);
    }

    public function testFilesystemLoaderIsRegistered(): void
    {
        $container = self::getTestContainer();
        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);

        self::assertTrue($registry->has('filesystem'));
        self::assertInstanceOf(FilesystemLoader::class, $registry->get('filesystem'));
    }

    public function testGlideTransformerIsRegistered(): void
    {
        $container = self::getTestContainer();
        $registry = $container->get('picasso.transformer_registry');
        self::assertInstanceOf(TransformerRegistry::class, $registry);

        self::assertTrue($registry->has('glide'));
        self::assertInstanceOf(GlideTransformer::class, $registry->get('glide'));
    }

    public function testMetadataGuesserIsRegistered(): void
    {
        $container = self::getTestContainer();

        self::assertTrue($container->has('picasso.metadata_guesser'));
        self::assertInstanceOf(MetadataGuesser::class, $container->get('picasso.metadata_guesser'));
    }

    public function testUrlEncryptionIsRegistered(): void
    {
        $container = self::getTestContainer();

        self::assertTrue($container->has('picasso.url_encryption'));
        self::assertInstanceOf(UrlEncryption::class, $container->get('picasso.url_encryption'));
    }

    public function testSrcsetGeneratorIsRegistered(): void
    {
        $container = self::getTestContainer();

        self::assertTrue($container->has('picasso.srcset_generator'));
        self::assertInstanceOf(SrcsetGenerator::class, $container->get('picasso.srcset_generator'));
    }

    public function testPipelineIsRegistered(): void
    {
        $container = self::getTestContainer();

        self::assertTrue($container->has('picasso.pipeline'));
        self::assertInstanceOf(ImagePipeline::class, $container->get('picasso.pipeline'));
    }

    public function testTwigExtensionIsRegistered(): void
    {
        $container = self::getTestContainer();

        self::assertTrue($container->has('.picasso.twig_extension'));
        self::assertInstanceOf(PicassoExtension::class, $container->get('.picasso.twig_extension'));
    }

    public function testPipelineGeneratesUrl(): void
    {
        $container = self::getTestContainer();
        $pipeline = $container->get('picasso.pipeline');
        self::assertInstanceOf(ImagePipeline::class, $pipeline);

        $url = $pipeline->url(
            new ImageReference('pixel.gif'),
            new ImageTransformation(width: 100, format: 'jpg'),
        );

        self::assertStringContainsString('/picasso/glide/filesystem/pixel.gif', $url);
        self::assertStringContainsString('w=100', $url);
        self::assertStringContainsString('fm=jpg', $url);
        self::assertStringContainsString('s=', $url);
    }

    public function testPipelineLoadsImageWithMetadata(): void
    {
        $container = self::getTestContainer();
        $pipeline = $container->get('picasso.pipeline');
        self::assertInstanceOf(ImagePipeline::class, $pipeline);

        $image = $pipeline->load(new ImageReference('pixel.gif'), withMetadata: true);

        self::assertSame('pixel.gif', $image->path);
        self::assertSame(1, $image->width);
        self::assertSame(1, $image->height);
        self::assertSame('image/gif', $image->mimeType);
    }

    public function testControllerServesTransformedImage(): void
    {
        $container = self::getTestContainer();
        $pipeline = $container->get('picasso.pipeline');
        self::assertInstanceOf(ImagePipeline::class, $pipeline);

        $url = $pipeline->url(
            new ImageReference('pixel.gif'),
            new ImageTransformation(width: 1, format: 'jpg', quality: 75),
        );

        $request = Request::create($url);
        $response = self::$kernel->handle($request);

        self::assertSame(200, $response->getStatusCode(), 'Serve failed: '.$response->getContent());
        self::assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testTwigExtensionGeneratesUrl(): void
    {
        $container = self::getTestContainer();
        $extension = $container->get('.picasso.twig_extension');
        self::assertInstanceOf(PicassoExtension::class, $extension);

        $url = $extension->imageUrl('pixel.gif', ['width' => 50, 'format' => 'gif']);

        self::assertStringContainsString('/picasso/glide/filesystem/pixel.gif', $url);
        self::assertStringContainsString('w=50', $url);
        self::assertStringContainsString('fm=gif', $url);
    }

    public function testControllerReturns404ForInvalidSignature(): void
    {
        $request = Request::create('/picasso/glide/filesystem/pixel.gif?w=100&s=invalid');
        $response = self::$kernel->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testSrcsetGeneratorProducesWidths(): void
    {
        $container = self::getTestContainer();
        $generator = $container->get('picasso.srcset_generator');
        self::assertInstanceOf(SrcsetGenerator::class, $generator);

        $widths = $generator->getWidths('100vw', null);

        self::assertNotEmpty($widths);
        self::assertContains(640, $widths);
        self::assertContains(1920, $widths);
    }

    public function testUrlEncryptionRoundTrip(): void
    {
        $container = self::getTestContainer();
        $encryption = $container->get('picasso.url_encryption');
        self::assertInstanceOf(UrlEncryption::class, $encryption);

        $original = '/var/uploads/images';
        $encrypted = $encryption->encrypt($original);
        $decrypted = $encryption->decrypt($encrypted);

        self::assertSame($original, $decrypted);
        self::assertNotSame($original, $encrypted);
    }
}
