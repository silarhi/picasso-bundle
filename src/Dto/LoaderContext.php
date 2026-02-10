<?php

namespace Silarhi\PicassoBundle\Dto;

/**
 * Context object passed to loaders.
 *
 * Provides a clean, extensible way to pass extra parameters
 * without polluting the LoaderInterface method signatures.
 */
class LoaderContext
{
    /**
     * @param string|object $source The image source: a file path or an entity object
     * @param string|null   $field  The uploadable field name (for entity-based loaders)
     * @param array          $extra  Arbitrary extra parameters for loader-specific needs
     */
    public function __construct(
        public readonly string|object $source,
        public readonly ?string $field = null,
        public readonly array $extra = [],
    ) {
    }

    public function isEntity(): bool
    {
        return \is_object($this->source);
    }

    public function getSourceAsString(): string
    {
        return (string) $this->source;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }
}
