<?php

declare(strict_types=1);

namespace DK\OpenXml\Signature;

enum SignatureStatus
{
    case Unsigned;
    case Signed;
    case Malformed;
}
