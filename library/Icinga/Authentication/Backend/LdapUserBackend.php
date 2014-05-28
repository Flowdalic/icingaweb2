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

namespace Icinga\Authentication\Backend;

use \Exception;
use Icinga\User;
use Icinga\Logger\Logger;
use Icinga\Authentication\UserBackend;
use Icinga\Protocol\Ldap\Connection;

class LdapUserBackend extends UserBackend
{
    /**
     * Connection to the LDAP server
     *
     * @var Connection
     **/
    protected $conn;

    protected $userClass;

    protected $userNameAttribute;

    public function __construct(Connection $conn, $userClass, $userNameAttribute)
    {
        $this->conn = $conn;
        $this->userClass = $userClass;
        $this->userNameAttribute = $userNameAttribute;
    }

    /**
     * Create query
     *
     * @param   string $username
     *
     * @return  \Icinga\Protocol\Ldap\Query
     **/
    protected function createQuery($username)
    {
        return $this->conn->select()
            ->from(
                $this->userClass,
                array($this->userNameAttribute)
            )
            ->where(
                $this->userNameAttribute,
                str_replace('*', '', $username)
            );
    }

    /**
     * Test whether the given user exists
     *
     * @param   User $user
     *
     * @return  bool
     */
    public function hasUser(User $user)
    {
        $username = $user->getUsername();
        return $this->conn->fetchOne($this->createQuery($username)) === $username;
    }

    /**
     * Authenticate the given user and return true on success, false on failure and null on error
     *
     * @param   User    $user
     * @param   string  $password
     *
     * @return  bool|null
     */
    public function authenticate(User $user, $password)
    {
        try {
            return $this->conn->testCredentials(
                $this->conn->fetchDN($this->createQuery($user->getUsername())),
                $password
            );
        } catch (Exception $e) {
            Logger::error(
                sprintf(
                    'Failed to authenticate user "%s" with backend "%s". Exception occured: %s',
                    $user->getUsername(),
                    $this->getName(),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Get the number of users available
     *
     * @return int
     */
    public function count()
    {
        return $this->conn->count(
            $this->conn->select()->from(
                $this->userClass,
                array(
                    $this->userNameAttribute
                )
            )
        );
    }

    /**
     * Get a list of available users.
     *
     * @return array
     */
    public function getUsers()
    {
        $users = $this->conn->select()
            ->from($this->userClass, array($this->userNameAttribute))
            ->fetchAll();
        $usernames = array();
        foreach ($users as $userObject) {
            $usernames[] = $userObject->uid;
        }
        return $usernames;
    }
}

