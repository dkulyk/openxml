<?php

declare(strict_types=1);

namespace DK\OpenXml\Signature;

final class SignatureContentType
{
    public const ORIGIN = 'application/vnd.openxmlformats-package.digital-signature-origin';
    public const XML_SIGNATURE = 'application/vnd.openxmlformats-package.digital-signature-xmlsignature+xml';
    public const CERTIFICATE = 'application/vnd.openxmlformats-package.digital-signature-certificate';

    private function __construct() {}
}
