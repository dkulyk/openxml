# Digital signatures

`OpenXmlPackage` can inspect the OPC digital-signature graph without changing
the package. The result distinguishes an unsigned package, structurally valid
signature material, and malformed signature material.

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Signature\SignatureStatus;

$package = OpenXmlPackage::open('document.docx');
$inspection = $package->inspectSignatures();

if ($inspection->status === SignatureStatus::Malformed) {
    foreach ($inspection->getIssues() as $issue) {
        echo $issue, PHP_EOL;
    }
}

foreach ($inspection as $signature) {
    echo $signature->partName, ': ', $signature->signatureMethod, PHP_EOL;

    foreach ($signature->getReferences() as $reference) {
        echo '  ', $reference->scope, ' ', $reference->uri, PHP_EOL;
    }
}
```

The inspection follows the package digital-signature origin relationship, the
origin's signature relationships, and their XMLDSig parts. It reports:

- the signature status and origin part name;
- each signature part, optional XML `Id`, canonicalization method, and signature method;
- references from `SignedInfo` and `Manifest`, including their URI, digest method, and encoded digest value;
- missing, external, unlinked, malformed, or incorrectly typed signature parts.

## Trust boundary

`SignatureStatus::Signed` means that the expected OPC and XMLDSig structure is
present and readable. It does **not** mean that the signature is authentic or
cryptographically valid.

The library does not currently:

- recalculate or compare reference digests;
- apply XML canonicalization or transforms;
- verify `SignatureValue`;
- validate certificates, certificate chains, timestamps, revocation, or signer trust.

Applications must not use structural inspection as an authorization or trust
decision. A future verifier can build on the inspected package graph while
keeping trust policy separate from package parsing.

## Saving signed packages

`validate()`, `save()`, and `saveAs()` reject packages containing signature
material. Changing any signed part, relationship, or package metadata may
invalidate a signature, and the library does not yet preserve or regenerate
signatures. This restriction also applies to malformed or unlinked signature
material so that it cannot be silently discarded.

The structures follow the digital-signature conventions in
[ECMA-376 Part 2](https://ecma-international.org/publications-and-standards/standards/ecma-376/)
and the [W3C XML Signature specification](https://www.w3.org/TR/xmldsig-core/).
