<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;

/**
 * @internal Stops a ZIP entry that inflates past the size its central directory declares.
 *
 * Package limits are checked once, against the directory metadata, when the container is
 * opened. Nothing downstream re-checks: libzip reports a size mismatch only after it has
 * already delivered every byte, and the PHP stream layer discards that error. Reading
 * through this filter caps the damage at one bucket rather than the whole expansion.
 */
final class DeclaredSizeFilter extends \php_user_filter
{
    public const NAME = 'dk-openxml.declared-size';

    private int $seen = 0;

    /**
     * Attach the filter to a read stream, bounding it at $declaredBytes.
     *
     * @param resource $stream
     */
    public static function attach($stream, string $entryName, int $declaredBytes): void
    {
        static $registered = false;
        if (!$registered) {
            $registered = stream_filter_register(self::NAME, self::class);
        }

        $filter = @stream_filter_append(
            $stream,
            self::NAME,
            STREAM_FILTER_READ,
            ['entryName' => $entryName, 'declaredBytes' => $declaredBytes],
        );
        if ($filter === false) {
            throw new OpenXmlException(sprintf('Unable to bound ZIP entry "%s" by its declared size.', $entryName));
        }
    }

    /** The failure a lying ZIP directory produces, shared with the container's own bounded read. */
    public static function exceeded(string $entryName, int $declaredBytes): PackageLimitException
    {
        return new PackageLimitException(sprintf(
            'Part "%s" expands beyond the %d bytes its ZIP directory declares.',
            $entryName,
            $declaredBytes,
        ));
    }

    /**
     * @param resource $in
     * @param resource $out
     *
     * @param-out int  $consumed
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        /** @var array{entryName: string, declaredBytes: int} $params */
        $params = $this->params;

        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            $data = $bucket->data;
            if (!is_string($data)) {
                throw new OpenXmlException(sprintf('Unreadable content in ZIP entry "%s".', $params['entryName']));
            }

            $this->seen += strlen($data);
            if ($this->seen > $params['declaredBytes']) {
                throw self::exceeded($params['entryName'], $params['declaredBytes']);
            }
            $consumed += strlen($data);
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
