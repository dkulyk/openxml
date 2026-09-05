<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

/**
 * Which part payloads are worth deflating.
 *
 * The list is positive and exact: a content type is on it only when its payload
 * is already a compressed stream, so anything unrecognised, and compressible
 * formats that look like media (SVG, BMP, EMF, WMF), keep being deflated.
 * Matching a family by prefix is what broke this before -- an embedded
 * presentation and every slide in the package share the type prefix
 * "…officedocument.presentationml.", and only the container is a ZIP.
 */
final class ContentCompression
{
    private function __construct() {}

    /**
     * @var array<string, true> Content types whose payload is already a compressed
     *                          stream, so deflating it again only costs time.
     */
    private const STORED = [
        // Images and audio that carry their own compression.
        'image/png' => true,
        'image/jpeg' => true,
        'image/gif' => true,
        'image/webp' => true,
        'image/heic' => true,
        'image/heif' => true,
        'image/avif' => true,
        'image/jp2' => true,
        'audio/mpeg' => true,
        'audio/mp4' => true,
        'audio/ogg' => true,
        'audio/webm' => true,
        // Archives, including the OPC and ODF documents a package embeds whole.
        'application/zip' => true,
        'application/gzip' => true,
        'application/x-zip-compressed' => true,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => true,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template' => true,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => true,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template' => true,
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => true,
        'application/vnd.openxmlformats-officedocument.presentationml.template' => true,
        'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => true,
        'application/vnd.ms-word.document.macroenabled.12' => true,
        'application/vnd.ms-word.template.macroenabled.12' => true,
        'application/vnd.ms-excel.sheet.macroenabled.12' => true,
        'application/vnd.ms-excel.sheet.binary.macroenabled.12' => true,
        'application/vnd.ms-excel.template.macroenabled.12' => true,
        'application/vnd.ms-excel.addin.macroenabled.12' => true,
        'application/vnd.ms-powerpoint.presentation.macroenabled.12' => true,
        'application/vnd.ms-powerpoint.template.macroenabled.12' => true,
        'application/vnd.ms-powerpoint.slideshow.macroenabled.12' => true,
        'application/vnd.ms-powerpoint.addin.macroenabled.12' => true,
        'application/vnd.oasis.opendocument.text' => true,
        'application/vnd.oasis.opendocument.text-master' => true,
        'application/vnd.oasis.opendocument.text-template' => true,
        'application/vnd.oasis.opendocument.spreadsheet' => true,
        'application/vnd.oasis.opendocument.spreadsheet-template' => true,
        'application/vnd.oasis.opendocument.presentation' => true,
        'application/vnd.oasis.opendocument.presentation-template' => true,
        'application/vnd.oasis.opendocument.graphics' => true,
        'application/vnd.oasis.opendocument.graphics-template' => true,
        'application/vnd.oasis.opendocument.chart' => true,
        'application/vnd.oasis.opendocument.formula' => true,
    ];

    /** @var array<string, true> Types registered by the application, on top of the list above. */
    private static array $registered = [];

    /**
     * Register content types whose payload is already a compressed stream, so that
     * parts of those types are stored rather than deflated.
     *
     * Formats outlive releases of this library: a codec the list does not know
     * about is registered once at start-up instead of being special-cased at every
     * call site. Registration is additive and process-wide, and re-registering a
     * type is harmless. A single part that disagrees with its type is better served
     * by the $compress argument of addPart() and writePart().
     */
    public static function store(string ...$contentTypes): void
    {
        foreach ($contentTypes as $contentType) {
            self::$registered[self::normalize($contentType)] = true;
        }
    }

    /** Forget every registered type. Mostly for tests, which should not leak into each other. */
    public static function reset(): void
    {
        self::$registered = [];
    }

    /** Whether deflate is worth spending on a part of this content type. */
    public static function compresses(?string $contentType): bool
    {
        if ($contentType === null) {
            return true;
        }

        $type = self::normalize($contentType);

        // video/ is the one family whose every member is a compressed stream.
        return !isset(self::STORED[$type])
            && !isset(self::$registered[$type])
            && !str_starts_with($type, 'video/');
    }

    /** The bare type, lowercased and without parameters: "image/PNG; charset=binary" is "image/png". */
    private static function normalize(string $contentType): string
    {
        $parameters = strpos($contentType, ';');

        return strtolower(trim($parameters === false ? $contentType : substr($contentType, 0, $parameters)));
    }
}
