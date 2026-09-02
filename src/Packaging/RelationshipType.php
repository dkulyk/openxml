<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

final class RelationshipType
{
    public const OFFICE_DOCUMENT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';
    public const CORE_PROPERTIES = 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties';
    public const EXTENDED_PROPERTIES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties';
    public const CUSTOM_PROPERTIES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties';
    public const THUMBNAIL = 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail';
    public const HYPERLINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';
    public const DIGITAL_SIGNATURE_ORIGIN = 'http://schemas.openxmlformats.org/package/2006/relationships/digital-signature/origin';
    public const DIGITAL_SIGNATURE = 'http://schemas.openxmlformats.org/package/2006/relationships/digital-signature/signature';
    public const DIGITAL_SIGNATURE_CERTIFICATE = 'http://schemas.openxmlformats.org/package/2006/relationships/digital-signature/certificate';

    private function __construct() {}
}
