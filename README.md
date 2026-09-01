# DK OpenXml

[![Latest Stable Version](https://img.shields.io/packagist/v/dkulyk/openxml.svg)](https://packagist.org/packages/dkulyk/openxml)
[![Total Downloads](https://img.shields.io/packagist/dt/dkulyk/openxml.svg)](https://packagist.org/packages/dkulyk/openxml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/dkulyk/openxml/php.svg)](https://packagist.org/packages/dkulyk/openxml)
[![CI](https://github.com/dkulyk/openxml/actions/workflows/ci.yml/badge.svg)](https://github.com/dkulyk/openxml/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/dkulyk/openxml.svg)](https://github.com/dkulyk/openxml/blob/main/LICENSE)

A PHP 8.1+ library for reading, creating, editing, encrypting, and decrypting
Open Packaging Conventions (OPC) packages used by DOCX, XLSX, and PPTX files.

The library works with package-level concepts—parts, content types, and
relationships. It does not attempt to model WordprocessingML, SpreadsheetML,
or PresentationML documents.

## Features

- Read and write OPC parts and `[Content_Types].xml` declarations.
- Stream large binary parts without keeping their contents in PHP strings.
- Navigate, create, and remove package-level and part-level relationships.
- Resolve internal relationship targets relative to their source parts.
- Stage edits in memory and save through atomic file replacement.
- Detect OPC ZIP, CFBF/OLE, encrypted OOXML, and unknown containers.
- Encrypt and decrypt Office files using ECMA-376 Agile Encryption.
- Apply configurable limits to ZIP expansion, XML parsing, password work, and decrypted output.
- Reject unsafe ZIP paths, duplicate entries, DTDs, malformed encryption metadata, and modified ciphertext.

## Requirements

- PHP 8.1 or newer.
- DOM extension.
- ZIP extension.

Encrypted Office file support additionally requires:

- [`dkulyk/compound-file:^0.2`](https://packagist.org/packages/dkulyk/compound-file).
- OpenSSL extension.

## Installation

Install the core OPC package:

```shell
composer require dkulyk/openxml
```

Install the optional CFBF dependency when you need encrypted Office file
detection, encryption, or decryption:

```shell
composer require dkulyk/compound-file:^0.2
```

## Reading a package

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;

$package = OpenXmlPackage::open('document.docx');

$document = $package
    ->getRelationships()
    ->firstByType(RelationshipType::OFFICE_DOCUMENT)
    ?->getTargetPart();

$xml = $document?->getContents();
```

Part relationships are resolved relative to their source part:

```php
$styles = $document
    ?->getRelationships()
    ->firstByType('urn:styles')
    ?->getTargetPart();
```

External relationships keep their URI in `getTarget()` and return `null` from
`getTargetPart()`.

## Creating a package

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;

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
$package->saveAs('document.docx');
```

### Streaming large parts

Use streams for images, embedded files, and other large binary parts:

```php
$image = fopen('photo.png', 'rb');
if ($image === false) {
    throw new RuntimeException('Unable to open photo.png.');
}

try {
    $part = $package->addPartFromStream(
        '/word/media/image1.png',
        'image/png',
        $image,
    );
} finally {
    fclose($image);
}
```

The input is consumed from its current position to EOF and staged in temporary
storage, so it can be closed immediately. To read or replace an existing part:

```php
$contents = $part->openStream();
try {
    stream_copy_to_stream($contents, $destination);
} finally {
    fclose($contents);
}

$part->setContentsFromStream($replacement);
```

The caller owns streams returned by `openStream()` and must close them. Small
parts can continue to use `getContents()` and `setContents()`.

`openStream()` returns an independent temporary stream. It remains usable even
if the package object is later released. For writes, `addPartFromStream()` and
`setContentsFromStream()` consume the input from its current cursor position to
EOF, copy it to package-owned temporary storage, and never close the caller's
resource. The original input may therefore be closed as soon as the method
returns.

Package and part relationship collections support lookup, filtering, creation,
and removal. Mutations made through a collection obtained from the package are
persisted automatically.

## Editing and atomic saves

Changes remain in memory until `save()` or `saveAs()` is called:

```php
$package = OpenXmlPackage::open('document.docx');
$package->getPart('/word/document.xml')->setContents('<updated/>');

if ($package->hasChanges()) {
    $package->save();
}
```

The complete ZIP is written to a temporary file in the destination directory,
validated, and then moved over the destination. For an existing package, unchanged
ZIP entries are copied through with their existing compressed representation;
only staged entries are replaced. Validation failures and write errors leave the
original file untouched. Saving and lazy reads are rejected when the source changed
on disk after it was opened.

Discard staged changes with:

```php
$package->discardChanges();
```

For a focused edit, the callback API saves only after the callback completes:

```php
OpenXmlPackage::edit('document.docx', function (OpenXmlPackage $package): void {
    $package->getPart('/word/document.xml')->setContents('<updated/>');
});
```

## Detecting the container

`OpenXmlPackage::open()` identifies the outer container before ZIP handling.
Applications can use the same detector to route files themselves:

```php
use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;

$format = OfficeFileDetector::detect('document.docx');

if ($format === OfficeFileFormat::EncryptedOpcPackage) {
    // Ask for a password and use EncryptedOfficeFile::decrypt().
}
```

For a CFBF signature, detection requires `dkulyk/compound-file`. Without it,
`MissingDependencyException` provides the installation command. Incomplete or
invalid encryption streams produce dedicated exceptions instead of falling
through to a generic ZIP error.

## Encrypting and decrypting Office files

The encryption API writes ECMA-376 Agile Encryption with AES-256-CBC, SHA-512,
a 100,000-iteration password hash, random salts, password verification, and an
integrity HMAC. Decryption automatically recognizes both modern Agile files and
older Standard Encryption files using AES-128, AES-192, or AES-256 with SHA-1.

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

Payloads are processed in 4096-byte segments. A destination is replaced only
after encryption, integrity, and OPC validation succeed. A wrong password or
modified ciphertext leaves an existing destination untouched.

New encrypted files always use Agile Encryption. Standard Encryption is read-only
compatibility support because it lacks Agile's authenticated integrity metadata.
Extensible Encryption, RC4, XOR obfuscation, and encrypted legacy binary Office
formats (`.doc`, `.xls`, and `.ppt`) are not supported.

## Processing untrusted files

ZIP entries are checked before their contents are loaded into memory. Applications
can restrict entry count, expanded sizes, compression ratio, and parsed XML size:

```php
use DK\OpenXml\Security\PackageLimits;

$package = OpenXmlPackage::open('upload.docx', new PackageLimits(
    maximumEntries: 2_000,
    maximumPartBytes: 32 * 1024 * 1024,
    maximumPackageBytes: 256 * 1024 * 1024,
    maximumCompressionRatio: 200.0,
    maximumXmlBytes: 8 * 1024 * 1024,
));
```

Encrypted input has separate limits for attacker-controlled password work and
decrypted output size:

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

## Public API reference

### `OpenXmlPackage`

| Method | Description |
| --- | --- |
| `create(?PackageLimits $limits = null): self` | Create an empty OPC package. |
| `open(string $filename, ?PackageLimits $limits = null): self` | Open and validate an OPC ZIP package lazily. |
| `edit(string $filename, callable $edit, ?PackageLimits $limits = null): void` | Apply an edit and atomically save when the callback succeeds. |
| `hasPart(string $name): bool` | Check whether a part exists. |
| `getPart(string $name): PartInterface` | Return a part without loading its contents. |
| `getParts(): Traversable` | Iterate ordinary package parts. |
| `addPart(string $name, string $contentType, string $contents): PartInterface` | Add or replace a small string-backed part. |
| `addPartFromStream(string $name, string $contentType, resource $stream): PartInterface` | Stage bytes from the stream's current position to EOF. |
| `removePart(string $name): void` | Remove a part and its relationship part. |
| `getRelationships(?string $sourcePartName = null): Relationships` | Read package or part relationships. |
| `validate(): array` | Return structural issues without saving. |
| `hasChanges(): bool` | Report whether edits are staged. |
| `discardChanges(): void` | Restore the source package or reset a new package. |
| `save(): void` | Atomically replace the opened source. |
| `saveAs(string $filename): void` | Atomically write to another path. |

### `PartInterface`

| Method | Description |
| --- | --- |
| `getName(): string` | Return the normalized absolute part name. |
| `getContentType(): string` | Return the registered MIME content type. |
| `getContents(): string` | Materialize and return the complete part. |
| `setContents(string $contents): void` | Stage complete string contents. |
| `openStream(): resource` | Return an independent readable temporary stream owned by the caller. |
| `setContentsFromStream(resource $stream): void` | Stage data from the current cursor to EOF. |
| `getRelationships(): Relationships` | Return relationships originating at this part. |
| `addRelationship(...)` | Create an internal or external relationship. |
| `removeRelationship(string $id): void` | Remove a relationship by ID. |

### Encryption and detection

| API | Description |
| --- | --- |
| `OfficeFileDetector::detect(string $filename): OfficeFileFormat` | Identify OPC ZIP, CFBF, encrypted OPC, or unknown input. |
| `EncryptedOfficeFile::encrypt(...)` | Write Agile AES-256/SHA-512 encryption. |
| `EncryptedOfficeFile::decrypt(...)` | Atomically decrypt Agile or Standard Encryption into a validated OPC package. |
| `PackageLimits` | Bound ZIP entries, expanded bytes, ratios, and parsed XML. |
| `EncryptionLimits` | Bound password work and decrypted output size. |

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
├── Internal\Encryption\AgileEncryption (read/write)
├── Internal\Encryption\StandardEncryption (read-only)
└── dkulyk/compound-file (optional CFBF integration)
```

`Packaging` is the public OPC API. ZIP remains behind an internal container
boundary and keeps only entry metadata in memory when opening a package. Streamed
writes are staged in temporary files, while unchanged entries use ZIP copy-through.
The container can later move to shared package infrastructure without changing the
OPC surface. Domain-specific Office XML models belong in libraries built on top of
this package.

## Current limitations

- `getContents()` still materializes a complete part; use `openStream()` for large payloads.
- Encrypted documents must be explicitly decrypted before opening them as OPC.
- Digitally signed packages can be inspected, but saving is blocked because signatures cannot be preserved yet.
- Atomic replacement requires same-directory rename support from the destination filesystem.
- Validation covers OPC structure and integrity, not the WordprocessingML, SpreadsheetML, or PresentationML schemas.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for the complete pull-request workflow,
performance requirements, and compatibility reporting guidance.

```shell
composer install
composer check
composer benchmark
```

The quality pipeline runs Composer validation, PHP CS Fixer, PHPStan at level
`max` with strict rules, dependency auditing, and PHPUnit on PHP 8.1–8.5 plus
Windows. Weekly, manually dispatchable workflows exercise LibreOffice
interoperability and publish an observational package benchmark artifact.

Run the optional LibreOffice test locally with:

```shell
SOFFICE=/path/to/soffice vendor/bin/phpunit tests/LibreOfficeInteropTest.php
```

## License

DK OpenXml is open-source software licensed under the
[MIT License](https://github.com/dkulyk/openxml/blob/main/LICENSE).
