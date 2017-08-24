<?php
/**
 * @author Arthur Schiwon <blizzz@owncloud.com>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Jakob Sack <mail@jakobsack.de>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Michael Gapczynski <GapczynskiM@gmail.com>
 * @author michag86 <micha_g@arcor.de>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
/*
 *
 * The following SQL statement is just a help for developers and will not be
 * executed!
 *
 * CREATE TABLE `groups` (
 *   `gid` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
 *   PRIMARY KEY (`gid`)
 * ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
 *
 * CREATE TABLE `group_user` (
 *   `gid` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
 *   `uid` varchar(64) COLLATE utf8_unicode_ci NOT NULL
 * ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
 *
 */

/**
 * Class for group management in a SQL Database (e.g. MySQL, SQLite)
 */
class OC_Group_Database extends OC_Group_Backend {

	/** @var string[] */
	private $groupCache = [];

	/** @var \OCP\IDBConnection */
	private $dbConn;

	/**
	 * OC_Group_Database constructor.
	 *
	 * @param \OCP\IDBConnection|null $dbConn
	 */
	public function __construct(\OCP\IDBConnection $dbConn = null) {
		$this->dbConn = $dbConn;
	}

	/**
	 * FIXME: This function should not be required!
	 */
	private function fixDI() {
		if ($this->dbConn === null) {
			$this->dbConn = \OC::$server->getDatabaseConnection();
		}
	}

	/**
	 * Try to create a new group
	 * @param string $gid The name of the group to create
	 * @return bool
	 *
	 * Tries to create a new group. If the group name already exists, false will
	 * be returned.
	 */
	public function createGroup( $gid ) {
		$this->fixDI();

		// Add group
		$result = $this->dbConn->insertIfNotExist('*PREFIX*groups', [
			'gid' => $gid,
		]);

		// Add to cache
		$this->groupCache[$gid] = $gid;

		return $result === 1;
	}

	/**
	 * delete a group
	 * @param string $gid gid of the group to delete
	 * @return bool
	 *
	 * Deletes a group and removes it from the group_user-table
	 */
	public function deleteGroup( $gid ) {
		$this->fixDI();

		// Delete the group
		$qb = $this->dbConn->getQueryBuilder();
		$qb->delete('groups')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->execute();

		// Delete the group-user relation
		$qb = $this->dbConn->getQueryBuilder();
		$qb->delete('group_user')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->execute();

		// Delete the group-groupadmin relation
		$qb = $this->dbConn->getQueryBuilder();
		$qb->delete('group_admin')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->execute();

		// Delete from cache
		unset($this->groupCache[$gid]);

		return true;
	}

	/**
	 * is user in group?
	 * @param string $uid uid of the user
	 * @param string $gid gid of the group
	 * @return bool
	 *
	 * Checks whether the user is member of a group or not.
	 */
	public function inGroup( $uid, $gid ) {
		$this->fixDI();

		// check
		$qb = $this->dbConn->getQueryBuilder();
		$cursor = $qb->select('uid')
			->from('group_user')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->execute();

		$result = $cursor->fetch();
		$cursor->closeCursor();

		return $result ? true : false;
	}

	/**
	 * Add a user to a group
	 * @param string $uid Name of the user to add to group
	 * @param string $gid Name of the group in which add the user
	 * @return bool
	 *
	 * Adds a user to a group.
	 */
	public function addToGroup( $uid, $gid ) {
		$this->fixDI();

		// No duplicate entries!
		if( !$this->inGroup( $uid, $gid )) {
			$qb = $this->dbConn->getQueryBuilder();
			$qb->insert('group_user')
				->setValue('uid', $qb->createNamedParameter($uid))
				->setValue('gid', $qb->createNamedParameter($gid))
				->execute();
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Removes a user from a group
	 * @param string $uid Name of the user to remove from group
	 * @param string $gid Name of the group from which remove the user
	 * @return bool
	 *
	 * removes the user from a group.
	 */
	public function removeFromGroup( $uid, $gid ) {
		$this->fixDI();

		$qb = $this->dbConn->getQueryBuilder();
		$qb->delete('group_user')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->execute();

		return true;
	}

	/**
	 * Get all groups a user belongs to
	 * @param string $uid Name of the user
	 * @return array an array of group names
	 *
	 * This function fetches all groups a user belongs to. It does not check
	 * if the user exists at all.
	 */
	public function getUserGroups( $uid ) {
		$this->fixDI();

		// No magic!
		$qb = $this->dbConn->getQueryBuilder();
		$cursor = $qb->select('gid')
			->from('group_user')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->execute();

		$groups = [];
		while( $row = $cursor->fetch()) {
			if ( !array_key_exists('gid', $row) ) {
				\OC::$server->getLogger()->error("Unexpected row for ".__METHOD__."($uid):".print_r($row, true), ['app'=>'debug']);
				throw new \OutOfBoundsException('An internal error occurred, please try again');
			}
			$groups[] = $row["gid"];
			$this->groupCache[$row['gid']] = $row['gid'];
		}
		$cursor->closeCursor();

		return $groups;
	}

	/**
	 * get a list of all groups
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return array an array of group names
	 *
	 * Returns a list with all groups
	 */
	public function getGroups($search = '', $limit = null, $offset = null) {
		$parameters = [];
		$searchLike = '';
		if ($search !== '') {
			$parameters[] = '%' . $search . '%';
			$searchLike = ' WHERE LOWER(`gid`) LIKE LOWER(?)';
		}

		$stmt = OC_DB::prepare('SELECT `gid` FROM `*PREFIX*groups`' . $searchLike . ' ORDER BY `gid` ASC', $limit, $offset);
		$result = $stmt->execute($parameters);
		$groups = array();
		while ($row = $result->fetchRow()) {
			if ( !array_key_exists('gid', $row) ) {
				\OC::$server->getLogger()->error("Unexpected row for ".__METHOD__."($search, $limit, $offset):".print_r($row, true), ['app'=>'debug']);
				throw new \OutOfBoundsException('An internal error occurred, please try again');
			}
			$groups[] = $row['gid'];
		}
		return $groups;
	}

	/**
	 * check if a group exists
	 * @param string $gid
	 * @return bool
	 */
	public function groupExists($gid) {
		$this->fixDI();

		// Check cache first
		if (isset($this->groupCache[$gid])) {
			return true;
		}

		$qb = $this->dbConn->getQueryBuilder();
		$cursor = $qb->select('gid')
			->from('groups')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->execute();

		if ($row = $cursor->fetch()) {
			if ($unexpected = $cursor->fetch()) {
				\OC::$server->getLogger()->error("Too many rows for ".__METHOD__."($gid):".print_r($unexpected, true), ['app'=>'debug']);
				throw new \LengthException('An internal error occurred, please try again');
			}
			if (!array_key_exists('gid', $row)) {
				\OC::$server->getLogger()->error("Unexpected row for " . __METHOD__ . "($gid):" . print_r($row, true), ['app' => 'debug']);
				throw new \OutOfBoundsException('An internal error occurred, please try again');
			}
			$this->groupCache[$gid] = $gid;
			return true;
		} else {
			return false;
		}
	}

	/**
	 * get a list of all users in a group
	 * @param string $gid
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return array an array of user ids
	 */
	public function usersInGroup($gid, $search = '', $limit = null, $offset = null) {
		$parameters = [$gid];
		$searchLike = '';
		if ($search !== '') {
			$parameters[] = '%' . $this->dbConn->escapeLikeParameter($search) . '%';
			$searchLike = ' AND `uid` LIKE ?';
		}

		$stmt = OC_DB::prepare('SELECT `uid` FROM `*PREFIX*group_user` WHERE `gid` = ?' . $searchLike . ' ORDER BY `uid` ASC',
			$limit,
			$offset);
		$result = $stmt->execute($parameters);
		$users = array();
		while ($row = $result->fetchRow()) {
			if ( !array_key_exists('uid', $row) ) {
				\OC::$server->getLogger()->error("Unexpected row for ".__METHOD__."($gid, $search, $limit, $offset):".print_r($row, true), ['app'=>'debug']);
				throw new \OutOfBoundsException('An internal error occurred, please try again');
			}
			$users[] = $row['uid'];
		}
		return $users;
	}

	/**
	 * get the number of all users matching the search string in a group
	 * @param string $gid
	 * @param string $search
	 * @return int|false
	 * @throws \OC\DatabaseException
	 */
	public function countUsersInGroup($gid, $search = '') {
		$parameters = [$gid];
		$searchLike = '';
		if ($search !== '') {
			$parameters[] = '%' . $this->dbConn->escapeLikeParameter($search) . '%';
			$searchLike = ' AND `uid` LIKE ?';
		}

		$stmt = OC_DB::prepare('SELECT COUNT(`uid`) AS `count` FROM `*PREFIX*group_user` WHERE `gid` = ?' . $searchLike);
		$result = $stmt->execute($parameters);
		$count = $result->fetchOne();
		if($count !== false) {
			$count = intval($count);
		}
		return $count;
	}

}