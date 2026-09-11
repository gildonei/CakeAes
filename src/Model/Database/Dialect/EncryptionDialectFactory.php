<?php
declare(strict_types=1);

namespace CakeAes\Model\Database\Dialect;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Postgres;
use RuntimeException;

class EncryptionDialectFactory
{
    public static function create(Connection $connection): DatabaseEncryptionDialect
    {
        $configured = Configure::read('CakeAes.driver', 'auto');
        $driver = $connection->getDriver();

        if ($configured === 'mysql' || ($configured === 'auto' && $driver instanceof Mysql)) {
            if (!$driver instanceof Mysql) {
                throw new RuntimeException('CakeAes.driver mysql does not match the database connection.');
            }

            return new MysqlEncryptionDialect($connection);
        }
        if ($configured === 'postgres' || ($configured === 'auto' && $driver instanceof Postgres)) {
            if (!$driver instanceof Postgres) {
                throw new RuntimeException('CakeAes.driver postgres does not match the database connection.');
            }

            return new PostgresPgcryptoDialect($connection);
        }

        throw new RuntimeException(
            sprintf('CakeAes does not support database driver %s.', $driver::class),
        );
    }
}
