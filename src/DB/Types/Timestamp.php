<?php
declare(strict_types=1);

namespace Reut\DB\Types;

/**
 * Class Timestamp
 * Represents a TIMESTAMP column definition with optional CURRENT_TIMESTAMP helpers.
 */
class Timestamp extends ColumnType
{
    protected bool $useCurrentTimestampDefault;
    protected bool $useOnUpdateCurrentTimestamp;

    public function __construct(
        bool $nullable = true,
        bool $useCurrentTimestampDefault = true,
        bool $useOnUpdateCurrentTimestamp = false,
        bool $isPrimary = false
    ) {
        parent::__construct($nullable, null, $isPrimary);
        $this->name = "TIMESTAMP";
        $this->useCurrentTimestampDefault = $useCurrentTimestampDefault;
        $this->useOnUpdateCurrentTimestamp = $useOnUpdateCurrentTimestamp;
    }

    protected function getAdditionalSql(): string
    {
        $parts = [];

        // For nullable TIMESTAMP columns, explicitly set DEFAULT NULL
        // MySQL requires explicit DEFAULT NULL for nullable TIMESTAMP columns
        if ($this->nullable && !$this->useCurrentTimestampDefault) {
            $parts[] = "DEFAULT NULL";
        }

        if ($this->useCurrentTimestampDefault) {
            $parts[] = "DEFAULT CURRENT_TIMESTAMP";
        }

        if ($this->useOnUpdateCurrentTimestamp) {
            $parts[] = "ON UPDATE CURRENT_TIMESTAMP";
        }

        return $parts ? ' ' . implode(' ', $parts) : '';
    }
}

