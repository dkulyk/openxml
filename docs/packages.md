# Working with packages

`OpenXmlPackage` exposes the Open Packaging Conventions concepts shared by DOCX,
XLSX, and PPTX: parts, content types, and relationships. It deliberately does not
interpret the XML vocabulary inside a part.

## Reading parts and relationships

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;

$package = OpenXmlPackage::open('document.docx');

$document = $package
    ->getRelationships()
    ->firstByType(RelationshipType::OFFICE_DOCUMENT)
    ?->getTargetPart();

$styles = $document
    ?->getRelationships()
    ->firstByType('urn:styles')
    ?->getTargetPart();
```

Part relationship targets are resolved relative to their source part. External
relationships retain their URI in `getTarget()` and return `null` from
`getTargetPart()`.

## Creating a package

```php
$package = OpenXmlPackage::create();

$document = $package->addPart(
    '/word/document.xml',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
    '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
);

$package->addRelationship(RelationshipType::OFFICE_DOCUMENT, 'word/document.xml');
$document->addRelationship('urn:styles', 'styles.xml');
$package->saveAs('document.docx');
```

## Streaming large parts

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

Input streams are consumed from their current position to EOF and copied to
package-owned temporary storage. The library never closes the caller's resource,
and the caller may close it as soon as the method returns.

```php
$contents = $part->openStream();
try {
    stream_copy_to_stream($contents, $destination);
} finally {
    fclose($contents);
}

$part->setContentsFromStream($replacement);
```

The caller owns streams returned by `openStream()` and must close them. Each is
an independent temporary stream and remains usable if the package object is later
released. Use `getContents()` and `setContents()` for small parts.

## Atomic edits

Mutations remain staged until `save()` or `saveAs()`:

```php
$package = OpenXmlPackage::open('document.docx');
$package->getPart('/word/document.xml')->setContents('<updated/>');

if ($package->hasChanges()) {
    $package->save();
}
```

The complete ZIP is written to a temporary file in the destination directory,
validated, and moved over the destination. Unchanged entries retain their
compressed representation through ZIP copy-through. If validation or writing
fails, the original file remains untouched.

The library detects concurrent source changes before lazy reads and saves. Use
`discardChanges()` to restore the opened source state. For a focused edit, use a
callback that saves only after successful completion:

```php
OpenXmlPackage::edit('document.docx', function (OpenXmlPackage $package): void {
    $package->getPart('/word/document.xml')->setContents('<updated/>');
});
```

## Public API

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
| `movePart(string $source, string $destination): PartInterface` | Move a part and update relationships that depend on its name. |
| `getRelationships(?string $sourcePartName = null): Relationships` | Read package or part relationships. |
| `validate(): array` | Return structural issues without saving. |
| `hasChanges(): bool` | Report whether edits are staged. |
| `discardChanges(): void` | Restore the source package or reset a new package. |
| `save(): void` | Atomically replace the opened source. |
| `saveAs(string $filename): void` | Atomically write to another path. |

`validate()` reports missing relationship targets, invalid internal targets,
orphan relationship parts, missing or stale content-type declarations, incorrect
relationship content types, and unsupported digital-signature preservation.

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

### `Relationships`

| Method | Description |
| --- | --- |
| `get(string $id): RelationshipInterface` | Return a relationship by ID. |
| `firstByType(string $type): ?RelationshipInterface` | Return the first relationship with an exact type. |
| `getByType(string $type): array` | Return every relationship with an exact type. |
| `firstByTarget(string $target): ?RelationshipInterface` | Match the serialized target exactly. |
| `getByTarget(string $target): array` | Return all exact serialized-target matches. |
| `getByTargetPart(string $partName): array` | Match internal relationships by normalized resolved part name. |
| `retarget(string $id, string $target): RelationshipInterface` | Replace a target while preserving ID, type, and target mode. |
| `removeByTargetPart(string $partName): int` | Remove internal relationships to a resolved part and return their count. |

Raw target lookup distinguishes relative and absolute spellings. Resolved target
lookup treats `media/image.png` and `/word/media/image.png` as the same target
when both resolve from `/word/document.xml` to `/word/media/image.png`. External
relationships are never returned or removed by resolved-part operations.

`movePart()` also moves the part's relationship part. It rewrites relationships
that target the moved part and adjusts its relative outgoing targets when the
part changes directory. Existing destination parts are never overwritten.
