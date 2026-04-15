<?php
declare(strict_types=1);

namespace CakeAes\Model\Behavior;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Database\Expression\FunctionExpression;
use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Query;
use Cake\Utility\Security;
use Cake\Database\TypeFactory;
use Cake\ORM\Locator\LocatorAwareTrait;
/**
 * Encrypt Behavior
 */
class EncryptBehavior extends Behavior
{
    use LocatorAwareTrait;

    public function initialize(array $config): void
    {
        $this->_table->encryptFields = [];
        $this->_table->decryptedValues = [];
        $this->_table->containEncryptedFields = null;
        if (isset($config['fields']) && is_array($config['fields'])) {
            $this->_table->encryptFields = $config['fields'];
            TypeFactory::map('aes', 'CakeAes\Model\Database\Type\AesType');
            $schema = $this->_table->getSchema();
            foreach ($config['fields'] as $field) {
                if ($schema->hasColumn($field)) {
                    $schema->setColumnType($field, 'aes');
                }
            }
        }
    }

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        foreach ($this->_table->encryptFields as $field) {
            $value = $entity->get($field);
            if (is_string($value)) {
                $this->_table->decryptedValues[$field] = $value;
                $entity->set($field, $this->encrypt($value));
            }
        }
    }

    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        foreach ($this->_table->decryptedValues as $field => $value) {
            $entity->set($field, $value);
        }
        $this->_table->decryptedValues = [];
    }

    /**
     * Encrypt a text
     *
     * @param string $value text value
     * @return QueryExpression Encrypt Expression
     */
    public function encrypt(string $value): QueryExpression
    {
        /** @var string $key */
        $key = Configure::read('Security.key');
        $query = $this->_table->find();
        $value = addslashes($value);
        $expressionValue = $query->newExpr()
            ->add("AES_ENCRYPT('{$value}',UNHEX('{$key}'))");

        return $expressionValue;
	}

    public function beforeFind(EventInterface $event, Query $query, ArrayObject $options, bool $primary): void
    {
        $associations = $query->getContain();
        $this->setContainFields($query, $associations);
        $query = $this->decryptSelect($query, $primary);
        $query = $this->decryptWhere($query);
        $query = $this->decryptOrder($query);
    }

    protected function setContainFields(Query $query, array $associations): void
    {
        foreach ($associations as $name => $config) {
            foreach ($config as $key => $options) {
                if ($key === 'fields') {
                    /** @var \Cake\ORM\Locator\TableLocator $locator */
                    $locator = $this->getTableLocator();
                    $table = $locator->allowFallbackClass(true)
                        ->get($name);
                    if ($table->hasBehavior('Encrypt')) {
                        $table->containEncryptedFields = $options;
                    }
                } else {
                    if (is_array($options)) {
                        $this->setContainFields($query, [$key => $options]);
                    }
                }
            }
        }
    }

    /**
     * Decrypt select encrypted fields
     *
     * @param Query $query Query
     * @param bool $primary Is a primary table or associated table
     * @return Query Modified Query with decrypt expressions in found fields
     */
    public function decryptSelect(Query $query, $primary): Query
    {
        $select = $query->clause('select');
        if (empty($select)) {
            if ($primary || $this->_table->containEncryptedFields === null) {
                $select = $this->_table
                    ->getSchema()
                    ->columns();
            } else {
                $select = $this->_table->containEncryptedFields;
                $this->_table->containEncryptedFields = [];
            }
        }
        $fields = [];
        foreach ($select as $virtual => $field) {
            if ($field instanceof FunctionExpression) {
                $this->decryptFunctionExpressionField($field);
            }

            if ($this->isEncrypted($field)) {
                $table = $this->_table->getAlias();
                if (strpos($field, '.') !== false) {
                    list($table, $field) = explode('.', $field);
                }
                $virtual = \is_numeric($virtual) ? $table . '__' . $field : $virtual;
                $fields[$virtual] = $this->decryptField($table . '.' . $field);
            } else {
                $fields[$virtual] = $field;
            }
        }
        if (!empty($fields)) {
            $query = $query->select($fields, true);
        }

        return $query;
    }

    /**
     * Decrypt where encrypted fields
     *
     * @param Query $query Query
     * @return Query Modified Query with decrypt expressions in found fields
     */
    public function decryptWhere(Query $query): Query
    {
        $expr = $query->clause('where');
        if ($expr instanceof \Cake\Database\Expression\QueryExpression) {
            $expr->traverse(function ($condition) {
                if ($condition instanceof \Cake\Database\Expression\ComparisonExpression) {
                    $field = $condition->getField();
                    if (is_string($field) && $this->isEncrypted($field)) {
                        $condition->setField($this->decryptField($field));
                    }
                }

                return $condition;
            });
        }

        return $query;
    }

    /**
     * Decrypt order by encrypted fields
     *
     * @param Query $query Query
     * @return Query Modified Query with decrypt expressions in found fields
     */
    public function decryptOrder(Query $query): Query
    {
        $expr = $query->clause('order');
        if ($expr instanceof \Cake\Database\Expression\OrderByExpression) {
            $expr->iterateParts(function ($direction, &$field) {
                if ($this->isEncrypted($field)) {
                    $field = $this->decryptString($field);
                }

                return $direction;
            });
        }

        return $query;
    }

    /**
     * Check wheter field is encrypted
     *
     * @param string $field Field name
     * @return bool Is or not encrypted
     */
    public function isEncrypted($field): bool
    {
        $isEncrypted = false;
        if (!empty($field) && is_string($field)) {
            $table = null;
            if (strpos($field, '.') !== false) {
                list($table, $field) = explode('.', $field);
            }
            if (empty($table) || $table == $this->_table->getAlias()) {
                $isEncrypted = in_array($field, $this->_table->encryptFields);
            } else {
                if (!empty($this->_table->{$table}) && $this->_table->{$table}->hasBehavior('Encrypt')) {
                    $isEncrypted = $this->_table->{$table}->isEncrypted($field);
                }
            }
        }

        return $isEncrypted;
    }

    /**
     * Get comparison expression with a encrypted field
     *
     * @param string $fieldName Field name
     * @param string $value Text value
     * @return QueryExpression Comparison expression
     */
    public function decryptEq(string $fieldName, string $value): QueryExpression
    {
        $query = $this->_table->find();
        $expressioEquals = $query->newExpr()
            ->eq($this->decryptField($fieldName), $value);

        return $expressioEquals;
    }

    public function decryptNotEq(string $fieldName, string $value): QueryExpression
    {
        $query = $this->_table->find();
        $expressioEquals = $query->newExpr()
            ->notEq($this->decryptField($fieldName), $value);

        return $expressioEquals;
    }

    public function decryptLike(string $fieldName, string $value): QueryExpression
    {
        $query = $this->_table->find();
        $expressioEquals = $query->newExpr()
            ->like($this->decryptField($fieldName), $value);

        return $expressioEquals;
    }

    public function decryptNotLike(string $fieldName, string $value): QueryExpression
    {
        $query = $this->_table->find();
        $expressioEquals = $query->newExpr()
            ->notLike($this->decryptField($fieldName), $value);

        return $expressioEquals;
    }

    public function decryptIn(string $fieldName, string $value): QueryExpression
    {
        $query = $this->_table->find();
        $expressioEquals = $query->newExpr()
            ->in($this->decryptField($fieldName), $value);

        return $expressioEquals;
    }

    public function decryptNotIn(string $fieldName, string $value): QueryExpression
    {
        $query = $this->_table->find();
        $expressioEquals = $query->newExpr()
            ->notIn($this->decryptField($fieldName), $value);

        return $expressioEquals;
    }

    /**
     * Decrypt a field
     *
     * @param string $fieldName Field name
     * @return QueryExpression Decrypt expression
     */
    public function decryptField($fieldName): QueryExpression
    {
        $expressionField = $this->_table->find()
            ->newExpr()
            ->add($this->decryptString($fieldName));

        return $expressionField;
    }

    /**
     * Decrypt field string
     *
     * @param string $fieldName Field name
     * @return string Decrypt field string
     */
    public function decryptString(string $fieldName): string
    {
        /** @var string $key */
        $key = Configure::read('Security.key');
        $expression = "AES_DECRYPT({$fieldName}, UNHEX('{$key}'))";

        return $expression;
    }

    /**
     * Encrypt a file
     *
     * @param string $pathFileName Path and name file
     * @return false|int bytes count saved
     */
    public function encryptFile(string $pathFileName)
    {
		$crypted = false;
        if (file_exists($pathFileName)) {
            $content = @file_get_contents($pathFileName);
            if (!empty($content)) {
                /** @var string $key */
                $key = Configure::read('Security.key');
                $content = Security::encrypt($content, $key);
                if (!empty($content)) {
                    $crypted = @file_put_contents($pathFileName, $content);
                }
            }
        }

		return $crypted;
	}

    /**
     * Decrypt a file
     *
     * @param string $pathFileName Path and name file
     * @return false|int bytes count saved
     */
    public function decryptFile(string $pathFileName)
    {
		$decrypted = false;
        if (file_exists($pathFileName)) {
            $content = @file_get_contents($pathFileName);
            if (!empty($content)) {
                /** @var string $key */
                $key = Configure::read('Security.key');
                $content = Security::decrypt($content, $key);
                if (!empty($content)) {
                    $decrypted = @file_put_contents($pathFileName, $content);
                }
            }
        }

		return $decrypted;
	}

    /**
     * Decrypts a function expression field
     *
     * @param FunctionExpression $field
     * @return void
     */
    public function decryptFunctionExpressionField(FunctionExpression $field)
    {
        $field->iterateParts(function ($part) {
            if ($part instanceof IdentifierExpression && $this->isEncrypted($part->getIdentifier())) {
                $part->setIdentifier($this->decryptString($part->getIdentifier()));
            } else if (is_string($part) && $this->isEncrypted($part)) {
                $part = $this->decryptString($part);
            }

            return $part;
        });
    }
}
