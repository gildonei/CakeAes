# CakeAes
CakePHP plugin to encrypt/decrypt table fields using database AES functions on MySQL/MariaDB databases.
This branch is for use with CakePHP 5.0+. Use branch "main-cake4" to use with CakePHP 4.0+

## Install
Install it as require dependency:
```
composer require joacir/cake-aes
```

## Setup
Enable the plugin in your Application.php or call
```
bin/cake plugin load CakeAes
```

Configure a new security hash in *app_local.php*:
```
'Security' => [
    'key' => '<your application-specific hexadecimal key>',
],
```
- This will be the key used by the plugin for encryption.
- NEVER use the same key for different apps.
- Once generated, NEVER change the key.
- The key must contain at least 32 digits in hexadecimal.

Change the type field of the fields you want to encrypt on your tables to a binary type:
```
char(20) -> blob
vachar(200) -> varbinary(200)
text -> blob
```
- Only *string* types works. Use only *VarBinary* or *Blob* types.

Load the behavior on your table *initialize()* method:
```
$this->addBehavior('CakeAes.Encrypt', [
    'fields' => ['name', 'card', 'phone']
]);
```

## Usage

### To encrypt fields

Nothing is necessary, the EncryptBehavior do the encryption automatically when you set the *fields* in settings.

### To decrypt fields, conditions, order and contain

The *find()* ou *get()* works without changes, in complex queries you can use *decryptField()*.
```
$new = $this->Temps->get($temp->id, ['fields' => [
    'id',
    'name' => $this->Temps->decryptField('Temps.name')
]]);

$temp = $this->Temps->find()
    ->select(['name' => $this->Temps->decryptField('Temps.name')])
    ->where(['id' => 2])
    ->first();
```

You can use *decryptEq()* in *conditions*:
```
$temp = $this->Temps->find()
    ->select(['name' => $this->Temps->decryptField('Temps.name')])
    ->where([$this->Temps->decryptEq('Temps.name', $name)])
    ->first();

$temp = $this->Temps->find()
    ->select([
        'id',
        'name' => $this->Temps->decryptField('Temps.name')
    ])
    ->where([$this->Temps->decryptLike('Temps.name', '%Sa%')])
    ->first();
```

In *updateAll()* you can use *encrypt()* to encrypt:
```
$name = $this->Temps->encrypt("José");
$fields = ['name' => $name];
$conditions = [
    $this->Temps->decryptEq('Temps.name', 'Maria')
];
$this->Temps->updateAll($fields, $conditions);
```

### To encrypt/decrypt a file

```
$imageFile = dirname(__FILE__) . DS . 'imagem.jpg';
$Temps->encryptFile($imageFile);

$imageFile = dirname(__FILE__) . DS . 'imagem_crypted.jpg';
$Temps->decryptFile($imageFile);
```

### To decrypt a file in a controller

Load de Encrypt Component:
```
public function initialize(): void
{
    $this->loadComponent('CakeAes.Encrypt');
}
```

To decrypt and render the content:
```
$imageFile = dirname(__FILE__) . DS . 'imagem_encrypted.jpg';

return $this->Encrypt->decryptRender($imageFile);
```

To decrypt and download a file:
```
$imageFile = dirname(__FILE__) . DS . 'imagem_encrypted.jpg';

return $this->Encrypt->decryptDownload($imageFile);
```

## Encryption profiles and upgrade notes

This release requires CakePHP 5 and PHP 8.1 or newer. Existing ciphertext is
not migrated, and the plugin never changes the database encryption mode.

Configure `CakeAes` alongside `Security` in `app_local.php`:

```php
'CakeAes' => [
    'profile' => 'legacy',
    // Set this to the verified mode used to write your existing data:
    'expectedMode' => 'aes-128-ecb',
],
```

`legacy` preserves the two-argument AES storage format. On MySQL it accepts
AES-128/192/256-ECB, checking `expectedMode` when supplied. Without that setting,
it cannot detect a change between supported ECB modes. On MariaDB the legacy
format uses AES-128-ECB; other expected modes are rejected. Unsupported drivers
and modes requiring an IV are rejected. The live session is checked whenever
an encryption/decryption expression is built, without caching across reconnects.
Do not change session modes between building and executing a query.

To enforce AES-256 for the existing ECB format on MySQL, set `profile` to
`aes-256-ecb`, use exactly 64 hexadecimal characters for `Security.key`, and
configure the database connection to use `aes-256-ecb`. A mismatch raises an
exception. MariaDB is explicitly rejected for this profile. ECB is deterministic
and provides no authentication; this compatibility profile is not an authenticated
encryption format. An authenticated format with IV/nonces needs a separate storage
migration and is not implemented here.

All profiles require an even-length hexadecimal key with at least 32 characters.
Never replace an existing key just to satisfy validation: recover the actual key
and investigate the original encoding first. File encryption still uses CakePHP's
Security utility and is independent of the database profile.

Before changing the mode/key for existing data: back up and verify recovery;
identify the original server version, key and mode; decrypt using that original
configuration; write to separate columns using the target configuration; compare
all recovered values before switching reads. Account for ciphertext byte length
when sizing binary columns. No automatic migration is performed by this release.

### Query API migration

`decryptString()` now throws a clear migration exception because raw SQL strings
cannot preserve bound values. Replace calls with `decryptField()` and pass the
result as an expression. For manual ordering, use:

```php
use Cake\Database\Expression\OrderClauseExpression;
$query->orderBy([
    new OrderClauseExpression($table->decryptField('Temps.name'), 'ASC'),
]);
```

Only configured encrypted fields and their recognized association aliases are
accepted. Arbitrary SQL snippets are rejected. Values and keys are bound parameters;
configure application/database logging appropriately because bind values can still
be logged. The database server necessarily receives the key to perform decryption.

### Regression tests without a database

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Regression
```

These tests exercise the real CakePHP expression classes with a simulated database
connection. The existing integration suite still needs a dedicated MySQL/MariaDB
test database and its fixture setup; SQLite cannot execute this plugin's AES SQL.
