<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\Exception\OpenXmlException;

final class PartName
{
    private function __construct() {}

    public static function normalize(string $name): string
    {
        if ($name === '' || str_contains($name, '\\') || str_contains($name, '#') || str_contains($name, '?')) {
            throw new OpenXmlException(sprintf('Invalid OPC part name "%s".', $name));
        }

        $segments = [];
        foreach (explode('/', '/' . ltrim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new OpenXmlException(sprintf('Part name escapes the package root: "%s".', $name));
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }
        if ($segments === []) {
            throw new OpenXmlException(sprintf('Invalid OPC part name "%s".', $name));
        }

        return '/' . implode('/', $segments);
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
        $directory = dirname($source);

        return ($directory === '/' ? '' : $directory) . '/_rels/' . basename($source) . '.rels';
    }

    public static function isRelationshipsPart(string $name): bool
    {
        $name = self::normalize($name);

        return $name === '/_rels/.rels' || (bool) preg_match('~/_rels/[^/]+\.rels$~', $name);
    }

    public static function resolveTarget(?string $sourcePartName, string $target): string
    {
        if ($target === '' || str_contains($target, '#') || str_contains($target, '?')) {
            throw new OpenXmlException(sprintf('Invalid internal relationship target "%s".', $target));
        }
        if (str_starts_with($target, '/')) {
            return self::normalize($target);
        }

        $base = $sourcePartName === null ? '/' : dirname(self::normalize($sourcePartName)) . '/';

        return self::normalize($base . $target);
    }
}
