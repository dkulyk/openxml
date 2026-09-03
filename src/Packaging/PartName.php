<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\Exception\OpenXmlException;

final class PartName
{
    private function __construct() {}

    /** @var array<string, true> */
    private static array $validated = [];

    public static function normalize(string $name): string
    {
        if (isset(self::$validated[$name])) {
            return $name;
        }
        if (
            $name === ''
            || !str_starts_with($name, '/')
            || str_ends_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, '#')
            || str_contains($name, '?')
            || preg_match('//u', $name) !== 1
        ) {
            throw new OpenXmlException(sprintf('Invalid OPC part name "%s".', $name));
        }

        foreach (explode('/', substr($name, 1)) as $segment) {
            self::validateSegment($segment, $name);
        }
        // Names repeat dozens of times per part during a build and save; the
        // cache is bounded in count and entry length so long-lived workers do
        // not accumulate every name a package ever presented.
        if (strlen($name) <= 255) {
            if (count(self::$validated) >= 4096) {
                self::$validated = [];
            }
            self::$validated[$name] = true;
        }

        return $name;
    }

    public static function entry(string $name): string
    {
        return ltrim(self::normalize($name), '/');
    }

    public static function relationshipsName(?string $sourcePartName = null): string
    {
        if ($sourcePartName === null) {
            return '/_rels/.rels';
        }

        $source = self::normalize($sourcePartName);
        $directory = self::directory($source);

        return ($directory === '/' ? '' : $directory) . '/_rels/' . self::filename($source) . '.rels';
    }

    public static function isRelationshipsPart(string $name): bool
    {
        $name = self::normalize($name);

        return $name === '/_rels/.rels' || (bool) preg_match('~/_rels/[^/]+\.rels$~', $name);
    }

    public static function relationshipSourceName(string $name): ?string
    {
        $name = self::normalize($name);
        if ($name === '/_rels/.rels') {
            return null;
        }

        if (preg_match('~^(.*)/_rels/([^/]+)\.rels$~', $name, $matches) !== 1) {
            throw new OpenXmlException(sprintf('Part is not an OPC relationship part: "%s".', $name));
        }

        return self::normalize(($matches[1] === '' ? '' : $matches[1]) . '/' . $matches[2]);
    }

    public static function resolveTarget(?string $sourcePartName, string $target): string
    {
        if (
            $target === ''
            || str_contains($target, '\\')
            || str_contains($target, '#')
            || str_contains($target, '?')
            || preg_match('//u', $target) !== 1
            || (!str_starts_with($target, '/') && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $target) === 1)
        ) {
            throw new OpenXmlException(sprintf('Invalid internal relationship target "%s".', $target));
        }

        $segments = str_starts_with($target, '/')
            ? []
            : self::directorySegments($sourcePartName);

        foreach (explode('/', ltrim($target, '/')) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new OpenXmlException(sprintf('Relationship target escapes the package root: "%s".', $target));
                }
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return self::normalize('/' . implode('/', $segments));
    }

    public static function relativeTarget(?string $sourcePartName, string $targetPartName): string
    {
        $targetSegments = explode('/', ltrim(self::normalize($targetPartName), '/'));
        if ($sourcePartName === null) {
            return implode('/', $targetSegments);
        }

        $sourceDirectory = trim(self::directory(self::normalize($sourcePartName)), '/');
        $sourceSegments = $sourceDirectory === '' ? [] : explode('/', $sourceDirectory);

        while ($sourceSegments !== [] && $targetSegments !== [] && $sourceSegments[0] === $targetSegments[0]) {
            array_shift($sourceSegments);
            array_shift($targetSegments);
        }

        return str_repeat('../', count($sourceSegments)) . implode('/', $targetSegments);
    }

    public static function conflicts(string $first, string $second): bool
    {
        $first = self::comparisonKey(self::normalize($first));
        $second = self::comparisonKey(self::normalize($second));

        return $first === $second
            || str_starts_with($first, $second . '/')
            || str_starts_with($second, $first . '/');
    }

    public static function equivalent(string $first, string $second): bool
    {
        return self::comparisonKey(self::normalize($first)) === self::comparisonKey(self::normalize($second));
    }

    /** @return list<string> */
    private static function directorySegments(?string $sourcePartName): array
    {
        if ($sourcePartName === null) {
            return [];
        }

        $directory = trim(self::directory(self::normalize($sourcePartName)), '/');

        return $directory === '' ? [] : explode('/', $directory);
    }

    private static function comparisonKey(string $name): string
    {
        return strtolower($name);
    }

    private static function validateSegment(string $segment, string $name): void
    {
        if (
            $segment === ''
            || str_ends_with($segment, '.')
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $segment) === 1
            || self::hasForbiddenPercentEncoding($segment)
            || preg_match(
                "/\\A(?:[A-Za-z0-9\\-._~!$&'()*+,;=:@%]|[^\\x00-\\x7F\\p{Z}\\p{C}])+\\z/u",
                $segment,
            ) !== 1
        ) {
            throw new OpenXmlException(sprintf('Invalid OPC part name "%s".', $name));
        }
    }

    private static function hasForbiddenPercentEncoding(string $segment): bool
    {
        preg_match_all('/%[0-9A-Fa-f]{2}/', $segment, $matches);
        foreach ($matches[0] as $encoding) {
            // ECMA-376 [M1.7] and [M1.8]: percent-encoded separators and
            // unreserved characters are aliases; encoded non-ASCII is the
            // standard way to store IRI part names in ZIP item names.
            $byte = hexdec(substr($encoding, 1));
            if (
                $byte === 0x2F
                || $byte === 0x5C
                || $byte >= 0x30 && $byte <= 0x39
                || $byte >= 0x41 && $byte <= 0x5A
                || $byte >= 0x61 && $byte <= 0x7A
                || in_array($byte, [0x2D, 0x2E, 0x5F, 0x7E], true)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function directory(string $name): string
    {
        $separator = strrpos($name, '/');
        if ($separator === false || $separator === 0) {
            return '/';
        }

        return substr($name, 0, $separator);
    }

    private static function filename(string $name): string
    {
        $separator = strrpos($name, '/');

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
