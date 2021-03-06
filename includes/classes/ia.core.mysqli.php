<?php
/******************************************************************************
 *
 * Subrion - open source content management system
 * Copyright (C) 2017 Intelliants, LLC <https://intelliants.com>
 *
 * This file is part of Subrion.
 *
 * Subrion is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Subrion is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Subrion. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link https://subrion.org/
 *
 ******************************************************************************/

class iaDb extends abstractUtil implements iaInterfaceDbAdapter
{
	protected $_link;

	protected $_counter = 0;

	protected $_lastQuery = '';

	protected $_queryList = [];

	protected $_tableList = [];

	protected $_table;

	public $tableOptions = 'ENGINE = MyISAM DEFAULT CHARSET = utf8';
	public $prefix;


	public function init()
	{
		$this->prefix = INTELLI_DBPREFIX;
		$this->_connect();
	}

	/**
	 * Creates connection to database
	 *
	 * @return void
	 */
	protected function _connect()
	{
		$this->_link = mysqli_init();
		if (!$this->_link)
		{
			die('mysqli_init failed.');
		}

		if (!mysqli_options($this->_link, MYSQLI_OPT_CONNECT_TIMEOUT, 5))
		{
			die('Setting MYSQLI_OPT_CONNECT_TIMEOUT failed.');
		}

		mysqli_real_connect($this->_link, INTELLI_DBHOST, INTELLI_DBUSER, INTELLI_DBPASS, INTELLI_DBNAME, INTELLI_DBPORT);
		if (!$this->_link)
		{
			$message = !INTELLI_DEBUG ? 'Could not connect.' : 'Could not connect to the database. For more information see error logs.';
			die($message);
		}

		// set active database again
		mysqli_select_db($this->_link, INTELLI_DBNAME);

		$this->query("SET NAMES 'utf8'");
	}

	public function setTimezoneOffset($offset)
	{
		$query = 'SET time_zone = :offset';
		$this->bind($query, ['offset' => $offset]);

		$this->query($query);
	}

	/**
	 * Escapes string using valid connection
	 *
	 * @param string $string string to be escaped
	 *
	 * @return string
	 */
	public function sql($string = '')
	{
		return mysqli_real_escape_string($this->_link, $string);
	}

	/**
	 * Returns a string that represents various information on a connected database
	 *
	 * @param $type information type
	 *
	 * @return string
	 */
	public function getInfo($type)
	{
		$function = 'mysqli_get_' . $type;

		return $function($this->_link);
	}

	public function setTable($tableName, $addPrefix = true)
	{
		$this->_table = ($addPrefix ? $this->prefix : '') . $tableName;
		array_unshift($this->_tableList, $this->_table);
	}

	public function resetTable()
	{
		if (empty($this->_tableList) || 1 == count($this->_tableList))
		{
			$this->_table = '';
			$this->_tableList = [];
		}
		else
		{
			array_shift($this->_tableList);
			$this->_table = $this->_tableList[0];
		}
	}

	public function query($sql)
	{
		if (!$this->_link)
		{
			$this->_connect();
		}

		$timeStart = explode(' ', microtime());
		$result = mysqli_query($this->_link, $sql);

		$timeEnd = explode(' ', microtime());

		$start = $timeStart[1] + $timeStart[0];
		$end = $timeEnd[1] + $timeEnd[0];
		$times = number_format($end - $start, 5, '.', '');

		$this->_counter++;
		$this->_lastQuery = $sql;
		if (INTELLI_DEBUG)
		{
			$this->_queryList[] = [$sql, $times];
		}

		// 2013 - lost connection during the execution
		if (!$result && 2013 != mysqli_errno($this->_link))
		{
			$error = $this->getError();
			$error .= PHP_EOL . $sql;

			trigger_error($error, E_USER_WARNING);
		}

		return $result;
	}

	public function getLastQuery()
	{
		return $this->_lastQuery;
	}

	public function getQueriesList()
	{
		return $this->_queryList;
	}

	public function getCount()
	{
		return $this->_counter;
	}

	public function getPrefix()
	{
		return $this->prefix;
	}

	/**
	 * Converts short aliases into MySQL query format
	 *
	 * @param string $type selection type
	 * @param string|array $fields fields to be selected
	 * @param string $condition condition to be used for the selection
	 * @param int $start start position
	 * @param int|null $limit number of records to be returned
	 *
	 * @return array|bool
	 */
	protected function _get($type, $fields, $condition = '', $start = 0, $limit = null)
	{
		$stmtFields = $fields;

		if (is_array($fields))
		{
			$stmtFields = '';
			foreach ($fields as $key => $field)
			{
				$stmtFields .= is_int($key)
					? '`' . $field . '`'
					: sprintf('%s `%s`', is_numeric($field) ? $field : '`' . $field . '`', $key);
				$stmtFields .= ', ';
			}
			$stmtFields = substr($stmtFields, 0, -2);
		}

		if ($condition)
		{
			$condition = ' WHERE ' . $condition;
		}
		if ($limit && stripos($condition, 'limit') === false)
		{
			$condition .= ' LIMIT ' . $start . ', ' . $limit;
		}

		$sql = 'SELECT ' . $stmtFields . ' FROM `' . $this->_table . '` ' . $condition;

		switch ($type)
		{
			case 'all':
				return $this->getAll($sql);
			case 'keyval':
				return $this->getKeyValue($sql);
			case 'assoc':
				return $this->getAssoc($sql, true);
			default:
				return $this->getRow($sql);
		}
	}

	public function getRow($sql)
	{
		$result = false;

		$query = $this->query($sql);
		if ($this->getNumRows($query) > 0)
		{
			$result = mysqli_fetch_assoc($query);
		}

		if ($query)
		{
			mysqli_free_result($query);
		}

		return $result;
	}

	public function getAll($sql, $start = 0, $limit = 0)
	{
		if ($limit != 0)
		{
			$sql .= sprintf(' LIMIT %d, %d', $start, $limit);
		}

		$result = [];

		$query = $this->query($sql);
		if ($this->getNumRows($query) > 0)
		{
			while ($row = mysqli_fetch_assoc($query))
			{
				$result[] = $row;
			}
		}

		if ($query)
		{
			mysqli_free_result($query);
		}

		return $result;
	}

	public function getAssoc($sql, $singleRow = false)
	{
		$result = [];

		$query = $this->query($sql);
		if ($this->getNumRows($query))
		{
			while ($row = mysqli_fetch_assoc($query))
			{
				$key = array_shift($row);
				if ($singleRow)
				{
					$result[$key] = $row;
				}
				else
				{
					$result[$key][] = $row;
				}
			}
		}

		if ($query)
		{
			mysqli_free_result($query);
		}

		return $result;
	}

	public function getKeyValue($sql)
	{
		$result = [];

		$query = $this->query($sql);
		if ($this->getNumRows($query) > 0)
		{
			$array = mysqli_fetch_row($query);
			$asArray = false;
			if (count($array) > 2)
			{
				$result[$array[0]] = $array;
				$asArray = true;
			}
			else
			{
				$result[$array[0]] = $array[1];
			}

			while ($array = mysqli_fetch_row($query))
			{
				$result[$array[0]] = $asArray ? $array : $array[1];
			}
		}

		if ($query)
		{
			mysqli_free_result($query);
		}

		return $result;
	}

	public function getOne($sql)
	{
		$query = $this->query($sql);

		return ($this->getNumRows($query) > 0) ? $this->mysqli_result($query, 0, 0) : false;
	}

	public function mysqli_result($result, $row, $field = 0)
	{
		$result->data_seek($row);
		$datarow = $result->fetch_array();

		return $datarow[$field];
	}

	/**
	 * Returns the error text from the last MySQL function
	 *
	 * @return string
	 */
	public function getError()
	{
		return mysqli_error($this->_link);
	}

	public function getErrorNumber()
	{
		return mysqli_errno($this->_link);
	}

	public static function printf($pattern, array $replacements)
	{
		if ($replacements)
		{
			$keys = [];
			$values = [];
			foreach ($replacements as $key => $value)
			{
				$keys[] = ':' . $key;
				$values[] = is_scalar($value) ? $value : '';
			}
			$pattern = str_replace($keys, $values, $pattern);
		}

		return $pattern;
	}

	public function exists($where, $values = [], $tableName = null)
	{
		$this->bind($where, $values);
		if ($tableName)
		{
			$this->setTable($tableName);
			$result = $this->query("SELECT 1 FROM `" . $this->_table . "` WHERE " . $where);
			$this->resetTable();
		}
		else
		{
			$result = $this->query("SELECT 1 FROM `" . $this->_table . "` WHERE " . $where);
		}

		return ($this->getNumRows($result) > 0);
	}

	public function getInsertId()
	{
		return mysqli_insert_id($this->_link);
	}

	/**
	 * Returns the ID using auto increment value for a table
	 */
	public function getNextId($table = null)
	{
		$table = empty($table) ? $this->_table : $table;

		$result = $this->query("SHOW TABLE STATUS LIKE '{$table}'");
		$row = mysqli_fetch_array($result);

		return $row['Auto_increment'];
	}

	public function getAffected()
	{
		return mysqli_affected_rows($this->_link);
	}

	public function foundRows()
	{
		return (int)$this->getOne('SELECT ' . self::FUNCTION_FOUND_ROWS);
	}

	public function getNumRows($resource)
	{
		if (is_object($resource))
		{
			return mysqli_num_rows($resource);
		}

		return 0;
	}

	/**
	 * Retrieves the number of fields from a query
	 *
	 * @param $result query result
	 *
	 * @return int
	 */
	public function getNumFields($result)
	{
		return mysqli_num_fields($result);
	}

	/**
	 * Returns an array of objects which contains field definition information or FALSE if no field information is available
	 *
	 * @param $result query result
	 *
	 * @return array|bool
	 */
	public function getFieldNames($result)
	{
		return mysqli_fetch_fields($result);
	}

	/**
	 * Fetches one row of data from the result set and returns it as an enumerated array
	 *
	 * @param $result query result
	 *
	 * @return array|null
	 */
	public function fetchRow($result)
	{
		return mysqli_fetch_row($result);
	}

	public function describe($tableName = null, $addPrefix = true)
	{
		if (empty($tableName))
		{
			$tableName = $this->_table;
		}
		else
		{
			$tableName = ($addPrefix ? $this->prefix : '') . $tableName;
		}

		$sql = sprintf('DESCRIBE `%s`', $tableName);

		return $this->getAll($sql);
	}

	public function truncate($table = null)
	{
		if (is_null($table))
		{
			$table = $this->_table;
		}
		$sql = 'TRUNCATE TABLE `' . $table . '`';

		return $this->query($sql);
	}

	public function one($field, $condition = '', $tableName = null, $start = 0)
	{
		$result = $this->row($field, $condition, $tableName, $start);

		return is_bool($result) ? $result : array_shift($result);
	}

	public function onefield($field = self::ID_COLUMN_SELECTION, $condition = null, $start = 0, $limit = null, $tableName = null)
	{
		if (false !== strpos($field, ','))
		{
			return false;
		}

		if ($tableName)
		{
			$this->setTable($tableName);
			$rows = $this->_get('all', $field, $condition, $start, $limit);
			$this->resetTable();
		}
		else
		{
			$rows = $this->_get('all', $field, $condition, $start, $limit);
		}

		$result = [];

		if (empty($rows))
		{
			return $result;
		}

		$field = str_replace('`', '', $field);
		foreach ($rows as $row)
		{
			$result[] = $row[$field];
		}

		return $result;
	}

	public function row($fields = self::ALL_COLUMNS_SELECTION, $condition = '', $tableName = null, $start = 0)
	{
		if (is_null($tableName))
		{
			$result = $this->_get('row', $fields, $condition, $start, 1);
		}
		else
		{
			$this->setTable($tableName);
			$result = $this->_get('row', $fields, $condition, $start, 1);
			$this->resetTable();
		}

		return $result;
	}

	public function all($fields = self::ALL_COLUMNS_SELECTION, $condition = '', $start = 0, $limit = null, $tableName = null)
	{
		if (is_null($tableName))
		{
			$result = $this->_get('all', $fields, $condition, $start, $limit);
		}
		else
		{
			$this->setTable($tableName);
			$result = $this->_get('all', $fields, $condition, $start, $limit);
			$this->resetTable();
		}

		return $result;
	}

	public function assoc($fields = self::ALL_COLUMNS_SELECTION, $condition = '', $tableName = null, $start = 0, $limit = null)
	{
		if (is_null($tableName))
		{
			$result = $this->_get('assoc', $fields, $condition, $start, $limit);
		}
		else
		{
			$this->setTable($tableName);
			$result = $this->_get('assoc', $fields, $condition, $start, $limit);
			$this->resetTable();
		}

		return $result;
	}

	public function keyvalue($fields = self::ALL_COLUMNS_SELECTION, $condition = null, $tableName = null, $start = 0, $limit = null)
	{
		if (is_null($tableName))
		{
			$result = $this->_get('keyval', $fields, $condition, $start, $limit);
		}
		else
		{
			$this->setTable($tableName);
			$result = $this->_get('keyval', $fields, $condition, $start, $limit);
			$this->resetTable();
		}

		return $result;
	}

	public function insert(array $values, $rawValues = null, $tableName = null)
	{
		$table = $tableName ? $this->prefix . $tableName : $this->_table;
		$queue = is_array(current($values)) ? $values : [$values];

		foreach ($queue as $entryValues)
		{
			if ($stmtSet = $this->_wrapValues($entryValues, $rawValues))
			{
				$sql = sprintf('INSERT INTO `%s` SET %s', $table, $stmtSet);
				$this->query($sql);
			}
		}

		return $this->getInsertId();
	}

	public function update($values, $condition = null, $rawValues = null, $tableName = null)
	{
		if (empty($values) && empty($rawValues))
		{
			return false;
		}

		if (empty($this->_table) && empty($tableName))
		{
			return false;
		}

		$stmtWhere = '';
		if ($condition)
		{
			$stmtWhere = 'WHERE ' . $condition;
		}
		elseif (isset($values['id']))
		{
			$stmtWhere = 'WHERE `' . self::ID_COLUMN_SELECTION . "` = '" . $values['id'] . "'";
			unset($values['id']);
		}

		$stmtSet = $this->_wrapValues($values, $rawValues);
		if (empty($stmtSet))
		{
			return false;
		}

		$table = $tableName ? $this->prefix . $tableName : $this->_table;

		$this->query(sprintf('UPDATE `%s` SET %s %s', $table, $stmtSet, $stmtWhere));

		return $this->getAffected();
	}

	public function delete($condition, $tableName = null, $values = [])
	{
		if (empty($condition))
		{
			trigger_error(__METHOD__ . ' Parameters required "where clause"). All rows deletion is restricted.', E_USER_ERROR);
		}

		if ($values)
		{
			$this->bind($condition, $values);
		}

		$table = is_null($tableName) ? $this->_table : $this->prefix . $tableName;
		$this->query(sprintf('DELETE FROM `%s` WHERE %s', $table, $condition));

		return $this->getAffected();
	}

	public function replace(array $values, $rawValues = null, $tableName = null)
	{
		$table = $tableName ? $this->prefix . $tableName : $this->_table;
		$queue = is_array(current($values)) ? $values : [$values];

		foreach ($queue as $entryValues)
		{
			if ($stmtSet = $this->_wrapValues($entryValues, $rawValues))
			{
				$sql = sprintf('REPLACE INTO `%s` SET %s', $table, $stmtSet);
				$this->query($sql);
			}
		}

		return $this->getAffected();
	}

	public function bind(&$sql, $values)
	{
		if (is_array($values) && $values)
		{
			foreach ($values as $key => $value)
			{
				$sql = str_replace(':' . $key, "'" . iaSanitize::sql($value) . "'", $sql);
			}
		}
	}

	public function row_bind($fields, $condition, array $values, $tableName = null, $start = 0)
	{
		$this->bind($condition, $values);

		return $this->row($fields, $condition, $tableName, $start);
	}

	public function one_bind($field, $condition, array $values, $tableName = null, $start = 0)
	{
		$this->bind($condition, $values);

		return $this->one($field, $condition, $tableName, $start);
	}

	public static function convertIds($ids, $columnName = 'id', $equal = true)
	{
		if (empty($columnName))
		{
			return false;
		}

		switch (true)
		{
			case is_numeric($ids):
				return sprintf('`%s` ' . ($equal ? '=' : '!=') . ' %s', $columnName, $ids);

			case is_array($ids):
				$array = [];
				foreach ($ids as $id)
				{
					$array[] = (int)$id;
				}

				return "`{$columnName}` " . ($equal ? '' : 'NOT ') . "IN (" . implode(',', $array) . ')';

			case is_string($ids):
				return sprintf("`%s` " . ($equal ? '=' : '!=') . " '%s'", $columnName, iaSanitize::sql($ids));

			default:
				return false;
		}
	}

	public function cascadeDelete($tbl, $where = '')
	{
		if (empty($tbl) || empty($where))
		{
			return false;
		}

		if (!is_array($tbl))
		{
			$tbl = (array)$tbl;
		}

		// we don't use setTable because this is multiple changing
		$old = $this->_table;
		$totalDeleted = 0;
		foreach ($tbl as $table)
		{
			$this->_table = $this->prefix . $table;
			$totalDeleted += $this->delete($where);
		}
		$this->_table = $old;

		return $totalDeleted;
	}

	public function getEnumValues($table, $field)
	{
		$result = $this->getRow('SHOW COLUMNS FROM `' . $this->prefix . $table . '` LIKE "' . $field . '"');

		if ($result['Type'])
		{
			if (preg_match('#^(set|enum)\((.*?)\)$#i', $result['Type'], $enumArray))
			{
				$values = explode(',', $enumArray[2]);
				$enumFields = [];
				if ($values)
				{
					foreach ($values as $val)
					{
						$enumFields[] = trim($val, "'");
					}
				}

				return [
					'values' => $enumFields,
					'type' => $enumArray[1],
					'default' => $result['Default']
				];
			}
		}

		return false;
	}

	public function getMaxOrder($table = null, $condition = null)
	{
		!$condition || $condition = $this->convertIds($condition[1], $condition[0]);

		return (int)$this->one('MAX(`order`)', $condition, $table);
	}

	public function orderByRand($max, $id_name = '`id`', $pieces = 12, $delimiter = 100)
	{
		// we get max order value if $max is a table name
		if (!is_numeric($max))
		{
			$max = $this->getMaxOrder($max);
		}

		$where = '';
		$pieces = max($pieces, 6);
		$delimiter = max($delimiter, 10);
		if ($pieces * $delimiter > 5000)
		{
			$pieces = 12;
			$delimiter = 100;
		}

		if ($max > 2000)
		{
			$piece_first = ceil($max / $pieces);
			$piece_second = ceil($piece_first / $delimiter);
			$where = [];
			for($i = 0; $i < $pieces; $i++)
			{
				$start = mt_rand(0, $piece_second) * $delimiter + $piece_first * $i;
				$end = $start + $delimiter;
				$where[] = '(' . $id_name . ' >= ' . $start . ' AND ' . $id_name . ' <= ' . $end . ')';
			}
			$where = 'AND (' . implode(' OR ', $where) . ')';
		}

		return $where;
	}

	/**
	 * Internal utility function used to generate SET stmt
	 *
	 * @param array $values values to be set checking by type
	 * @param array $rawValues values to be set without processing
	 *
	 * @return string
	 */
	protected function _wrapValues($values, $rawValues)
	{
		$result = '';

		// no need for further processing
		if (empty($values) && empty($rawValues))
		{
			return $result;
		}

		$array = [];

		if (is_array($values))
		{
			foreach ($values as $columnName => $value)
			{
				$pattern = "`%s` = '%s'";

				switch (true) // an order of statements is important!
				{
					case is_bool($value):
						$pattern = '`%s` = %s';
						$value = $value ? 1 : 0;
						break;
					case is_null($value):
						$pattern = '`%s` = %s';
						$value = 'NULL';
						break;
					case is_scalar($value):
						$value = iaSanitize::sql($value);
						break;
					default: // arrays, objects & resources are now actually ignored
						continue;
				}

				$array[] = sprintf($pattern, $columnName, $value);
			}
		}

		if (is_array($rawValues) && $rawValues)
		{
			foreach ($rawValues as $field => $value)
			{
				$array[] = "`$field` = $value";
			}
		}

		$result = implode(', ', $array);

		return $result;
	}
}