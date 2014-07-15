<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Authentication\Backend;

use Icinga\User;
use Icinga\Authentication\UserBackend;
use Icinga\Protocol\Ldap\Connection;
use Icinga\Exception\AuthenticationException;
use Icinga\Protocol\Ldap\Exception as LdapException;

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
     * Probe the backend to test if authentication is possible
     *
     * Try to bind to the backend and query all available users to check if:
     * <ul>
     *  <li>User connection credentials are correct and the bind is possible</li>
     *  <li>At least one user exists</li>
     *  <li>The specified userClass has the property specified by userNameAttribute</li>
     * </ul>
     *
     * @throws AuthenticationException  When authentication is not possible
     */
    public function assertAuthenticationPossible()
    {
        $q = $this->conn->select()->from($this->userClass);
        $result = $q->fetchRow();
        if (! isset($result)) {
            throw new AuthenticationException(
                sprintf(
                    'No objects with objectClass="%s" in DN="%s" found.',
                    $this->userClass,
                    $this->conn->getDN()
                )
            );
        }

        if (! isset($result->{$this->userNameAttribute})) {
            throw new AuthenticationException(
                sprintf(
                    'UserNameAttribute "%s" not existing in objectClass="%s"',
                    $this->userNameAttribute,
                    $this->userClass
                )
            );
        }
    }

    /**
     * Test whether the given user exists
     *
     * @param   User $user
     *
     * @return  bool
     * @throws  AuthenticationException
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
     * @param   boolean $healthCheck        Perform additional health checks to generate more useful exceptions in case
     *                                      of a configuration or backend error
     *
     * @return  bool                        True when the authentication was successful, false when the username
     *                                      or password was invalid
     * @throws  AuthenticationException     When an error occurred during authentication and authentication is not possible
     */
    public function authenticate(User $user, $password, $healthCheck = true)
    {
        if ($healthCheck) {
            try {
                $this->assertAuthenticationPossible();
            } catch (AuthenticationException $e) {
                // Authentication not possible
                throw new AuthenticationException(
                    sprintf(
                        'Authentication against backend "%s" not possible: ',
                        $this->getName()
                    ),
                    0,
                    $e
                );
            }
        }
        if (! $this->hasUser($user)) {
            return false;
        }
        try {
            return $this->conn->testCredentials(
                $this->conn->fetchDN($this->createQuery($user->getUsername())),
                $password
            );
        } catch (LdapException $e) {
            // Error during authentication of this specific user
            throw new AuthenticationException(
                sprintf(
                    'Failed to authenticate user "%s" against backend "%s". An exception was thrown:',
                    $user->getUsername(),
                    $this->getName()
                ),
                0,
                $e
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
}

