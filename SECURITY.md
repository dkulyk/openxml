# Security policy

## Supported versions

Until the first stable release, security fixes are applied to the latest `main` branch.

## Reporting a vulnerability

Please do not disclose vulnerabilities in a public issue. Use the repository's private GitHub security-advisory reporting flow:

<https://github.com/dkulyk/openxml/security/advisories/new>

Include a minimal reproducer, affected package structure, expected impact, and any known mitigations. Reports will be acknowledged as soon as practical.

Encrypted Office files are authenticated before plaintext is committed to the
destination. Applications processing untrusted files should configure
`EncryptionLimits` to bound password-hash work and decrypted output size. The
library currently supports only AES-256/SHA-512 Agile Encryption and intentionally
rejects unsupported cryptographic profiles.
