<?php

declare(strict_types=1);

namespace DK\OpenXml;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\Exception\CfbfException;
use DK\OpenXml\Exception\InvalidCompoundFileException;
use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\MissingDependencyException;
use DK\OpenXml\Exception\OpenXmlException;

final class OfficeFileDetector
{
    private const CFBF_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const ZIP_SIGNATURES = ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"];

    public static function detect(string $filename): OfficeFileFormat
    {
        $signature = self::readSignature($filename);

        if ($signature === self::CFBF_SIGNATURE) {
            return self::inspectCompoundFile($filename);
        }

        if (in_array(substr($signature, 0, 4), self::ZIP_SIGNATURES, true)) {
            return OfficeFileFormat::OpcPackage;
        }

        return OfficeFileFormat::Unknown;
    }

    private static function inspectCompoundFile(string $filename): OfficeFileFormat
    {
        if (!class_exists(CompoundFile::class)) {
            throw new MissingDependencyException(
                'A CFBF file was detected. Install the optional reader with "composer require dkulyk/compound-file" to inspect it.',
            );
        }

        try {
            $compoundFile = CompoundFile::open($filename);
            $hasEncryptionInfo = $compoundFile->hasStream('EncryptionInfo');
            $hasEncryptedPackage = $compoundFile->hasStream('EncryptedPackage');

            if (!$hasEncryptionInfo && !$hasEncryptedPackage) {
                return OfficeFileFormat::CompoundFile;
            }

            if (!$hasEncryptionInfo) {
                throw new InvalidEncryptedPackageException(
                    'The CFBF container has an EncryptedPackage stream but no EncryptionInfo stream.',
                );
            }

            if (!$hasEncryptedPackage) {
                throw new InvalidEncryptedPackageException(
                    'The CFBF container has an EncryptionInfo stream but no EncryptedPackage stream.',
                );
            }

            self::validateEncryptionStreams($compoundFile);

            return OfficeFileFormat::EncryptedOpcPackage;
        } catch (InvalidEncryptedPackageException $exception) {
            throw $exception;
        } catch (CfbfException $exception) {
            throw new InvalidCompoundFileException(
                sprintf('The CFBF container is invalid: %s', $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    private static function validateEncryptionStreams(CompoundFile $compoundFile): void
    {
        $encryptionInfoSize = $compoundFile->openStream('EncryptionInfo')->getSize();
        if ($encryptionInfoSize < 8) {
            throw new InvalidEncryptedPackageException(sprintf(
                'The EncryptionInfo stream is too short: expected at least 8 bytes, got %d.',
                $encryptionInfoSize,
            ));
        }

        $encryptedPackageSize = $compoundFile->openStream('EncryptedPackage')->getSize();
        if ($encryptedPackageSize <= 8) {
            throw new InvalidEncryptedPackageException(sprintf(
                'The EncryptedPackage stream has no encrypted payload: expected more than 8 bytes, got %d.',
                $encryptedPackageSize,
            ));
        }
    }

    private static function readSignature(string $filename): string
    {
        if (!is_file($filename)) {
            throw new OpenXmlException(sprintf('File "%s" does not exist.', $filename));
        }

        $stream = @fopen($filename, 'rb');
        if ($stream === false) {
            throw new OpenXmlException(sprintf('Unable to read file "%s".', $filename));
        }

        try {
            $signature = fread($stream, 8);
            if ($signature === false) {
                throw new OpenXmlException(sprintf('Unable to read file "%s".', $filename));
            }

            return $signature;
        } finally {
            fclose($stream);
        }
    }
}
