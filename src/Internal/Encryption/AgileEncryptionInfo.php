<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Encryption;

use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\UnsupportedEncryptionException;

/** @internal */
final class AgileEncryptionInfo
{
    private const ENCRYPTION_NS = 'http://schemas.microsoft.com/office/2006/encryption';
    private const PASSWORD_NS = 'http://schemas.microsoft.com/office/2006/keyEncryptor/password';

    public function __construct(
        public readonly string $keyDataSalt,
        public readonly string $passwordSalt,
        public readonly int $spinCount,
        public readonly string $encryptedVerifier,
        public readonly string $encryptedVerifierHash,
        public readonly string $encryptedKey,
        public readonly string $encryptedHmacKey,
        public readonly string $encryptedHmacValue,
    ) {}

    public static function fromStream(string $contents, int $maximumSpinCount): self
    {
        if (strlen($contents) < 9) {
            throw new InvalidEncryptedPackageException('The EncryptionInfo stream is too short.');
        }

        $header = unpack('vmajor/vminor/Vreserved', substr($contents, 0, 8));
        if ($header === false || $header['major'] !== 4 || $header['minor'] !== 4 || $header['reserved'] !== 0x40) {
            throw new UnsupportedEncryptionException('Only ECMA-376 Agile Encryption version 4.4 is supported.');
        }

        $xml = substr($contents, 8);
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new InvalidEncryptedPackageException('DTD declarations are not allowed in EncryptionInfo.');
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new InvalidEncryptedPackageException('EncryptionInfo contains invalid XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('e', self::ENCRYPTION_NS);
        $xpath->registerNamespace('p', self::PASSWORD_NS);
        $keyData = self::element($xpath, '/e:encryption/e:keyData');
        $integrity = self::element($xpath, '/e:encryption/e:dataIntegrity');
        $keyEncryptor = self::element($xpath, '/e:encryption/e:keyEncryptors/e:keyEncryptor');
        if ($keyEncryptor->getAttribute('uri') !== self::PASSWORD_NS) {
            throw new UnsupportedEncryptionException('Only password-based Agile Encryption is supported.');
        }
        $encryptedKey = self::element($xpath, '/e:encryption/e:keyEncryptors/e:keyEncryptor/p:encryptedKey');

        self::assertAlgorithms($keyData);
        self::assertAlgorithms($encryptedKey);

        $spinCount = self::integerAttribute($encryptedKey, 'spinCount');
        if ($spinCount < 1 || $spinCount > $maximumSpinCount) {
            throw new UnsupportedEncryptionException(sprintf(
                'Encryption spin count %d exceeds the configured maximum of %d.',
                $spinCount,
                $maximumSpinCount,
            ));
        }

        return new self(
            self::binaryAttribute($keyData, 'saltValue', 16),
            self::binaryAttribute($encryptedKey, 'saltValue', 16),
            $spinCount,
            self::binaryAttribute($encryptedKey, 'encryptedVerifierHashInput', 16),
            self::binaryAttribute($encryptedKey, 'encryptedVerifierHashValue', 64),
            self::binaryAttribute($encryptedKey, 'encryptedKeyValue', 32),
            self::binaryAttribute($integrity, 'encryptedHmacKey', 64),
            self::binaryAttribute($integrity, 'encryptedHmacValue', 64),
        );
    }

    public function toStream(): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $encryption = $document->createElementNS(self::ENCRYPTION_NS, 'encryption');
        $encryption->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:p', self::PASSWORD_NS);
        $document->appendChild($encryption);

        $keyData = $document->createElementNS(self::ENCRYPTION_NS, 'keyData');
        self::setAlgorithmAttributes($keyData, $this->keyDataSalt);
        $encryption->appendChild($keyData);

        $integrity = $document->createElementNS(self::ENCRYPTION_NS, 'dataIntegrity');
        $integrity->setAttribute('encryptedHmacKey', base64_encode($this->encryptedHmacKey));
        $integrity->setAttribute('encryptedHmacValue', base64_encode($this->encryptedHmacValue));
        $encryption->appendChild($integrity);

        $keyEncryptors = $document->createElementNS(self::ENCRYPTION_NS, 'keyEncryptors');
        $keyEncryptor = $document->createElementNS(self::ENCRYPTION_NS, 'keyEncryptor');
        $keyEncryptor->setAttribute('uri', self::PASSWORD_NS);
        $passwordKey = $document->createElementNS(self::PASSWORD_NS, 'p:encryptedKey');
        self::setAlgorithmAttributes($passwordKey, $this->passwordSalt);
        $passwordKey->setAttribute('spinCount', (string) $this->spinCount);
        $passwordKey->setAttribute('encryptedVerifierHashInput', base64_encode($this->encryptedVerifier));
        $passwordKey->setAttribute('encryptedVerifierHashValue', base64_encode($this->encryptedVerifierHash));
        $passwordKey->setAttribute('encryptedKeyValue', base64_encode($this->encryptedKey));
        $keyEncryptor->appendChild($passwordKey);
        $keyEncryptors->appendChild($keyEncryptor);
        $encryption->appendChild($keyEncryptors);

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new InvalidEncryptedPackageException('Unable to create EncryptionInfo XML.');
        }

        return pack('vvV', 4, 4, 0x40) . $xml;
    }

    private static function setAlgorithmAttributes(\DOMElement $element, string $salt): void
    {
        foreach ([
            'saltSize' => '16',
            'blockSize' => '16',
            'keyBits' => '256',
            'hashSize' => '64',
            'cipherAlgorithm' => 'AES',
            'cipherChaining' => 'ChainingModeCBC',
            'hashAlgorithm' => 'SHA512',
            'saltValue' => base64_encode($salt),
        ] as $name => $value) {
            $element->setAttribute($name, $value);
        }
    }

    private static function assertAlgorithms(\DOMElement $element): void
    {
        $expected = [
            'saltSize' => '16',
            'blockSize' => '16',
            'keyBits' => '256',
            'hashSize' => '64',
            'cipherAlgorithm' => 'AES',
            'cipherChaining' => 'ChainingModeCBC',
            'hashAlgorithm' => 'SHA512',
        ];
        foreach ($expected as $name => $value) {
            if ($element->getAttribute($name) !== $value) {
                throw new UnsupportedEncryptionException(sprintf(
                    'Unsupported Agile Encryption parameter %s="%s".',
                    $name,
                    $element->getAttribute($name),
                ));
            }
        }
    }

    private static function element(\DOMXPath $xpath, string $expression): \DOMElement
    {
        $nodes = $xpath->query($expression);
        $element = $nodes === false || $nodes->length !== 1 ? null : $nodes->item(0);
        if (!$element instanceof \DOMElement) {
            throw new InvalidEncryptedPackageException(sprintf('EncryptionInfo is missing %s.', $expression));
        }

        return $element;
    }

    private static function integerAttribute(\DOMElement $element, string $name): int
    {
        $value = $element->getAttribute($name);
        if ($value === '' || !ctype_digit($value)) {
            throw new InvalidEncryptedPackageException(sprintf('Invalid EncryptionInfo attribute %s.', $name));
        }

        return (int) $value;
    }

    private static function binaryAttribute(\DOMElement $element, string $name, int $expectedBytes): string
    {
        $value = base64_decode($element->getAttribute($name), true);
        if ($value === false || strlen($value) !== $expectedBytes) {
            throw new InvalidEncryptedPackageException(sprintf(
                'EncryptionInfo attribute %s must contain %d bytes.',
                $name,
                $expectedBytes,
            ));
        }

        return $value;
    }
}
