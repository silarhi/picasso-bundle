<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Service;

final class MetadataGuesser implements MetadataGuesserInterface
{
    private const READ_SIZE = 65536;

    /**
     * Guess image dimensions and MIME type from a stream.
     * Reads only the first bytes needed for header detection.
     *
     * @param resource $stream
     *
     * @return array{width: int|null, height: int|null, mimeType: string|null}
     */
    public function guess($stream): array
    {
        $data = stream_get_contents($stream, self::READ_SIZE, 0);

        if (false === $data || '' === $data) {
            return ['width' => null, 'height' => null, 'mimeType' => null];
        }

        $info = @getimagesizefromstring($data);

        if (false === $info) {
            return ['width' => null, 'height' => null, 'mimeType' => null];
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
            'mimeType' => $info['mime'],
        ];
    }
}
