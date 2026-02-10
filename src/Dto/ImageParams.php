<?php

namespace Silarhi\PicassoBundle\Dto;

/**
 * Agnostic image transformation parameters.
 *
 * This is the provider-neutral representation of what transformation
 * we want applied. Each ImageUrlGeneratorInterface implementation maps
 * these to provider-specific params (Glide, Cloudinary, Imgix, etc.).
 */
class ImageParams
{
    public function __construct(
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $format = null,
        public readonly ?int $quality = null,
        public readonly string $fit = 'contain',
        public readonly ?int $blur = null,
        public readonly ?int $dpr = null,
    ) {
    }

    public function withWidth(int $width): self
    {
        return new self($width, $this->height, $this->format, $this->quality, $this->fit, $this->blur, $this->dpr);
    }

    public function withHeight(int $height): self
    {
        return new self($this->width, $height, $this->format, $this->quality, $this->fit, $this->blur, $this->dpr);
    }

    public function withFormat(string $format): self
    {
        return new self($this->width, $this->height, $format, $this->quality, $this->fit, $this->blur, $this->dpr);
    }

    public function withQuality(int $quality): self
    {
        return new self($this->width, $this->height, $this->format, $quality, $this->fit, $this->blur, $this->dpr);
    }

    public function withFit(string $fit): self
    {
        return new self($this->width, $this->height, $this->format, $this->quality, $fit, $this->blur, $this->dpr);
    }

    public function withBlur(int $blur): self
    {
        return new self($this->width, $this->height, $this->format, $this->quality, $this->fit, $blur, $this->dpr);
    }

    public function withDpr(int $dpr): self
    {
        return new self($this->width, $this->height, $this->format, $this->quality, $this->fit, $this->blur, $dpr);
    }
}
