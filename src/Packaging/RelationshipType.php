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

    private function __construct() {}
}
