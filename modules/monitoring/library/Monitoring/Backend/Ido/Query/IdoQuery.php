<?php
// {{{ICINGA_LICENSE_HEADER}}}
/**
 * This file is part of Icinga Web 2.
 *
 * Icinga Web 2 - Head for multiple monitoring backends.
 * Copyright (C) 2013 Icinga Development Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @copyright  2013 Icinga Development Team <info@icinga.org>
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GPL, version 2
 * @author     Icinga Development Team <info@icinga.org>
 *
 */
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Module\Monitoring\Backend\Ido\Query;

use Icinga\Logger\Logger;
use Icinga\Data\Db\Query;
use Icinga\Application\Benchmark;
use Icinga\Exception\ProgrammingError;
use Icinga\Filter\Query\Tree;
use Icinga\Module\Monitoring\Filter\UrlViewFilter;
use Icinga\Application\Icinga;
use Icinga\Web\Session;

/**
 * Base class for Ido Queries
 *
 * This is the base class for all Ido queries and should be extended for new queries
 * The starting point for implementations is the columnMap attribute. This is an asscociative array in the
 * following form:
 *
 * <pre>
 * <code>
 * array(
 *      'virtualTable' => array(
 *          'fieldalias1' => 'queryColumn1',
 *          'fieldalias2' => 'queryColumn2',
 *          ....
 *      ),
 *      'virtualTable2' => array(
 *          'host'       =>  'host_name1'
 *      )
 * )
 * </code>
 * </pre>
 *
 * This allows you to select e.g. fieldalias1, which automatically calls the query code for joining 'virtualTable'. If
 * you afterwards select 'host', 'virtualTable2' will be joined. The joining logic is up to you, in order to make the
 * above example work you need to implement the joinVirtualTable() and joinVirtualTable2() method which contain your
 * custom (Zend_Db) logic for joining, filtering and querying the data you want.
 *
 */
abstract class IdoQuery extends Query
{
    /**
     * The prefix to use
     *
     * @var String
     */
    protected $prefix;

    /**
     * The alias name for the index column
     *
     * @var String
     */
    protected $idxAliasColumn;

    /**
     * The table containing the index column alias
     *
     * @var String
     */
    protected $idxAliasTable;

    /**
     * The column map containing all filterable columns
     *
     * This must be overwritten by child classes, in the format
     * array(
     *      'virtualTable' => array(
     *          'fieldalias1' => 'queryColumn1',
     *          'fieldalias2' => 'queryColumn2',
     *          ....
     *      )
     * )
     *
     * @var array
     */
    protected $columnMap = array();

    /**
     * Custom vars available for this query
     *
     * @var array
     */
    protected $customVars = array();

    /**
     * An array with all 'virtual' tables that are already joined
     *
     * Virtual tables are the keys  of the columnMap array and require a
     * join%VirtualTableName%() method to be defined in the concrete
     * query
     *
     * @var array
     */
    protected $joinedVirtualTables = array();

    /**
     * The primary field name for the object table
     *
     * @var string
     */
    protected $object_id       = 'object_id';

    /**
     * The primary field name for the IDO host table
     *
     * @var string
     */
    protected $host_id         = 'host_id';

    /**
     * The primary field name for the IDO hostgroup table
     *
     * @var string
     */
    protected $hostgroup_id    = 'hostgroup_id';

    /**
     * The primary field name for the IDO service table
     *
     * @var string
     */
    protected $service_id      = 'service_id';

    /**
     * The primary field name for the IDO serviegroup table
     *
     * @var string
     */
    protected $servicegroup_id = 'servicegroup_id';

    /**
     * The primary field name for the IDO contact table
     *
     * @var string
     */
    protected $contact_id      = 'contact_id';

    /**
     * The primary field name for the IDO contactgroup table
     *
     * @var string
     */
    protected $contactgroup_id = 'contactgroup_id';

    /**
     * An array containing Column names that cause an aggregation of the query
     *
     * @var array
     */
    protected $aggregateColumnIdx = array();

    /**
     * True to allow customvar filters and queries
     *
     * @var bool
     */
    protected $allowCustomVars = false;

    /**
     * Current IDO version. This is bullshit and needs to be moved somewhere
     * else. As someone decided that we need no Backend-specific connection
     * class unfortunately there is no better place right now. And as of the
     * 'check_source' patch we need a quick fix immediately. So here you go.
     *
     * TODO: Fix this.
     *
     * @var string
     */
    protected static $idoVersion;

    /**
     * Return true when the column is an aggregate column
     *
     * @param  String $column       The column to test
     * @return bool                 True when the column is an aggregate column
     */
    public function isAggregateColumn($column)
    {
        return array_key_exists($column, $this->aggregateColumnIdx);
    }

    /**
     * Order the result by the given column
     *
     * @param string $columnOrAlias         The column or column alias to order by
     * @param int $dir                      The sort direction or null to use default direction
     *
     * @return self                         Fluent interface
     */
    public function order($columnOrAlias, $dir = null)
    {
        $this->requireColumn($columnOrAlias);
        if ($this->isCustomvar($columnOrAlias)) {
            $columnOrAlias = $this->getCustomvarColumnName($columnOrAlias);
        } elseif ($this->hasAliasName($columnOrAlias)) {
            $columnOrAlias = $this->aliasToColumnName($columnOrAlias);
        } else {
            Logger::info('Can\'t order by column ' . $columnOrAlias);
            return $this;
        }
        return parent::order($columnOrAlias, $dir);
    }

    /**
     * Return true when the given field can be used for filtering
     *
     * @param String $field     The field to test
     * @return bool             True when the field can be used for querying, otherwise false
     */
    public function isValidFilterTarget($field)
    {
        return $this->getMappedField($field) !== null;
    }

    /**
     * Return the resolved field for an alias
     *
     * @param  String $field     The alias to resolve
     * @return String           The resolved alias or null if unknown
     */
    public function getMappedField($field)
    {
        foreach ($this->columnMap as $columnSource => $columnSet) {
            if (isset($columnSet[$field])) {
                return $columnSet[$field];
            }
        }
        return null;
    }

    /**
     * Return true if an field contains an explicit timestamp
     *
     * @param  String $field    The field to test for containing an timestamp
     * @return bool             True when the field represents an timestamp
     */
    public function isTimestamp($field)
    {
        $mapped = $this->getMappedField($field);
        if ($mapped === null) {
            return false;
        }
        return stripos($mapped, 'UNIX_TIMESTAMP') !== false;
    }

    /**
     * Apply oracle specific query initialization
     */
    private function initializeForOracle()
    {
        // Oracle uses the reserved field 'id' for primary keys, so
        // these must be used instead of the normally defined ids
        $this->object_id = $this->host_id = $this->service_id
            = $this->hostgroup_id = $this->servicegroup_id
            = $this->contact_id = $this->contactgroup_id = 'id';
        foreach ($this->columnMap as &$columns) {
            foreach ($columns as &$value) {
                $value = preg_replace('/UNIX_TIMESTAMP/', 'localts2unixts', $value);
                $value = preg_replace('/ COLLATE .+$/', '', $value);
            }
        }
    }

    /**
     * Apply postgresql specific query initialization
     */
    private function initializeForPostgres()
    {
        foreach ($this->columnMap as $table => & $columns) {
            foreach ($columns as $key => & $value) {
                $value = preg_replace('/ COLLATE .+$/', '', $value);
                $value = preg_replace('/inet_aton\(([[:word:].]+)\)/i', '$1::inet - \'0.0.0.0\'', $value);
            }
        }
    }

    /**
     * Set up this query and join the initial tables
     *
     * @see IdoQuery::initializeForPostgres     For postgresql specific setup
     */
    protected function init()
    {
        parent::init();
        $this->prefix = $this->ds->getTablePrefix();

        if ($this->ds->getDbType() === 'oracle') {
            $this->initializeForOracle();
        } elseif ($this->ds->getDbType() === 'pgsql') {
            $this->initializeForPostgres();
        }
        $this->joinBaseTables();
        $this->prepareAliasIndexes();
    }

    /**
     * Join the base tables for this query
     */
    protected function joinBaseTables()
    {
        reset($this->columnMap);
        $table = key($this->columnMap);

        $this->baseQuery = $this->db->select()->from(
            array($table => $this->prefix . $table),
            array()
        );

        $this->joinedVirtualTables = array($table => true);
    }

    /**
     * Populates the idxAliasTAble and idxAliasColumn properties
     */
    protected function prepareAliasIndexes()
    {
        foreach ($this->columnMap as $tbl => & $cols) {
            foreach ($cols as $alias => $col) {
                $this->idxAliasTable[$alias] = $tbl;
                $this->idxAliasColumn[$alias] = preg_replace('~\n\s*~', ' ', $col);
            }
        }
    }

    /**
     * Prepare query execution
     *
     * @see IdoQuery::resolveColumns()    For column alias resolving
     */
    protected function beforeQueryCreation()
    {
        $this->resolveColumns();
        $classParts = explode('\\', get_class($this));
        Benchmark::measure(sprintf('%s ready to run', array_pop($classParts)));
    }

    /**
     * Resolve columns aliases to their database field using the columnMap
     *
     * @return self         Fluent interface
     */
    public function resolveColumns()
    {
        $columns = $this->getColumns();
        $resolvedColumns = array();

        foreach ($columns as $alias => $col) {
            $this->requireColumn($col);
            if ($this->isCustomvar($col)) {
                $name = $this->getCustomvarColumnName($col);
            } else {
                $name = $this->aliasToColumnName($col);
            }
            if (is_int($alias)) {
                $alias = $col;
            }

            $resolvedColumns[$alias] = preg_replace('|\n|', ' ', $name);
        }
        $this->setColumns($resolvedColumns);

        return $this;
    }

    /**
     * Return all columns that will be selected when no columns are given in the constructor or from
     *
     * @return array        An array of column aliases
     */
    public function getDefaultColumns()
    {
        reset($this->columnMap);
        $table = key($this->columnMap);
        return array_keys($this->columnMap[$table]);
    }

    /**
     * Modify the query to the given alias can be used in the result set or queries
     *
     * This calls requireVirtualTable if needed
     *
     * @param $alias                                The alias of the column to require
     *
     * @return self                                 Fluent interface
     * @see    IdoQuery::requireVirtualTable        The method initializing required joins
     * @throws \Icinga\Exception\ProgrammingError   When an unknown column is requested
     */
    public function requireColumn($alias)
    {
        if ($this->hasAliasName($alias)) {
            $this->requireVirtualTable($this->aliasToTableName($alias));
        } elseif ($this->isCustomVar($alias)) {
            $this->requireCustomvar($alias);
        } else {
            throw new ProgrammingError(sprintf('%s : Got invalid column: %s', get_called_class(), $alias));
        }
        return $this;
    }

    /**
     * Return true if the given alias exists
     *
     * @param  String $alias    The alias to test for
     * @return bool             True when the alias exists, otherwise false
     */
    protected function hasAliasName($alias)
    {
        return array_key_exists($alias, $this->idxAliasColumn);
    }

    /**
     * Require a virtual table for the given table name if not already required
     *
     * @param  String $name         The table name to require
     * @return self                 Fluent interface
     */
    protected function requireVirtualTable($name)
    {
        if ($this->hasJoinedVirtualTable($name)) {
            return $this;
        }
        return $this->joinVirtualTable($name);
    }

    protected function conflictsWithVirtualTable($name)
    {
        if ($this->hasJoinedVirtualTable($name)) {
            throw new ProgrammingError(
                sprintf('IDO query virtual table conflict with "%s"', $name)
            );
        }
        return $this;
    }

    /**
     * Call the method for joining a virtual table
     *
     * This requires a join$Table() method to exist
     *
     * @param  String $table        The table to join by calling join$Table() in the concrete implementation
     * @return self                 Fluent interface
     *
     * @throws \Icinga\Exception\ProgrammingError   If the join method for this table does not exist
     */
    protected function joinVirtualTable($table)
    {
        $func = 'join' . ucfirst($table);
        if (method_exists($this, $func)) {
            $this->$func();
        } else {
            throw new ProgrammingError(
                sprintf(
                    'Cannot join "%s", no such table found',
                    $table
                )
            );
        }
        $this->joinedVirtualTables[$table] = true;
        return $this;
    }

    /**
     * Get the table for a specific alias
     *
     * @param   String $alias   The alias to request the table for
     * @return  String          The table for the alias or null if it doesn't exist
     */
    protected function aliasToTableName($alias)
    {
        return isset($this->idxAliasTable[$alias]) ? $this->idxAliasTable[$alias] : null;
    }

    /**
     * Return true if the given alias denotes a custom variable
     *
     * @param  String $alias    The alias to test for being a customvariable
     * @return bool             True if the alias is a customvariable, otherwise false
     */
    protected function isCustomVar($alias)
    {
        return $this->allowCustomVars && $alias[0] === '_';
    }

    protected function requireCustomvar($customvar)
    {
        if (! $this->hasCustomvar($customvar)) {
            $this->joinCustomvar($customvar);
        }
        return $this;
    }

    protected function hasCustomvar($customvar)
    {
        return array_key_exists($customvar, $this->customVars);
    }

    protected function joinCustomvar($customvar)
    {
        // TODO: This is not generic enough yet
        list($type, $name) = $this->customvarNameToTypeName($customvar);
        $alias = ($type === 'host' ? 'hcv_' : 'scv_') . strtolower($name);

        $this->customVars[$customvar] = $alias;

        // TODO: extend if we allow queries with only hosts / only services
        //       ($leftcol s.host_object_id vs h.host_object_id
        if ($this->hasJoinedVirtualTable('services')) {
            $leftcol = 's.' . $type . '_object_id';
        } else {
            $leftcol = 'h.' . $type . '_object_id';
        }
        $joinOn = $leftcol
                . ' = '
                . $alias
                . '.object_id'
                . ' AND '
                . $alias
                . '.varname = '
                . $this->db->quote(strtoupper($name));

        $this->baseQuery->joinLeft(
            array($alias => $this->prefix . 'customvariablestatus'),
            $joinOn,
            array()
        );

        return $this;
    }

    protected function customvarNameToTypeName($customvar)
    {
        // TODO: Improve this:
        if (! preg_match('~^_(host|service)_([a-zA-Z0-9_]+)$~', $customvar, $m)) {
            throw new ProgrammingError(
                sprintf(
                    'Got invalid custom var: "%s"',
                    $customvar
                )
            );
        }
        return array($m[1], $m[2]);
    }

    protected function hasJoinedVirtualTable($name)
    {
        return array_key_exists($name, $this->joinedVirtualTables);
    }

    protected function getCustomvarColumnName($customvar)
    {
        return $this->customVars[$customvar] . '.varvalue';
    }

    public function aliasToColumnName($alias)
    {
        return $this->idxAliasColumn[$alias];
    }

    protected function createSubQuery($queryName, $columns = array())
    {
        $class = '\\'
            . substr(__CLASS__, 0, strrpos(__CLASS__, '\\') + 1)
            . ucfirst($queryName) . 'Query';
        $query = new $class($this->ds, $columns);
        return $query;
    }

    // TODO: Move this away, see note related to $idoVersion var
    protected function getIdoVersion()
    {
        if (self::$idoVersion === null) {
            $session = null;
            if (Icinga::app()->isWeb()) {
                // TODO: Once we have version per connection we should choose a
                //       namespace based on resource name
                $session = Session::getSession()->getNamespace('monitoring/ido');
                if (isset($session->version)) {
                    self::$idoVersion = $session->version;
                    return self::$idoVersion;
                }
            }
            self::$idoVersion = $this->db->fetchOne(
                $this->db->select()->from($this->prefix . 'dbversion', 'version')
            );
            if ($session !== null) {
                $session->version = self::$idoVersion;
                $session->write(); // <- WHY? I don't want to care about this!
            }
        }
        return self::$idoVersion;
    }
}
