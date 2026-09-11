<?php
declare(strict_types=1);

namespace CakeAes\Model\Database;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use InvalidArgumentException;
use RuntimeException;

/** Validates the existing database session; never changes its encryption mode. */
class EncryptionProfile
{
    public static function key(Connection $connection): string
    {
        $key = Configure::read('CakeAes.key', Configure::read('Security.key'));
        if (!is_string($key) || strlen($key) < 32 || strlen($key) % 2 !== 0 || !ctype_xdigit($key)) {
            throw new InvalidArgumentException('Security.key must contain an even number of hexadecimal characters, at least 32.');
        }
        $profile = Configure::read('CakeAes.mysql.profile', Configure::read('CakeAes.profile', 'legacy'));
        if (!in_array($profile, ['legacy', 'aes-256-ecb'], true)) {
            throw new InvalidArgumentException('CakeAes.profile must be legacy or aes-256-ecb.');
        }
        if (!$connection->getDriver() instanceof Mysql) {
            throw new RuntimeException('CakeAes requires a MySQL or MariaDB connection.');
        }
        // Do not cache: the connection may reconnect or its session mode may change.
        $version = $connection->execute('SELECT VERSION()')->fetchColumn(0);
        if (stripos((string)$version, 'MariaDB') !== false) {
            // The legacy two-argument MariaDB AES functions use AES-128-ECB.
            // AES-256 requires a separate, version-specific storage migration.
            $expectedMode = Configure::read(
                'CakeAes.mysql.expectedMode',
                Configure::read('CakeAes.expectedMode', 'aes-128-ecb'),
            );
            if ($expectedMode !== 'aes-128-ecb') {
                throw new RuntimeException('MariaDB legacy storage requires aes-128-ecb.');
            }
            if ($profile !== 'legacy') {
                throw new RuntimeException('The AES-256 profile is not supported on MariaDB by this storage format.');
            }
        } else {
            $mode = strtolower((string)$connection->execute('SELECT @@SESSION.block_encryption_mode')->fetchColumn(0));
            $expected = Configure::read(
                'CakeAes.mysql.expectedMode',
                Configure::read('CakeAes.expectedMode'),
            );
            if ($profile === 'aes-256-ecb') {
                $expected = 'aes-256-ecb';
                if (strlen($key) !== 64) {
                    throw new InvalidArgumentException('AES-256 requires a 64-character hexadecimal key.');
                }
            }
            if (!in_array($mode, ['aes-128-ecb', 'aes-192-ecb', 'aes-256-ecb'], true) ||
                ($expected !== null && $expected !== $mode)
            ) {
                throw new RuntimeException('Database AES mode does not match the configured CakeAes storage profile.');
            }
        }
        return $key;
    }
}
