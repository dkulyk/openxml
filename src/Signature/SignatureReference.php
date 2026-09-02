<?php

declare(strict_types=1);

namespace DK\OpenXml\Signature;

final class SignatureReference
{
    public const SIGNED_INFO = 'signed_info';
    public const MANIFEST = 'manifest';

    public function __construct(
        public readonly string $scope,
        public readonly string $uri,
        public readonly string $digestMethod,
        public readonly string $digestValue,
    ) {}
}
