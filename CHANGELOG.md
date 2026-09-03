# Changelog

All notable changes to this project will be documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases use [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Successful saves reuse the verified temporary package fingerprint after
  atomic replacement instead of hashing the same output a second time.
- Part-name availability checks use an indexed exact, ancestor, and descendant
  lookup instead of scanning every package entry for each added part.
- Relationship parts can be accessed consistently through both `getPart()`
  and the raw part-reading APIs.
- Raw part-writing APIs reject relationship parts, which must be changed through
  the relationship API to keep the in-memory collection and XML synchronized.

### Fixed

- New packages write `[Content_Types].xml` as the first ZIP entry, as OPC
  expects for streaming readers, instead of appending it after every part.
- `hasPart()` returns `false` for package metadata and invalid OPC part names
  instead of throwing while performing an existence check.

## [0.6.0] - 2026-09-03

### Added

- Lazy streams for unchanged ZIP-backed parts, sharing one source archive whose
  container remains alive until the caller closes the last stream, with weak
  same-process coordination before atomic source replacement.
- Deferred part paths using native `zip://` URIs for unchanged entries and
  package-owned local materialization for staged content and path-only consumers.
- Local path-based APIs for adding and replacing large parts without first
  loading their contents into PHP strings.

### Changed

- Source files are fully hashed once when opened and immediately before an
  in-place save. Part reads use filesystem identity and timestamp metadata,
  avoiding work proportional to the entire package size on every access.
- `openStream()` now returns a lazy ZIP stream for unchanged parts; seekability
  is no longer guaranteed and callers requiring random access must use
  `getLocalPath()`.
- Source archives remain open for the package lifetime and are shared by its
  streams. Source replacement is rejected while a relevant stream is open;
  this also makes file-handle lifetime observable on Windows.

## [0.5.0] - 2026-09-02

### Added

- Read-only structural inspection of OPC digital-signature origins, signature
  parts, XMLDSig references, algorithms, and malformed signature graphs.
- Explicit staged removal of OPC signatures and related certificates before
  writing an intentionally unsigned package.
- Agile Encryption reading for AES-128/192/256 with SHA-1/256/384/512,
  bounded encryption metadata, safe cross-platform unsigned-size decoding,
  and an independently generated Microsoft Excel compatibility fixture.
- Strict ECMA-376 part-name validation, ASCII-case-insensitive lookup and
  collision detection, derivation checks, and RFC-style relative target resolution.
- Inbound relationship inspection, strict referenced-part removal, and an
  explicit cascading removal API with a structured result.
- Explicit package repair analysis and application for selected dangling or
  invalid relationships, orphan relationship parts, and content-type issues.
- Relationship lookup by raw or resolved target, explicit retargeting, and bulk
  removal of internal relationships to a resolved part.
- Atomic staged part moves with content-type migration, relationship-part moves,
  and automatic inbound and outgoing relationship target updates.
- OPC consistency checks for orphan relationship parts, stale content-type
  overrides, incorrect relationship content types, and invalid internal targets.
- Windows compatibility coverage and weekly, manually dispatchable LibreOffice interoperability and package benchmark workflows.
- A reproducible lazy-open, streamed-extraction, and ZIP copy-through benchmark.
- Pull-request, performance-reporting, stream-lifecycle, and compatibility-testing documentation.

### Changed

- Renamed the Composer package from `dkulyk/openxml` to
  `dkulyk/openxml-package` to make its OPC packaging scope explicit.
- Reorganized detailed package, encryption, security, and architecture guidance
  into focused documentation pages while keeping the README as a concise entry point.
- CI cancellation now groups repeated pull-request runs by pull-request number, and feature branches no longer create duplicate push and pull-request runs.
- GitHub Actions now uses the Node.js 24-based `actions/upload-artifact@v7` for benchmark artifacts.

### Fixed

- OPC relationship-part names and relative targets now use `/` separators independently of the host operating system.
- Saving after moving a part onto the name of a removed part no longer fails with a ZIP rename error.
- Packages saved to a new path now receive the permissions a regular file create would give under the current umask instead of `0600`.
- Part names with percent-encoded non-ASCII characters such as `/word/%C3%A9.xml` are accepted; ECMA-376 only forbids percent-encoded separators and unreserved characters.
- `getRelationships()` returns one live collection per source part, so repeated lookups no longer overwrite each other's changes or re-parse the relationship XML.

## [0.4.0] - 2026-09-02

### Added

- Lazy package opening that retains ZIP entry metadata without loading every part into memory.
- Public stream APIs for adding, reading, and replacing large binary parts.
- Temporary-file staging for streamed writes, allowing caller-owned input streams to be closed immediately.
- ZIP copy-through saves that preserve the compressed representation of unchanged entries.
- Coverage for binary stream round-trips, stream limits and rollback, lazy memory use, copy-through metadata, and concurrent source changes.

### Changed

- Updated GitHub Actions to `actions/checkout@v7` and moved Composer validation, PHP CS Fixer, PHPStan, and dependency auditing into a single PHP 8.1 quality job instead of repeating them across the test matrix.
- Packages now reopen their container after a successful atomic save, keeping lazy reads pinned to the newly written source.

## [0.3.0] - 2026-09-01

### Added

- Read-only ECMA-376 Standard Encryption support with automatic version detection, AES-128/192/256, SHA-1 password verification, and the fixed 50,000-iteration work factor.
- Compatibility coverage for all Standard AES key sizes, wrong passwords, and configured work limits; independently verified byte-for-byte against the public `msoffcrypto-tool` fixture.

### Changed

- `EncryptedOfficeFile::decrypt()` now transparently routes Agile and Standard encrypted OOXML files while encryption continues to produce modern Agile files only.

## [0.2.1] - 2026-09-01

### Changed

- Reorganized the README around installation and common package workflows.
- Added Packagist, PHP, CI, downloads, and license badges.
- Updated Composer metadata to describe encryption and decryption support.

## [0.2.0] - 2026-09-01

### Added

- File-signature detection for OPC ZIP, CFBF/OLE, encrypted Office Open XML, and unknown files.
- Explicit exceptions for missing optional CFBF support, malformed compound files, incomplete encryption streams, encrypted packages, and unsupported formats.
- Optional `dkulyk/compound-file` integration, tested against its 0.2 writer API.
- Agile Encryption read/write support with AES-256, SHA-512, password verification, data-integrity HMAC, segmented processing, and atomic output replacement.
- Encryption work-factor and decrypted-size limits for untrusted files.

### Changed

- `OpenXmlPackage::open()` now identifies the outer container before invoking ZIP handling.

## [0.1.0] - 2026-09-01

### Added

- OPC package, part, content-type, and relationship APIs.
- Package-level and part-level relationship navigation.
- Atomic edit sessions with dirty tracking, discard, validation, and optimistic concurrency protection.
- Configurable ZIP entry, expanded-size, compression-ratio, and XML-size limits.
- Strict XML root validation and DTD rejection.
- DOCX-, XLSX-, and PPTX-style integration tests, including binary media.
- PHPStan max-level analysis, PHP CS Fixer, PHPUnit, Composer audit, and PHP 8.1–8.5 CI.

### Security

- Unsafe and duplicate ZIP entry names are rejected.
- Suspicious compression ratios are rejected before entry extraction.
- Saving digitally signed packages is blocked until signature preservation is supported.

[Unreleased]: https://github.com/dkulyk/openxml-package/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/dkulyk/openxml-package/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/dkulyk/openxml-package/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/dkulyk/openxml-package/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/dkulyk/openxml-package/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/dkulyk/openxml-package/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/dkulyk/openxml-package/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/dkulyk/openxml-package/releases/tag/v0.1.0
