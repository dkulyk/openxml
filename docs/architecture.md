# Architecture

The public surface follows Open Packaging Conventions terminology. ZIP and CFBF
are storage mechanisms rather than domain concepts exposed to package consumers.

```text
DK\OpenXml\OpenXmlPackage
├── Packaging\PackageInterface
├── Packaging\PartInterface / Part
├── Packaging\Relationships / RelationshipInterface
├── Packaging\ContentTypes
├── Signature\SignatureInspection / PackageSignature
└── Internal\Container\ContainerInterface
    ├── Internal\Container\ZipContainer
    └── Internal\MaterializationPool

DK\OpenXml\Encryption\EncryptedOfficeFile
├── Internal\Encryption\AgileEncryption (compatible profiles read, modern profile write)
├── Internal\Encryption\StandardEncryption (read-only)
└── dkulyk/compound-file (optional CFBF integration)
```

## Packaging boundary

`Packaging` is the public OPC API. `Internal\Container` hides ZIP implementation
details and is not a compatibility surface. The container retains entry metadata
when opening a package and loads content only when requested.

Streamed writes are staged in temporary storage. Reads of unchanged entries use
native lazy ZIP streams. A container opens its source archive once, shares it
between active entry streams, and is retained by each stream context until the
caller closes it. Unchanged entries also use ZIP copy-through during a save,
preserving their compressed representation. Complete output is validated in a
same-directory temporary file before atomic replacement.

Unchanged ZIP-backed parts can expose a native `zip://` URI to deferred
path-based consumers. When an entry is staged or a local path is required, the
internal materialization pool copies it to private temporary storage. This pool
is an implementation detail: callers receive ordinary strings, and the package
owns their lifetime.

The internal boundary allows container infrastructure to move into a shared
package later if ODF or another format demonstrates a real common abstraction. No
separate generic ZIP package is needed today.

## Domain boundary

This library owns OPC concerns only:

- package parts and part names;
- content-type declarations;
- package and part relationships;
- container safety, persistence, and Office encryption.

WordprocessingML, SpreadsheetML, and PresentationML object models belong in
specialized libraries built on top of this package. Resource deduplication also
remains outside the default package behavior until its relationship and semantic
policies are defined.

## Current limitations

- `getContents()` materializes a complete part; use `openStream()` or the path
  APIs for large payloads.
- Encrypted documents must be decrypted before opening them as OPC.
- Digital-signature structure can be inspected, but signatures are not
  cryptographically verified or preserved when saving.
- Atomic replacement requires same-directory rename support from the filesystem.
- Validation covers OPC structure, not the schemas of Office XML vocabularies.
