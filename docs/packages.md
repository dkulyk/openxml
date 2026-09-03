# Working with packages

`OpenXmlPackage` exposes the Open Packaging Conventions concepts shared by DOCX,
XLSX, and PPTX: parts, content types, and relationships. It deliberately does not
interpret the XML vocabulary inside a part.

Part names are strict absolute OPC IRIs such as `/word/document.xml`. Empty or
dot segments, trailing dots, malformed or aliasing percent encodings, query
strings, and fragments are rejected rather than silently normalized. Part-name
lookup and collision detection use the ASCII-case-insensitive equivalence rules
defined by OPC. Relative relationship targets remain valid and are resolved using
their source part as the base.

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

## Paths for deferred consumers

Some image libraries and document writers accept a filename but not a PHP stream.
Use `getReadablePath()` when they understand PHP stream-wrapper paths:

```php
$package = OpenXmlPackage::open('slides.pptx');
$image = $package->getPart('/ppt/media/image1.png');

$writer->setImagePath($image->getReadablePath());
```

For an unchanged part in an opened ZIP, this normally returns a `zip://` URI and
avoids copying the entry before the consumer reads it. New and modified parts are
materialized automatically. Use `getLocalPath()` when the consumer specifically
requires a real local filesystem path.

```php
$writer->setImagePath($image->getLocalPath());
```

The package owns materialized files. A returned local path remains valid while
the package is alive, including after that part is modified again. Keep the
package alive until every deferred consumer has finished reading its paths.

Local files can also be copied into a part without loading them into a string:

```php
$image = $package->addPartFromPath(
    '/ppt/media/image2.png',
    'image/png',
    'photo.png',
);

$image->setContentsFromPath('replacement.png');
```

Path input is copied immediately and is restricted to readable local files. Use
the stream methods for other PHP stream wrappers.

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
| `addPartFromPath(string $name, string $contentType, string $path): PartInterface` | Stage bytes copied from a readable local file. |
| `removePart(string $name): void` | Remove an unreferenced part and its relationship part. |
| `getInboundRelationships(string $partName): array` | Return package and part relationships targeting a part. |
| `removePartAndRelationships(string $name): PartRemovalResult` | Explicitly remove a part and every inbound relationship. |
| `movePart(string $source, string $destination): PartInterface` | Move a part and update relationships that depend on its name. |
| `getRelationships(?string $sourcePartName = null): Relationships` | Read package or part relationships. |
| `inspectSignatures(): SignatureInspection` | Inspect the OPC signature structure and return its status, parts, references, and issues. |
| `removeSignatures(): SignatureRemovalResult` | Explicitly stage removal of signature parts, certificates, relationships, and content types. |
| `validate(): array` | Return structural issues without saving. |
| `hasChanges(): bool` | Report whether edits are staged. |
| `discardChanges(): void` | Restore the source package or reset a new package. |
| `save(): void` | Atomically replace the opened source. |
| `saveAs(string $filename): void` | Atomically write to another path. |

`inspectSignatures()` is structural inspection, not cryptographic verification.
See [Digital signatures](signatures.md) for its trust boundary and examples.

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
| `getReadablePath(): string` | Return a `zip://` URI when possible, otherwise a package-owned local path. |
| `getLocalPath(): string` | Return a package-owned local filesystem path. |
| `setContentsFromStream(resource $stream): void` | Stage data from the current cursor to EOF. |
| `setContentsFromPath(string $path): void` | Stage bytes copied from a readable local file. |
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

## Explicit package repair

Repair is opt-in and separated into analysis and application. Enable only the
operations appropriate for the application:

```php
use DK\OpenXml\Repair\PackageRepairOptions;

$options = new PackageRepairOptions(
    removeDanglingRelationships: true,
    removeInvalidRelationships: true,
    removeOrphanRelationshipParts: true,
    removeStaleContentTypeOverrides: true,
    correctRelationshipContentTypes: true,
);

$report = $package->analyzeRepairs($options);
foreach ($report as $action) {
    echo $action->description, PHP_EOL;
}

$applied = $package->applyRepairs($options);
$package->save();
```

`analyzeRepairs()` never stages changes. `applyRepairs()` returns the same typed
actions and modifies only the categories enabled in `PackageRepairOptions`.
Malformed relationship XML and digital signatures are reported by validation but
are not repaired automatically because their intended meaning cannot be inferred.

`movePart()` also moves the part's relationship part. It rewrites relationships
that target the moved part and adjusts its relative outgoing targets when the
part changes directory. Existing destination parts are never overwritten.

`removePart()` refuses to remove a referenced part and throws
`PartInUseException`, whose references identify every relationship source and ID.
Use the explicitly cascading operation only when removing all inbound references
is intended:

```php
$references = $package->getInboundRelationships('/word/media/image1.png');

$result = $package->removePartAndRelationships('/word/media/image1.png');
foreach ($result->getRemovedRelationships() as $reference) {
    echo $reference->sourcePartName ?? 'package', ': ',
        $reference->relationship->getId(), PHP_EOL;
}
```

Relationships to other shared resources are unchanged. Both removal methods also
remove the deleted part's own relationship part and content-type override.
