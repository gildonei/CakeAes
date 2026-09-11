<?php
declare(strict_types=1);

namespace CakeAes\Model\Database\Type;

use Cake\Database\Driver;
use Cake\Database\Type\BinaryType;
use \Exception;
/**
 * Binary type converter.
 *
 * Use to convert AES_ENCRYPT data between PHP and the database types.
 */
//class AesType extends BinaryType implements ExpressionTypeInterface
class AesType extends BinaryType
{
    /**
     * Convert varbinary into resource handles
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     * @return string|null
     * @throws Exception
     */
    public function toPHP(mixed $value, Driver $driver): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) || is_numeric($value) || is_resource($value)) {
            if (is_resource($value)) {
                if (get_resource_type($value) !== 'stream') {
                    throw new Exception('Expected a stream resource.');
                }
                $content = stream_get_contents($value);
                if ($content === false) {
                    throw new Exception('Unable to read binary stream.');
                }
                return $content;
            }
            return (string)$value;
        }
        throw new Exception(sprintf('Unable to convert %s into binary.', gettype($value)));
    }
}
