<?php
declare(strict_types=1);

namespace CakeAes\Model\Database\Dialect;

use Cake\Database\Expression\QueryExpression;

interface DatabaseEncryptionDialect
{
    public function encrypt(string $value): QueryExpression;

    public function decrypt(string $fieldName): QueryExpression;

    public function name(): string;
}
