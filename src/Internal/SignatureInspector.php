<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Internal\Container\ContainerInterface;
use DK\OpenXml\Packaging\ContentTypes;
use DK\OpenXml\Packaging\PartName;
use DK\OpenXml\Packaging\RelationshipInterface;
use DK\OpenXml\Packaging\Relationships;
use DK\OpenXml\Packaging\RelationshipType;
use DK\OpenXml\Signature\PackageSignature;
use DK\OpenXml\Signature\SignatureInspection;
use DK\OpenXml\Signature\SignatureReference;
use DK\OpenXml\Signature\SignatureStatus;

/** @internal Performs structural inspection only; it does not verify cryptographic signatures. */
final class SignatureInspector
{
    private const XML_SIGNATURE_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';
    private const ORIGIN_CONTENT_TYPE = 'application/vnd.openxmlformats-package.digital-signature-origin';
    private const SIGNATURE_CONTENT_TYPE = 'application/vnd.openxmlformats-package.digital-signature-xmlsignature+xml';

    /** @var array<string, string> Lowercase part name => stored part name. */
    private array $partNames = [];

    public function __construct(
        private ContainerInterface $container,
        private ContentTypes $contentTypes,
        private int $maximumXmlBytes,
    ) {
        foreach ($container->entries() as $entryName) {
            if ($entryName !== '[Content_Types].xml') {
                $partName = '/' . $entryName;
                $this->partNames[strtolower($partName)] = $partName;
            }
        }
    }

    public function inspect(): SignatureInspection
    {
        $issues = [];
        $signatureParts = [];
        $hasSignatureMaterial = $this->hasSignatureMaterial();
        $packageRelationships = $this->readRelationships(null, $issues, $hasSignatureMaterial);
        if ($packageRelationships === null) {
            return $this->result(null, [], $issues, $hasSignatureMaterial);
        }

        $originRelationships = $packageRelationships->getByType(RelationshipType::DIGITAL_SIGNATURE_ORIGIN);
        if ($originRelationships === []) {
            if ($hasSignatureMaterial) {
                $issues[] = 'Signature material exists without a package digital-signature origin relationship.';
            }

            return $this->result(null, [], $issues, $hasSignatureMaterial);
        }
        if (count($originRelationships) !== 1) {
            $issues[] = 'A package must have exactly one digital-signature origin relationship.';
        }

        $originPartName = $this->targetPartName($originRelationships[0], $issues, 'Digital-signature origin');
        if ($originPartName === null) {
            return $this->result(null, [], $issues, true);
        }

        $originPartName = $this->storedPartName($originPartName);
        if ($originPartName === null) {
            $issues[] = 'The digital-signature origin relationship targets a missing part.';

            return $this->result(null, [], $issues, true);
        }
        if ($this->contentTypes->getForPart($originPartName) !== self::ORIGIN_CONTENT_TYPE) {
            $issues[] = sprintf('Digital-signature origin part "%s" has an invalid content type.', $originPartName);
        }

        $originRelationships = $this->readRelationships($originPartName, $issues, true);
        if ($originRelationships === null) {
            return $this->result($originPartName, [], $issues, true);
        }

        $relationships = $originRelationships->getByType(RelationshipType::DIGITAL_SIGNATURE);
        if ($relationships === []) {
            $issues[] = sprintf('Digital-signature origin part "%s" has no signature relationships.', $originPartName);
        }

        $linkedSignatureParts = [];
        foreach ($relationships as $relationship) {
            $partName = $this->targetPartName($relationship, $issues, 'Digital-signature');
            if ($partName === null) {
                continue;
            }

            $partName = $this->storedPartName($partName);
            if ($partName === null) {
                $issues[] = sprintf('Digital-signature relationship "%s" targets a missing part.', $relationship->getId());

                continue;
            }
            $linkedSignatureParts[strtolower($partName)] = true;
            if ($this->contentTypes->getForPart($partName) !== self::SIGNATURE_CONTENT_TYPE) {
                $issues[] = sprintf('Digital-signature part "%s" has an invalid content type.', $partName);

                continue;
            }

            try {
                $signatureParts[] = $this->readSignature($partName);
            } catch (OpenXmlException $exception) {
                $issues[] = sprintf('Digital-signature part "%s" is invalid: %s', $partName, $exception->getMessage());
            }
        }

        foreach ($this->signaturePartNames() as $partName) {
            if (!isset($linkedSignatureParts[strtolower($partName)])) {
                $issues[] = sprintf('Digital-signature part "%s" is not linked from the origin part.', $partName);
            }
        }

        return $this->result($originPartName, $signatureParts, $issues, true);
    }

    /** @param list<string> $issues */
    private function readRelationships(
        ?string $sourcePartName,
        array &$issues,
        bool $reportInvalid,
    ): ?Relationships {
        $partName = PartName::relationshipsName($sourcePartName);
        $storedName = $this->storedPartName($partName);
        if ($storedName === null) {
            if ($reportInvalid && $sourcePartName !== null) {
                $issues[] = sprintf('Digital-signature origin part "%s" has no relationship part.', $sourcePartName);
            }

            return $sourcePartName === null ? new Relationships() : null;
        }

        try {
            return Relationships::fromXml(
                $this->container->read(PartName::entry($storedName)),
                sourcePartName: $sourcePartName,
                maximumXmlBytes: $this->maximumXmlBytes,
            );
        } catch (OpenXmlException $exception) {
            if ($reportInvalid) {
                $issues[] = sprintf('Signature relationship part "%s" is invalid: %s', $storedName, $exception->getMessage());
            }

            return null;
        }
    }

    /** @param list<string> $issues */
    private function targetPartName(
        RelationshipInterface $relationship,
        array &$issues,
        string $description,
    ): ?string {
        if ($relationship->isExternal()) {
            $issues[] = sprintf('%s relationship "%s" must be internal.', $description, $relationship->getId());

            return null;
        }

        try {
            return $relationship->getTargetPartName();
        } catch (OpenXmlException $exception) {
            $issues[] = sprintf('%s relationship "%s" has an invalid target: %s', $description, $relationship->getId(), $exception->getMessage());

            return null;
        }
    }

    private function readSignature(string $partName): PackageSignature
    {
        $document = XmlDocument::load(
            $this->container->read(PartName::entry($partName)),
            'Signature',
            self::XML_SIGNATURE_NAMESPACE,
            $this->maximumXmlBytes,
        );
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ds', self::XML_SIGNATURE_NAMESPACE);
        $signedInfo = self::singleElement($xpath, '/ds:Signature/ds:SignedInfo', 'SignedInfo');
        $canonicalization = self::algorithmElement(
            $xpath,
            '/ds:Signature/ds:SignedInfo/ds:CanonicalizationMethod',
            'CanonicalizationMethod',
        );
        $signatureMethod = self::algorithmElement(
            $xpath,
            '/ds:Signature/ds:SignedInfo/ds:SignatureMethod',
            'SignatureMethod',
        );
        self::singleElement($xpath, '/ds:Signature/ds:SignatureValue', 'SignatureValue');

        $references = [];
        $signedInfoReferences = $xpath->query('ds:Reference', $signedInfo);
        foreach ($signedInfoReferences === false ? [] : $signedInfoReferences as $node) {
            if ($node instanceof \DOMElement) {
                $references[] = self::readReference($xpath, $node, SignatureReference::SIGNED_INFO);
            }
        }
        $manifestReferences = $xpath->query('/ds:Signature/ds:Object/ds:Manifest/ds:Reference');
        foreach ($manifestReferences === false ? [] : $manifestReferences as $node) {
            if ($node instanceof \DOMElement) {
                $references[] = self::readReference($xpath, $node, SignatureReference::MANIFEST);
            }
        }
        if ($references === []) {
            throw new OpenXmlException('Signature contains no references.');
        }

        $id = $document->documentElement?->getAttribute('Id') ?? '';

        return new PackageSignature(
            $partName,
            $id === '' ? null : $id,
            $canonicalization,
            $signatureMethod,
            $references,
        );
    }

    private static function readReference(
        \DOMXPath $xpath,
        \DOMElement $reference,
        string $scope,
    ): SignatureReference {
        $digestMethod = self::algorithmElement($xpath, 'ds:DigestMethod', 'DigestMethod', $reference);
        $digestValue = trim(self::singleElement($xpath, 'ds:DigestValue', 'DigestValue', $reference)->textContent);
        if ($digestValue === '' || base64_decode($digestValue, true) === false) {
            throw new OpenXmlException('DigestValue must contain valid base64 data.');
        }

        return new SignatureReference(
            $scope,
            $reference->getAttribute('URI'),
            $digestMethod,
            $digestValue,
        );
    }

    private static function algorithmElement(
        \DOMXPath $xpath,
        string $expression,
        string $description,
        ?\DOMNode $contextNode = null,
    ): string {
        $algorithm = self::singleElement($xpath, $expression, $description, $contextNode)->getAttribute('Algorithm');
        if ($algorithm === '') {
            throw new OpenXmlException(sprintf('%s has no Algorithm.', $description));
        }

        return $algorithm;
    }

    private static function singleElement(
        \DOMXPath $xpath,
        string $expression,
        string $description,
        ?\DOMNode $contextNode = null,
    ): \DOMElement {
        $nodes = $xpath->query($expression, $contextNode);
        $element = $nodes === false || $nodes->length !== 1 ? null : $nodes->item(0);
        if (!$element instanceof \DOMElement) {
            throw new OpenXmlException(sprintf('Signature must contain exactly one %s element.', $description));
        }

        return $element;
    }

    private function hasSignatureMaterial(): bool
    {
        foreach ($this->partNames as $partName) {
            if (
                str_starts_with(strtolower($partName), '/_xmlsignatures/')
                || in_array($this->contentTypes->getForPart($partName), [self::ORIGIN_CONTENT_TYPE, self::SIGNATURE_CONTENT_TYPE], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function signaturePartNames(): array
    {
        $partNames = [];
        foreach ($this->partNames as $partName) {
            if ($this->contentTypes->getForPart($partName) === self::SIGNATURE_CONTENT_TYPE) {
                $partNames[] = $partName;
            }
        }

        return $partNames;
    }

    private function storedPartName(string $partName): ?string
    {
        return $this->partNames[strtolower($partName)] ?? null;
    }

    /**
     * @param list<PackageSignature> $signatures
     * @param list<string>           $issues
     */
    private function result(
        ?string $originPartName,
        array $signatures,
        array $issues,
        bool $hasSignatureMaterial,
    ): SignatureInspection {
        $status = !$hasSignatureMaterial && $issues === []
            ? SignatureStatus::Unsigned
            : ($issues === [] && $signatures !== [] ? SignatureStatus::Signed : SignatureStatus::Malformed);

        return new SignatureInspection($status, $originPartName, $signatures, $issues);
    }
}
