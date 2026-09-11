<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Postgres;
use Cake\Database\StatementInterface;
use Cake\Database\Schema\TableSchema;
use Cake\Database\ValueBinder;
use Cake\Event\EventInterface;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use CakeAes\Model\Behavior\EncryptBehavior;
use CakeAes\Model\Database\Type\AesType;
use CakeAes\Model\Database\EncryptionProfile;
use CakeAes\Model\Database\Dialect\EncryptionDialectFactory;

final class EncryptionTest extends TestCase
{
    private function connection(string $version = '8.0.40', string $mode = 'aes-128-ecb'): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->onlyMethods(['getDriver', 'execute'])->getMock();
        $connection->method('getDriver')->willReturn(new Mysql());
        $connection->method('execute')->willReturnCallback(function ($sql) use ($version, $mode) {
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchColumn')->willReturn(str_contains($sql, 'VERSION') ? $version : $mode);
            return $statement;
        });
        return $connection;
    }

    private function postgresConnection(bool $pgcrypto = true): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDriver', 'execute'])
            ->getMock();
        $connection->method('getDriver')->willReturn(new Postgres());
        $connection->method('execute')->willReturnCallback(function () use ($pgcrypto) {
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchColumn')->willReturn($pgcrypto);

            return $statement;
        });

        return $connection;
    }

    protected function setUp(): void
    {
        Configure::write('Security.key', str_repeat('ab', 32));
        Configure::write('CakeAes', ['profile' => 'legacy']);
    }

    protected function tearDown(): void
    {
        Configure::delete('CakeAes');
        Configure::delete('Security.key');
    }

    public function testBinaryContentIsPreserved(): void
    {
        $type = new AesType();
        $driver = new Mysql();
        foreach ([null, '', '0', "C:\\temp\\file", '{"path":"a\\\\b"}', "Olá\0'\\"] as $value) {
            self::assertSame($value, $type->toPHP($value, $driver));
        }
        foreach (['', "abc\0\\def"] as $value) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $value);
            rewind($stream);
            self::assertSame($value, $type->toPHP($stream, $driver));
            fclose($stream);
        }
        self::assertSame('123', $type->toPHP(123, $driver));
    }

    public function testExpressionsBindValuesAndSupportCake5Queries(): void
    {
        $table = new Table(['alias' => 'Temps', 'table' => 'temps', 'connection' => $this->connection()]);
        $table->setSchema(new TableSchema('temps', ['id' => ['type' => 'integer'], 'name' => ['type' => 'binary'], 'phone' => ['type' => 'binary']]));
        $behavior = new EncryptBehavior($table, ['fields' => ['name', 'phone']]);
        $value = "O'Reilly\\x'); DROP TABLE temps; --";
        $binder = new ValueBinder();
        $sql = $behavior->encrypt($value)->sql($binder);
        self::assertStringNotContainsString($value, $sql);
        self::assertStringNotContainsString(Configure::read('Security.key'), $sql);
        self::assertContains($value, array_column($binder->bindings(), 'value'));
        $decryptedBinder = new ValueBinder();
        $decryptedSql = $behavior->decryptField('Temps.name')->sql($decryptedBinder);
        self::assertStringNotContainsString(Configure::read('Security.key'), $decryptedSql);
        self::assertContains(Configure::read('Security.key'), array_column($decryptedBinder->bindings(), 'value'));
        $function = new \Cake\Database\Expression\FunctionExpression('MAX', [new \Cake\Database\Expression\IdentifierExpression('Temps.name')]);
        $behavior->decryptFunctionExpressionField($function);
        self::assertStringContainsString('AES_DECRYPT', $function->sql(new ValueBinder()));
        $query = $table->find()->where(['name' => 'test'])->orderBy(['name' => 'ASC', 'phone' => 'DESC']);
        $behavior->decryptSelect($query, true);
        $behavior->decryptWhere($query);
        $behavior->decryptOrder($query);
        $order = $query->clause('order')->sql(new ValueBinder());
        self::assertSame(2, substr_count($order, 'AES_DECRYPT'));
        self::assertStringContainsString('ASC', $order);
        self::assertStringContainsString('DESC', $order);
        self::assertStringContainsString('AES_DECRYPT', $query->clause('where')->sql(new ValueBinder()));
        $this->expectException(InvalidArgumentException::class);
        $behavior->decryptField('name); DROP TABLE temps');
    }

    public function testStrictProfileRequiresMatchingMode(): void
    {
        Configure::write('CakeAes.profile', 'aes-256-ecb');
        self::assertSame(Configure::read('Security.key'), EncryptionProfile::key($this->connection('8.0.40', 'aes-256-ecb')));
        $this->expectException(RuntimeException::class);
        EncryptionProfile::key($this->connection());
    }

    public function testMariaDbRejectsUnsupportedProfile(): void
    {
        self::assertSame(Configure::read('Security.key'), EncryptionProfile::key($this->connection('11.4.0-MariaDB')));
        Configure::write('CakeAes.profile', 'aes-256-ecb');
        $this->expectException(RuntimeException::class);
        EncryptionProfile::key($this->connection('11.4.0-MariaDB'));
    }

    public function testInvalidKeyIsRejected(): void
    {
        Configure::write('Security.key', "invalid'key");
        $this->expectException(InvalidArgumentException::class);
        EncryptionProfile::key($this->connection());
    }

    public function testPostgresDialectUsesPgcryptoAndBindsValues(): void
    {
        Configure::write('CakeAes.key', 'postgres-passphrase-with-32-chars');
        $table = new Table([
            'alias' => 'Temps',
            'table' => 'temps',
            'connection' => $this->postgresConnection(),
        ]);
        $table->setSchema(new TableSchema('temps', ['name' => ['type' => 'binary']]));
        $behavior = new EncryptBehavior($table, ['fields' => ['name']]);

        $encryptBinder = new ValueBinder();
        $encryptSql = $behavior->encrypt("O'Reilly")->sql($encryptBinder);
        self::assertStringContainsString('pgp_sym_encrypt', $encryptSql);
        self::assertStringNotContainsString("O'Reilly", $encryptSql);
        self::assertContains("O'Reilly", array_column($encryptBinder->bindings(), 'value'));
        self::assertContains(
            'cipher-algo=aes256,compress-algo=0',
            array_column($encryptBinder->bindings(), 'value'),
        );

        $decryptBinder = new ValueBinder();
        $decryptSql = $behavior->decryptField('Temps.name')->sql($decryptBinder);
        self::assertStringContainsString('pgp_sym_decrypt', $decryptSql);
        self::assertStringNotContainsString('CONVERT', $decryptSql);
        self::assertContains(
            Configure::read('CakeAes.key'),
            array_column($decryptBinder->bindings(), 'value'),
        );
    }

    public function testPostgresRequiresPgcrypto(): void
    {
        Configure::write('CakeAes.key', 'postgres-passphrase');
        $dialect = EncryptionDialectFactory::create($this->postgresConnection(false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pgcrypto');
        $dialect->encrypt('value');
    }

    public function testExplicitDriverMustMatchConnection(): void
    {
        Configure::write('CakeAes.driver', 'postgres');

        $this->expectException(RuntimeException::class);
        EncryptionDialectFactory::create($this->connection());
    }

    public function testBehaviorDoesNotCreateDynamicTableProperties(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        }, E_DEPRECATED);
        try {
            $table = new Table([
                'alias' => 'Temps',
                'table' => 'temps',
                'connection' => $this->connection(),
            ]);
            $table->setSchema(new TableSchema('temps', ['name' => ['type' => 'binary']]));
            new EncryptBehavior($table, ['fields' => ['name']]);
        } finally {
            restore_error_handler();
        }

        self::assertFalse(property_exists($table, 'encryptFields'));
        self::assertFalse(property_exists($table, 'decryptedValues'));
        self::assertFalse(property_exists($table, 'containEncryptedFields'));
    }

    public function testDecryptedValuesAreScopedByEntity(): void
    {
        $table = new Table([
            'alias' => 'Temps',
            'table' => 'temps',
            'connection' => $this->connection(),
        ]);
        $table->setSchema(new TableSchema('temps', ['name' => ['type' => 'binary']]));
        $behavior = new EncryptBehavior($table, ['fields' => ['name']]);
        $first = new Entity(['name' => 'First']);
        $second = new Entity(['name' => 'Second']);
        $event = $this->createMock(EventInterface::class);
        $options = new ArrayObject();

        $behavior->beforeSave($event, $first, $options);
        $behavior->beforeSave($event, $second, $options);
        self::assertNotSame('First', $first->get('name'));
        self::assertNotSame('Second', $second->get('name'));

        $behavior->afterSave($event, $first, $options);
        self::assertSame('First', $first->get('name'));
        self::assertNotSame('Second', $second->get('name'));

        $behavior->afterSave($event, $second, $options);
        self::assertSame('Second', $second->get('name'));
    }

    public function testAssociationAndContainStateUseBehaviors(): void
    {
        $connection = $this->connection();
        $parents = new Table(['alias' => 'Parents', 'table' => 'parents', 'connection' => $connection]);
        $children = new Table(['alias' => 'Children', 'table' => 'children', 'connection' => $connection]);
        $parents->setSchema(new TableSchema('parents', ['id' => ['type' => 'integer']]));
        $children->setSchema(new TableSchema('children', [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'binary'],
        ]));
        $parents->addBehavior('CakeAes.Encrypt', ['fields' => []]);
        $children->addBehavior('CakeAes.Encrypt', ['fields' => ['name']]);
        $parents->hasMany('Children', ['targetTable' => $children]);

        $parentBehavior = $parents->getBehavior('Encrypt');
        $childBehavior = $children->getBehavior('Encrypt');
        self::assertInstanceOf(EncryptBehavior::class, $parentBehavior);
        self::assertInstanceOf(EncryptBehavior::class, $childBehavior);
        self::assertTrue($parentBehavior->isEncrypted('Children.name'));

        $childBehavior->setContainEncryptedFields(['display_name' => 'name']);
        $contained = $childBehavior->decryptSelect($children->find(), false)->clause('select');
        self::assertCount(1, $contained);
        self::assertArrayHasKey('display_name', $contained);

        $nextQuery = $childBehavior->decryptSelect($children->find(), false)->clause('select');
        self::assertCount(2, $nextQuery);
    }
}
