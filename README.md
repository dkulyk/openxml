# DK OpenXml

`dkulyk/openxml` is a PHP 8.1+ library for reading and writing the Open Packaging Conventions (OPC) layer used by DOCX, XLSX, and PPTX files.

It intentionally owns OPC concepts while keeping ZIP behind an internal boundary. A generic container package can be extracted later if an ODF implementation demonstrates a real shared API.

## Installation

```shell
composer require dkulyk/openxml
```

Encrypted Office files use a CFBF container instead of a directly readable ZIP.
Install the optional compound-file reader when your application needs to recognize
them:

```shell
composer require dkulyk/compound-file
```

## Detecting the file format

`OpenXmlPackage::open()` checks the container before invoking ZIP handling. CFBF,
encrypted Office, malformed encrypted containers, unknown files, and ordinary OPC
ZIP packages therefore produce distinct results or exceptions.

```php
use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;

$format = OfficeFileDetector::detect('document.docx');

if ($format === OfficeFileFormat::EncryptedOpcPackage) {
    // Route the file to an encryption-aware reader.
}
```

For a CFBF signature, detection requires `dkulyk/compound-file`. Without it,
`MissingDependencyException` includes the installation command. A CFBF containing
both `EncryptionInfo` and `EncryptedPackage` is recognized as encrypted OOXML;
missing or structurally invalid encryption streams are rejected explicitly.

## Encrypting and decrypting Office files

The optional encryption API writes ECMA-376 Agile Encryption using AES-256,
SHA-512, a 100,000-iteration password hash, random salts, a password verifier,
and package integrity HMAC. It requires `dkulyk/compound-file:^0.2` and PHP's
OpenSSL extension.

```php
use DK\OpenXml\Encryption\EncryptedOfficeFile;

EncryptedOfficeFile::encrypt(
    source: 'document.docx',
    destination: 'protected.docx',
    password: 'a strong password',
);

EncryptedOfficeFile::decrypt(
    source: 'protected.docx',
    destination: 'decrypted.docx',
    password: 'a strong password',
);
```

Payload encryption and decryption are processed in 4096-byte segments. Output is
written through temporary files and replaces the destination only after encryption,
integrity, and OPC validation succeed. A wrong password or modified ciphertext does
not overwrite an existing destination.

Applications accepting untrusted encrypted files can lower the password-work and
decrypted-size limits:

```php
use DK\OpenXml\Encryption\EncryptionLimits;

EncryptedOfficeFile::decrypt(
    'upload.docx',
    'document.docx',
    $password,
    new EncryptionLimits(
        maximumSpinCount: 500_000,
        maximumDecryptedBytes: 256 * 1024 * 1024,
    ),
);
```

Only Agile Encryption with AES-256/CBC and SHA-512 is accepted by this API.
Standard, Extensible, RC4, and legacy binary Office encryption are deliberately
not implemented.

## Reading and navigating a package

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;

$package = OpenXmlPackage::open('document.docx');

$officeDocument = $package
    ->getRelationships()
    ->firstByType(RelationshipType::OFFICE_DOCUMENT)
    ?->getTargetPart();

$xml = $officeDocument?->getContents();
```

Relationships are resolved relative to their source part:

```php
$styles = $officeDocument
    ?->getRelationships()
    ->firstByType('urn:styles')
    ?->getTargetPart();
```

External relationships return `null` from `getTargetPart()` and retain their URI in `getTarget()`.

## Creating and updating a package

```php
$package = OpenXmlPackage::create();

$document = $package->addPart(
    '/word/document.xml',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
    '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
);

$package->addRelationship(
    RelationshipType::OFFICE_DOCUMENT,
    'word/document.xml',
);

$document->addRelationship('urn:styles', 'styles.xml');
$document->setContents('<updated/>');

$issues = $package->validate();
if ($issues !== []) {
    throw new RuntimeException(implode("\n", $issues));
}

$package->saveAs('document.docx');
```

Package and part relationship collections support lookup, filtering, creation, and removal. Collection mutations obtained from a package are persisted automatically.

## Edit sessions and atomic saves

Changes are staged in memory until they are explicitly saved:

```php
$package = OpenXmlPackage::open('document.docx');
$package->getPart('/word/document.xml')->setContents('<updated/>');

if ($package->hasChanges()) {
    $package->save();
}
```

`save()` and `saveAs()` write a complete ZIP to a temporary file in the destination directory, verify it, and only then replace the destination. Validation failures and write errors leave the original file untouched. A save is also rejected if another process changed the source file after it was opened.

Unsaved changes can be discarded:

```php
$package->discardChanges();
```

For small edits, the callback API opens the package and saves only after the callback completes successfully:

```php
OpenXmlPackage::edit('document.docx', function (OpenXmlPackage $package): void {
    $package->getPart('/word/document.xml')->setContents('<updated/>');
});
```

## Resource limits and untrusted packages

Packages are checked before ZIP entries are loaded into memory. The defaults limit entry count, individual and total expanded sizes, compression ratio, and parsed XML size. Applications can provide stricter limits:

```php
use DK\OpenXml\Security\PackageLimits;

$limits = new PackageLimits(
    maximumEntries: 2_000,
    maximumPartBytes: 32 * 1024 * 1024,
    maximumPackageBytes: 256 * 1024 * 1024,
    maximumCompressionRatio: 200.0,
    maximumXmlBytes: 8 * 1024 * 1024,
);

$package = OpenXmlPackage::open('upload.docx', $limits);
```

Unsafe or duplicate ZIP entry names, DTD declarations, unexpected OPC XML roots, and suspicious compression ratios are rejected. Limits reduce resource-exhaustion risk but should still be selected for the application's workload and deployment memory budget.

## Current limitations

- Parts are currently held in memory; lazy streams and copy-through writes are not implemented yet.
- Encrypted Office documents must be explicitly decrypted before they can be opened as OPC; transparent mutable encrypted edit sessions are not implemented yet.
- Digitally signed packages can be opened for inspection, but saving them is blocked because signatures cannot currently be preserved.
- Atomic replacement depends on the destination filesystem supporting same-directory rename. If replacement is unavailable, saving fails and leaves the original file untouched.
- Validation covers OPC structure and known integrity rules; it does not validate WordprocessingML, SpreadsheetML, or PresentationML schemas.

## Architecture

```text
DK\OpenXml\OpenXmlPackage
├── Packaging\PackageInterface
├── Packaging\PartInterface / Part
├── Packaging\Relationships / RelationshipInterface
├── Packaging\ContentTypes
└── Internal\Container\ContainerInterface
    └── Internal\Container\ZipContainer

DK\OpenXml\Encryption\EncryptedOfficeFile
└── Internal\Encryption\AgileEncryption
    └── dkulyk/compound-file (optional CFBF integration)
```

The `Packaging` namespace is public OPC API. `Internal\Container` is not public API and may move to a shared package later without changing the OPC surface.

The current layer covers package parts, `[Content_Types].xml`, package-level and part-level `.rels`, relative target resolution, external targets, read/write round-trips, and basic integrity validation. Domain models for WordprocessingML, SpreadsheetML, and PresentationML belong in specialized packages built on top of this library.

## Development

```shell
composer install
composer check
```

The quality pipeline runs Composer validation, PHP CS Fixer in dry-run mode,
PHPStan at level `max` with strict rules, and the PHPUnit suite. Individual
commands are available as `composer check-style`, `composer analyse`, and
`composer test`; use `composer format` to apply the code style automatically.

Licensed under the MIT License.
