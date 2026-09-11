<?php
declare(strict_types=1);

namespace CakeAes\Model\Database\Dialect;

use Cake\Database\Connection;
use Cake\Database\Expression\FunctionExpression;
use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Expression\QueryExpression;
use CakeAes\Model\Database\EncryptionProfile;
use CakeAes\Model\Database\Expression\DecryptedExpression;

class MysqlEncryptionDialect implements DatabaseEncryptionDialect
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function encrypt(string $value): QueryExpression
    {
        $key = EncryptionProfile::key($this->connection);

        return new QueryExpression([
            new FunctionExpression('AES_ENCRYPT', [$value, $this->unhexKey($key)], ['string']),
        ]);
    }

    public function decrypt(string $fieldName): QueryExpression
    {
        $key = EncryptionProfile::key($this->connection);

        return new DecryptedExpression([
            new FunctionExpression('AES_DECRYPT', [
                new IdentifierExpression($fieldName),
                $this->unhexKey($key),
            ]),
        ]);
    }

    public function name(): string
    {
        return 'mysql';
    }

    private function unhexKey(string $key): FunctionExpression
    {
        return new FunctionExpression('UNHEX', [$key], ['string']);
    }
}
