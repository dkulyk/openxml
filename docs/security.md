# Security and limits

Treat uploaded Office documents as untrusted archives. Compressed data,
relationship XML, content types, and encryption metadata may all be controlled by
an attacker.

## OPC package limits

ZIP entries are inspected before their contents are materialized. Configure
limits appropriate for the application:

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Security\PackageLimits;

$package = OpenXmlPackage::open('upload.docx', new PackageLimits(
    maximumEntries: 2_000,
    maximumPartBytes: 32 * 1024 * 1024,
    maximumPackageBytes: 256 * 1024 * 1024,
    maximumCompressionRatio: 200.0,
    maximumXmlBytes: 8 * 1024 * 1024,
));
```

The package reader rejects unsafe and duplicate ZIP names, suspicious expansion
ratios, DTDs, malformed content-type and relationship XML, and data beyond the
configured limits. It also rejects case-equivalent part names, names derivable
from another part name, and percent-encoded aliases before part contents are read.

## Encryption limits

Encrypted input has separate bounds for attacker-controlled password work and
decrypted output size:

```php
use DK\OpenXml\Encryption\EncryptedOfficeFile;
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

Agile encryption integrity is authenticated before output replacement. Wrong
passwords, malformed metadata, and modified encrypted payloads fail with specific
exceptions.

## Validation boundary

The library validates OPC container structure and the integrity rules it manages.
It does not validate WordprocessingML, SpreadsheetML, or PresentationML schemas.
Applications handling those vocabularies remain responsible for domain-specific
validation.

Digitally signed packages may be inspected, but saving is blocked because a
rewrite cannot currently preserve their signatures.

For reporting vulnerabilities and supported release information, see the
[security policy](../SECURITY.md).
