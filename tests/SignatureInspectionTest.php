<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;
use DK\OpenXml\Signature\SignatureReference;
use DK\OpenXml\Signature\SignatureStatus;
use PHPUnit\Framework\TestCase;

final class SignatureInspectionTest extends TestCase
{
    private const ORIGIN_CONTENT_TYPE = 'application/vnd.openxmlformats-package.digital-signature-origin';
    private const SIGNATURE_CONTENT_TYPE = 'application/vnd.openxmlformats-package.digital-signature-xmlsignature+xml';

    public function testUnsignedPackageHasAnEmptyInspectionResult(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');

        $inspection = $package->inspectSignatures();

        self::assertSame(SignatureStatus::Unsigned, $inspection->status);
        self::assertNull($inspection->originPartName);
        self::assertCount(0, $inspection);
        self::assertSame([], $inspection->getIssues());
    }

    public function testStructurallyValidSignatureCanBeInspectedWithoutClaimingVerification(): void
    {
        $package = $this->signedPackage($this->signatureXml());

        $inspection = $package->inspectSignatures();

        self::assertSame(SignatureStatus::Signed, $inspection->status);
        self::assertSame('/_xmlsignatures/origin.sigs', $inspection->originPartName);
        self::assertSame([], $inspection->getIssues());
        self::assertCount(1, $inspection);

        $signature = $inspection->getSignatures()[0];
        self::assertSame('/_xmlsignatures/sig1.xml', $signature->partName);
        self::assertSame('signature-1', $signature->id);
        self::assertSame('urn:canonicalization', $signature->canonicalizationMethod);
        self::assertSame('urn:signature', $signature->signatureMethod);
        self::assertCount(2, $signature->getReferences());
        self::assertSame(SignatureReference::SIGNED_INFO, $signature->getReferences()[0]->scope);
        self::assertSame('/document.xml?ContentType=application/xml', $signature->getReferences()[0]->uri);
        self::assertSame('urn:digest', $signature->getReferences()[0]->digestMethod);
        self::assertSame(SignatureReference::MANIFEST, $signature->getReferences()[1]->scope);

        $issues = $package->validate();
        self::assertCount(1, $issues);
        self::assertStringContainsString('signature preservation is not supported', $issues[0]);
    }

    public function testMalformedSignatureReturnsIssuesInsteadOfThrowing(): void
    {
        $xml = str_replace('<SignatureValue>c2lnbmF0dXJl</SignatureValue>', '', $this->signatureXml());
        $package = $this->signedPackage($xml);

        $inspection = $package->inspectSignatures();

        self::assertSame(SignatureStatus::Malformed, $inspection->status);
        self::assertCount(0, $inspection);
        self::assertCount(1, $inspection->getIssues());
        self::assertStringContainsString('SignatureValue', $inspection->getIssues()[0]);
    }

    public function testUnlinkedSignatureMaterialIsMalformed(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/_xmlsignatures/sig1.xml', self::SIGNATURE_CONTENT_TYPE, $this->signatureXml());

        $inspection = $package->inspectSignatures();

        self::assertSame(SignatureStatus::Malformed, $inspection->status);
        self::assertStringContainsString('without a package digital-signature origin', $inspection->getIssues()[0]);
    }

    public function testExternalOriginRelationshipIsMalformed(): void
    {
        $package = OpenXmlPackage::create();
        $package->addRelationship(
            RelationshipType::DIGITAL_SIGNATURE_ORIGIN,
            'https://example.test/origin.sigs',
            external: true,
        );

        $inspection = $package->inspectSignatures();

        self::assertSame(SignatureStatus::Malformed, $inspection->status);
        self::assertStringContainsString('must be internal', $inspection->getIssues()[0]);
    }

    public function testOriginWithoutSignatureRelationshipsIsMalformed(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/_xmlsignatures/origin.sigs', self::ORIGIN_CONTENT_TYPE, '');
        $package->addRelationship(
            RelationshipType::DIGITAL_SIGNATURE_ORIGIN,
            '_xmlsignatures/origin.sigs',
        );

        $inspection = $package->inspectSignatures();

        self::assertSame(SignatureStatus::Malformed, $inspection->status);
        self::assertStringContainsString('has no relationship part', $inspection->getIssues()[0]);
    }

    private function signedPackage(string $signatureXml): OpenXmlPackage
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->addPart('/_xmlsignatures/origin.sigs', self::ORIGIN_CONTENT_TYPE, '');
        $package->addPart('/_xmlsignatures/sig1.xml', self::SIGNATURE_CONTENT_TYPE, $signatureXml);
        $package->addRelationship(
            RelationshipType::DIGITAL_SIGNATURE_ORIGIN,
            '_xmlsignatures/origin.sigs',
        );
        $package->addRelationship(
            RelationshipType::DIGITAL_SIGNATURE,
            'sig1.xml',
            sourcePartName: '/_xmlsignatures/origin.sigs',
        );

        return $package;
    }

    private function signatureXml(): string
    {
        return <<<'XML'
            <Signature xmlns="http://www.w3.org/2000/09/xmldsig#" Id="signature-1">
              <SignedInfo>
                <CanonicalizationMethod Algorithm="urn:canonicalization"/>
                <SignatureMethod Algorithm="urn:signature"/>
                <Reference URI="/document.xml?ContentType=application/xml">
                  <DigestMethod Algorithm="urn:digest"/>
                  <DigestValue>ZGlnZXN0</DigestValue>
                </Reference>
              </SignedInfo>
              <SignatureValue>c2lnbmF0dXJl</SignatureValue>
              <Object>
                <Manifest>
                  <Reference URI="/_rels/.rels?ContentType=application/vnd.openxmlformats-package.relationships+xml">
                    <DigestMethod Algorithm="urn:digest"/>
                    <DigestValue>cmVsYXRpb25zaGlwcw==</DigestValue>
                  </Reference>
                </Manifest>
              </Object>
            </Signature>
            XML;
    }
}
