# Changelog

All notable changes to this project will be documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases use [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/dkulyk/openxml/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/dkulyk/openxml/releases/tag/v0.1.0
