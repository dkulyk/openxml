<?php

declare(strict_types=1);

namespace DK\OpenXml\Exception;

final class PackageValidationException extends OpenXmlException
{
    /**
     * @param list<string> $issues
     */
    public function __construct(private array $issues)
    {
        parent::__construct("Package validation failed:\n- " . implode("\n- ", $issues));
    }

    /** @return list<string> */
    public function getIssues(): array
    {
        return $this->issues;
    }
}
