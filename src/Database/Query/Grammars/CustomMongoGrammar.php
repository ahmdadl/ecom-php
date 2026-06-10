<?php

declare(strict_types=1);

namespace App\Database\Query\Grammars;

use DateInvalidTimeZoneException;
use DateTimeInterface;
use DateTimeZone;
use MongoDB\Laravel\Query\Grammar as BaseGrammar;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Connection;
use stdClass;

use function array_key_exists;
use function date_default_timezone_get;
use function get_object_vars;
use function is_array;
use function is_object;
use function is_string;
use function property_exists;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function substr;

/** @property Connection $connection */
class CustomMongoGrammar extends BaseGrammar
{
    /**
     * @inheritDoc
     * 
     * Remote id => _id aliasing
     */
    public function prepareFieldsForQuery(array $values, bool $root = true): array
    {
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            // "->" arrow notation for subfields is an alias for "." dot notation
            if (str_contains($key, '->')) {
                $newKey = str_replace('->', '.', $key);
                if (array_key_exists($newKey, $values) && $value !== $values[$newKey]) {
                    throw new InvalidArgumentException(sprintf('Cannot have both "%s" and "%s" fields.', $key, $newKey));
                }

                $values[$newKey] = $value;
                unset($values[$key]);
                $key = $newKey;
            }
        }

        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->prepareFieldsForQuery($value, false);
            } elseif ($value instanceof DateTimeInterface) {
                $value = new UTCDateTime($value);
            }
        }

        return $values;
    }

    /**
     * @inheritDoc
     * 
     * Remote id => _id aliasing
     */
    public function prepareFieldsForResult(array|object $values, bool $root = true): array|object
    {
        if (is_array($values)) {
            foreach ($values as $key => $value) {
                if ($value instanceof UTCDateTime) {
                    $values[$key] = Date::instance($value->toDateTime())
                        ->setTimezone(new DateTimeZone(date_default_timezone_get()));
                } elseif (is_array($value) || is_object($value)) {
                    $values[$key] = $this->prepareFieldsForResult($value, false);
                }
            }
        }

        if ($values instanceof stdClass) {
            foreach (get_object_vars($values) as $key => $value) {
                if ($value instanceof UTCDateTime) {
                    $values->{$key} = Date::instance($value->toDateTime())
                        ->setTimezone(new DateTimeZone(date_default_timezone_get()));
                } elseif (is_array($value) || is_object($value)) {
                    $values->{$key} = $this->prepareFieldsForResult($value, false);
                }
            }
        }

        return $values;
    }
}
