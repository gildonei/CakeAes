<?php
declare(strict_types=1);

namespace CakeAes\Model\Database\Expression;

use Cake\Database\Expression\QueryExpression;
use Cake\Database\ValueBinder;

/** Converts decrypted bytes without interpolating values into SQL. */
class DecryptedExpression extends QueryExpression
{
    public function sql(ValueBinder $binder): string
    {
        return '(CONVERT(' . parent::sql($binder) . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci)';
    }
}
