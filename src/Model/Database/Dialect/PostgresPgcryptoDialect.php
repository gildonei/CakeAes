<?php
declare(strict_types=1);

namespace CakeAes\Model\Database\Dialect;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Expression\FunctionExpression;
use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Expression\QueryExpression;
use InvalidArgumentException;
use RuntimeException;

class PostgresPgcryptoDialect implements DatabaseEncryptionDialect
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function encrypt(string $value): QueryExpression
    {
        $this->assertPgcryptoAvailable();

        return new QueryExpression([
            new FunctionExpression(
                'pgp_sym_encrypt',
                [$value, $this->key(), $this->options()],
                ['string', 'string', 'string'],
            ),
        ]);
    }

    public function decrypt(string $fieldName): QueryExpression
    {
        $this->assertPgcryptoAvailable();

        return new QueryExpression([
            new FunctionExpression(
                'pgp_sym_decrypt',
                [new IdentifierExpression($fieldName), $this->key()],
                [null, 'string'],
            ),
        ]);
    }

    public function name(): string
    {
        return 'postgres-pgcrypto';
    }

    private function assertPgcryptoAvailable(): void
    {
        $available = $this->connection
            ->execute("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pgcrypto')")
            ->fetchColumn(0);
        if (!in_array($available, [true, 1, '1', 't', 'true'], true)) {
            throw new RuntimeException(
                'PostgreSQL extension pgcrypto is required. Enable it with CREATE EXTENSION pgcrypto.',
            );
        }
    }

    private function key(): string
    {
        $key = Configure::read('CakeAes.key', Configure::read('Security.key'));
        if (!is_string($key) || strlen($key) < 16) {
            throw new InvalidArgumentException('CakeAes.key must be a string with at least 16 characters.');
        }

        return $key;
    }

    private function options(): string
    {
        $cipher = Configure::read('CakeAes.postgres.cipher', 'aes256');
        $compression = Configure::read('CakeAes.postgres.compress-algo', 0);
        if (!in_array($cipher, ['aes128', 'aes192', 'aes256'], true)) {
            throw new InvalidArgumentException('Unsupported CakeAes PostgreSQL cipher.');
        }
        if (!in_array($compression, [0, 1, 2], true)) {
            throw new InvalidArgumentException('CakeAes PostgreSQL compress-algo must be 0, 1 or 2.');
        }

        return sprintf('cipher-algo=%s,compress-algo=%d', $cipher, $compression);
    }
}
