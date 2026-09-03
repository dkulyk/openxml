# Security policy

## Supported versions

Until the first stable release, security fixes are applied to the latest `main` branch.

## Reporting a vulnerability

Please do not disclose vulnerabilities in a public issue. Use the repository's private GitHub security-advisory reporting flow:

<https://github.com/dkulyk/openxml-package/security/advisories/new>

Include a minimal reproducer, affected package structure, expected impact, and any known mitigations. Reports will be acknowledged as soon as practical.

Agile encrypted Office files are authenticated before plaintext is committed to
the destination; decrypted Agile and Standard packages are structurally
validated before replacement. Applications processing untrusted files should
configure `EncryptionLimits` to bound password-hash work and decrypted output
size. The library writes AES-256/SHA-512 Agile Encryption, reads the supported
Agile and Standard AES profiles documented in
[the encryption guide](docs/encryption.md), and intentionally rejects
unsupported cryptographic profiles.
