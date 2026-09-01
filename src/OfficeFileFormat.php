<?php

declare(strict_types=1);

namespace DK\OpenXml;

enum OfficeFileFormat
{
    case OpcPackage;
    case EncryptedOpcPackage;
    case CompoundFile;
    case Unknown;
}
