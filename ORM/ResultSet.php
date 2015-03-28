<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\ORM;

use Cake\Collection\Collection;
use Cake\Collection\CollectionTrait;
use Cake\Database\Exception;
use Cake\Database\Type;
use Cake\Datasource\ResultSetInterface;
use JsonSerializable;
use SplFixedArray;

/**
 * Represents the results obtained after executing a query for a specific table
 * This object is responsible for correctly nesting result keys reported from
 * the query, casting each field to the correct type and executing the extra
 * queries required for eager loading external associations.
 *
 */
class ResultSet implements ResultSetInterface
{

    use CollectionTrait;

    /**
     * Original query from where results were generated
     *
     * @var Query
     */
    protected $_query;

    /**
     * Database statement holding the results
     *
     * @var \Cake\Database\StatementInterface
     */
    protected $_statement;

    /**
     * Points to the next record number that should be fetched
     *
     * @var int
     */
    protected $_index = 0;

    /**
     * Last record fetched from the statement
     *
     * @var array
     */
    protected $_current;

    /**
     * Default table instance
     *
     * @var \Cake\ORM\Table
     */
    protected $_defaultTable;

    /**
     * The default table alias
     *
     * @var string
     */
    protected $_defaultAlias;

    /**
     * List of associations that should be placed under the `_matchingData`
     * result key.
     *
     * @var array
     */
    protected $_matchingMap = [];

    /**
     * List of associations that should be eager loaded.
     *
     * @var array
     */
    protected $_containMap = [];

    /**
     * Map of fields that are fetched from the statement with
     * their type and the table they belong to
     *
     * @var array
     */
    protected $_map = [];

    /**
     * List of matching associations and the column keys to expect
     * from each of them.
     *
     * @var array
     */
    protected $_matchingMapColumns = [];

    /**
     * Results that have been fetched or hydrated into the results.
     *
     * @var array
     */
    protected $_results = [];

    /**
     * Whether to hydrate results into objects or not
     *
     * @var bool
     */
    protected $_hydrate = true;

    /**
     * The fully namespaced name of the class to use for hydrating results
     *
     * @var string
     */
    protected $_entityClass;

    /**
     * Whether or not to buffer results fetched from the statement
     *
     * @var bool
     */
    protected $_useBuffering = true;

    /**
     * Holds the count of records in this result set
     *
     * @var int
     */
    protected $_count;

    /**
     * Type cache for type converters.
     *
     * Converters are indexed by alias and column name.
     *
     * @var array
     */
    protected $_types = [];

    /**
     * Constructor
     *
     * @param \Cake\ORM\Query $query Query from where results come
     * @param \Cake\Database\StatementInterface $statement The statement to fetch from
     */
    public function __construct($query, $statement)
    {
        $repository = $query->repository();
        $this->_query = $query;
        $this->_statement = $statement;
        $this->_defaultTable = $this->_query->repository();
        $this->_calculateAssociationMap();
        $this->_hydrate = $this->_query->hydrate();
        $this->_entityClass = $repository->entityClass();
        $this->_useBuffering = $query->bufferResults();
        $this->_defaultAlias = $this->_defaultTable->alias();
        $this->_calculateColumnMap();

        if ($this->_useBuffering) {
            $count = $this->count();
            $this->_results = new SplFixedArray($count);
        }
    }

    /**
     * Returns the current record in the result iterator
     *
     * Part of Iterator interface.
     *
     * @return array|object
     */
    public function current()
    {
        return $this->_current;
    }

    /**
     * Returns the key of the current record in the iterator
     *
     * Part of Iterator interface.
     *
     * @return int
     */
    public function key()
    {
        return $this->_index;
    }

    /**
     * Advances the iterator pointer to the next record
     *
     * Part of Iterator interface.
     *
     * @return void
     */
    public function next()
    {
        $this->_index++;
    }

    /**
     * Rewinds a ResultSet.
     *
     * Part of Iterator interface.
     *
     * @throws \Cake\Database\Exception
     * @return void
     */
    public function rewind()
    {
        if ($this->_index == 0) {
            return;
        }

        if (!$this->_useBuffering) {
            $msg = 'You cannot rewind an un-buffered ResultSet. Use Query::bufferResults() to get a buffered ResultSet.';
            throw new Exception($msg);
        }

        $this->_index = 0;
    }

    /**
     * Whether there are more results to be fetched from the iterator
     *
     * Part of Iterator interface.
     *
     * @return bool
     */
    public function valid()
    {
        $valid = true;
        if ($this->_useBuffering) {
            $valid = $this->_index < $this->_count;
            if ($valid && $this->_results[$this->_index] !== null) {
                $this->_current = $this->_results[$this->_index];
                return true;
            }
            if (!$valid) {
                return $valid;
            }
        }

        $this->_current = $this->_fetchResult();
        $valid = $this->_current !== false;

        if ($valid && $this->_useBuffering) {
            $this->_results[$this->_index] = $this->_current;
        }
        if (!$valid && $this->_statement !== null) {
            $this->_statement->closeCursor();
        }

        return $valid;
    }

    /**
     * Get the first record from a result set.
     *
     * This method will also close the underlying statement cursor.
     *
     * @return array|object
     */
    public function first()
    {
        foreach ($this as $result) {
            if ($this->_statement && !$this->_useBuffering) {
                $this->_statement->closeCursor();
            }
            return $result;
        }
    }

    /**
     * Serializes a resultset.
     *
     * Part of Serializable interface.
     *
     * @return string Serialized object
     */
    public function serialize()
    {
        while ($this->valid()) {
            $this->next();
        }
        return serialize($this->_results);
    }

    /**
     * Unserializes a resultset.
     *
     * Part of Serializable interface.
     *
     * @param string $serialized Serialized object
     * @return void
     */
    public function unserialize($serialized)
    {
        $this->_results = unserialize($serialized);
        $this->_useBuffering = true;
        $this->_count = count($this->_results);
    }

    /**
     * Gives the number of rows in the result set.
     *
     * Part of the Countable interface.
     *
     * @return int
     */
    public function count()
    {
        if ($this->_count !== null) {
            return $this->_count;
        }
        if ($this->_statement) {
            return $this->_count = $this->_statement->rowCount();
        }
        return $this->_count = count($this->_results);
    }

    /**
     * Calculates the list of associations that should get eager loaded
     * when fetching each record
     *
     * @return void
     */
    protected function _calculateAssociationMap()
    {
        $map = $this->_query->eagerLoader()->associationsMap($this->_defaultTable);
        $this->_matchingMap = (new Collection($map))
            ->match(['matching' => true])
            ->indexBy('alias')
            ->toArray();

        $this->_containMap = (new Collection(array_reverse($map)))
            ->match(['matching' => false])
            ->indexBy('nestKey')
            ->toArray();
    }

    /**
     * Creates a map of row keys out of the query select clasuse that can be
     * used to quickly hydrate correctly nested result sets.
     *
     * @return void
     */
    protected function _calculateColumnMap()
    {
        $map = [];
        foreach ($this->_query->clause('select') as $key => $field) {
            $key = trim($key, '"`[]');
            if (strpos($key, '__') > 0) {
                $parts = explode('__', $key, 2);
                $map[$parts[0]][$key] = $parts[1];
            } else {
                $map[$this->_defaultAlias][$key] = $key;
            }
        }

        foreach ($this->_matchingMap as $alias => $assoc) {
            $this->_matchingMapColumns[$alias] = $map[$alias];
            unset($map[$alias]);
        }

        $this->_map = $map;
    }

    /**
     * Helper function to fetch the next result from the statement or
     * seeded results.
     *
     * @return mixed
     */
    protected function _fetchResult()
    {
        if (!$this->_statement) {
            return false;
        }

        $row = $this->_statement->fetch('assoc');
        if ($row === false) {
            return $row;
        }
        return $this->_groupResult($row);
    }

    /**
     * Correctly nests results keys including those coming from associations
     *
     * @param mixed $row Array containing columns and values or false if there is no results
     * @return array Results
     */
    protected function _groupResult($row)
    {
        $defaultAlias = $this->_defaultAlias;
        $results = $presentAliases = [];
        $options = [
            'useSetters' => false,
            'markClean' => true,
            'markNew' => false,
            'guard' => false
        ];

        foreach ($this->_matchingMapColumns as $alias => $keys) {
            $matching = $this->_matchingMap[$alias];
            $results['_matchingData'][$alias] = $this->_castValues(
                $matching['instance']->target(),
                array_combine(
                    $keys,
                    array_intersect_key($row, $keys)
                )
            );
            if ($this->_hydrate) {
                $options['source'] = $alias;
                $entity = new $matching['entityClass']($results['_matchingData'][$alias], $options);
                $entity->clean();
                $results['_matchingData'][$alias] = $entity;
            }
        }

        foreach ($this->_map as $table => $keys) {
            $results[$table] = array_combine($keys, array_intersect_key($row, $keys));
            $presentAliases[$table] = true;
        }

        if (isset($presentAliases[$defaultAlias])) {
            $results[$defaultAlias] = $this->_castValues(
                $this->_defaultTable,
                $results[$defaultAlias]
            );
        }
        unset($presentAliases[$defaultAlias]);

        foreach ($this->_containMap as $assoc) {
            $alias = $assoc['nestKey'];
            $instance = $assoc['instance'];

            if (!$assoc['canBeJoined'] && !isset($row[$alias])) {
                $results = $instance->defaultRowValue($results, $assoc['canBeJoined']);
                continue;
            }

            if (!$assoc['canBeJoined']) {
                $results[$alias] = $row[$alias];
            }

            $target = $instance->target();
            $options['source'] = $target->alias();
            unset($presentAliases[$alias]);

            if ($assoc['canBeJoined']) {
                $results[$alias] = $this->_castValues($target, $results[$alias]);

                $hasData = false;
                foreach ($results[$alias] as $v) {
                    if ($v !== null && $v !== []) {
                        $hasData = true;
                        break;
                    }
                }

                if (!$hasData) {
                    $results[$alias] = null;
                }
            }

            if ($this->_hydrate && $results[$alias] !== null && $assoc['canBeJoined']) {
                $entity = new $assoc['entityClass']($results[$alias], $options);
                $entity->clean();
                $results[$alias] = $entity;
            }

            $results = $instance->transformRow($results, $alias, $assoc['canBeJoined']);
        }

        foreach ($presentAliases as $alias => $present) {
            if (!isset($results[$alias])) {
                continue;
            }
            $results[$defaultAlias][$alias] = $results[$alias];
        }

        if (isset($results['_matchingData'])) {
            $results[$defaultAlias]['_matchingData'] = $results['_matchingData'];
        }

        $options['source'] = $this->_defaultTable->registryAlias();
        $results = $results[$defaultAlias];
        if ($this->_hydrate && !($results instanceof Entity)) {
            $results = new $this->_entityClass($results, $options);
        }

        return $results;
    }

    /**
     * Casts all values from a row brought from a table to the correct
     * PHP type.
     *
     * @param Table $table The table object
     * @param array $values The values to cast
     * @return array
     */
    protected function _castValues($table, $values)
    {
        $alias = $table->alias();
        $driver = $this->_query->connection()->driver();
        if (empty($this->_types[$alias])) {
            $schema = $table->schema();
            foreach ($schema->columns() as $col) {
                $this->_types[$alias][$col] = Type::build($schema->columnType($col));
            }
        }

        foreach ($values as $field => $value) {
            if (!isset($this->_types[$alias][$field])) {
                continue;
            }
            $values[$field] = $this->_types[$alias][$field]->toPHP($value, $driver);
        }

        return $values;
    }

    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array
     */
    public function __debugInfo()
    {
        return [
            'query' => $this->_query,
            'items' => $this->toArray(),
        ];
    }
}
