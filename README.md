# DK OpenXml

`dkulyk/openxml` is a PHP 8.1+ library for reading and writing the Open Packaging Conventions (OPC) layer used by DOCX, XLSX, and PPTX files.

It intentionally owns OPC concepts while keeping ZIP behind an internal boundary. A generic container package can be extracted later if an ODF implementation demonstrates a real shared API.

## Installation

```shell
composer require dkulyk/openxml
```

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

## Architecture

```text
DK\OpenXml\OpenXmlPackage
├── Packaging\PackageInterface
├── Packaging\PartInterface / Part
├── Packaging\Relationships / RelationshipInterface
├── Packaging\ContentTypes
└── Internal\Container\ContainerInterface
    └── Internal\Container\ZipContainer
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
