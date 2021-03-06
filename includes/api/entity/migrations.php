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

class iaApiEntityMigrations extends iaApiEntityAbstract
{
	const KEYWORD_SELF = 'self';

	protected $_name = 'migrations';

	protected $_table = 'migrations';

	public function apiGet($id)
	{
		throw new Exception('Method not allowed', iaApiResponse::NOT_ALLOWED);
	}

	public function apiList($start, $limit, $where, $order)
	{
		throw new Exception('Method not allowed', iaApiResponse::NOT_ALLOWED);
	}

	public function apiDelete($id)
	{
		throw new Exception('Method not allowed', iaApiResponse::NOT_ALLOWED);
	}

	public function apiInsert(array $data)
	{
		if (isset($data['action']))
		{
			if ($data['action'] == 'migrate')
			{
				return ['result' => $this->applyNewMigrations()];
			}
		}
		throw new Exception("Invalid or missing action", iaApiResponse::BAD_REQUEST);
	}

	private function applyNewMigrations()
	{
		$appliedMigrations = [];
		$newMigrations = [];

		$migrations = $this->_iaDb->all(iaDb::ALL_COLUMNS_SELECTION, '', 0, null, $this->getTable());
		foreach($migrations as $migration)
		{
			$appliedMigrations[] = $migration['name'];
		}

		$migration_dir = IA_HOME . 'updates' . IA_DS . 'migrations' . IA_DS;
		if (is_dir($migration_dir))
		{
			$files = scandir($migration_dir);
			foreach($files as $file)
			{
				if (substr($file, 0, 1) != '.' && is_file($migration_dir . $file))
				{
					if (!in_array($file, $appliedMigrations))
					{
						$newMigrations[] = $file;
					}
				}
			}
		}

		$migrationResults = [];

		$masterLangCode = $this->_iaDb->one('code', iaDb::convertIds(1, 'master'), iaLanguage::getLanguagesTable());
		foreach($newMigrations as $name)
		{
			$errors = [];
			$fd = @fopen($migration_dir . IA_DS . $name, 'r');

			if (!$fd)
			{
				continue;
			}

			$sql = '';
			while ($s = fgets($fd, 10240))
			{
				$s = trim($s);

				if (!$s || in_array($s[0], ['#', '-', '']))
				{
					continue;
				}

				if (';' == $s[strlen($s) - 1])
				{
					$sql.= $s;
				}
				else
				{
					$sql.= $s;
					continue;
				}

				$result = $this->_iaDb->query(str_replace(['{prefix}', '{lang}'],
					[$this->_iaDb->prefix, $masterLangCode], $sql));

				$result || $errors[] = $this->_iaDb->getError();

				$sql = '';
			}
			fclose($fd);

			$migrationProcessed = [
				'name' => $name,
				'status' => $errors ? 'incomplete' : 'complete',
				'data' => $errors ? $errors : null,
				'date' => date(iaDb::DATETIME_FORMAT),
			];
			$migrationResults[] = $migrationProcessed;

			if ($migrationProcessed['data'])
			{
				$migrationProcessed['data'] = json_encode($migrationProcessed['data']);
			}
			$this->_iaDb->insert($migrationProcessed, null, 'migrations');
		}
		$this->_iaCore->iaCache->clearAll();

		return $migrationResults;
	}
}