<?php

/*
 * This file is part of Hiject.
 *
 * Copyright (C) 2016 Hiject Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hiject\Core\Ldap;

use LogicException;
use Hiject\Core\Security\Role;
use Hiject\User\LdapUserProvider;

/**
 * LDAP User Finder
 */
class User
{
    /**
     * Query
     *
     * @access protected
     * @var Query
     */
    protected $query;

    /**
     * LDAP Group object
     *
     * @access protected
     * @var Group
     */
    protected $group;

    /**
     * Constructor
     *
     * @access public
     * @param  Query $query
     * @param  Group  $group
     */
    public function __construct(Query $query, Group $group = null)
    {
        $this->query = $query;
        $this->group = $group;
    }

    /**
     * Get user profile
     *
     * @static
     * @access public
     * @param  Client    $client
     * @param  string    $username
     * @return LdapUserProvider
     */
    public static function getUser(Client $client, $username)
    {
        $self = new static(new Query($client), new Group(new Query($client)));
        return $self->find($self->getLdapUserPattern($username));
    }

    /**
     * Find user
     *
     * @access public
     * @param  string    $query
     * @return LdapUserProvider
     */
    public function find($query)
    {
        $this->query->execute($this->getBasDn(), $query, $this->getAttributes());
        $user = null;

        if ($this->query->hasResult()) {
            $user = $this->build();
        }

        return $user;
    }

    /**
     * Get user groupIds (DN)
     *
     * 1) If configured, use memberUid and posixGroup
     * 2) Otherwise, use memberOf
     *
     * @access protected
     * @param  Entry   $entry
     * @param  string  $username
     * @return string[]
     */
    protected function getGroups(Entry $entry, $username)
    {
        $groupIds = array();

        if (! empty($username) && $this->group !== null && $this->hasGroupUserFilter()) {
            $groups = $this->group->find(sprintf($this->getGroupUserFilter(), $username));

            foreach ($groups as $group) {
                $groupIds[] = $group->getExternalId();
            }
        } else {
            $groupIds = $entry->getAll($this->getAttributeGroup());
        }

        return $groupIds;
    }

    /**
     * Get role from LDAP groups
     *
     * Note: Do not touch the current role if groups are not configured
     *
     * @access protected
     * @param  string[] $groupIds
     * @return string
     */
    protected function getRole(array $groupIds)
    {
        if (! $this->hasGroupsConfigured()) {
            return null;
        }

        foreach ($groupIds as $groupId) {
            $groupId = strtolower($groupId);

            if ($groupId === strtolower($this->getGroupAdminDn())) {
                return Role::APP_ADMIN;
            } elseif ($groupId === strtolower($this->getGroupManagerDn())) {
                return Role::APP_MANAGER;
            }
        }

        return Role::APP_USER;
    }

    /**
     * Build user profile
     *
     * @access protected
     * @return LdapUserProvider
     */
    protected function build()
    {
        $entry = $this->query->getEntries()->getFirstEntry();
        $username = $entry->getFirstValue($this->getAttributeUsername());
        $groupIds = $this->getGroups($entry, $username);

        return new LdapUserProvider(
            $entry->getDn(),
            $username,
            $entry->getFirstValue($this->getAttributeName()),
            $entry->getFirstValue($this->getAttributeEmail()),
            $this->getRole($groupIds),
            $groupIds,
            $entry->getFirstValue($this->getAttributePhoto()),
            $entry->getFirstValue($this->getAttributeLanguage())
        );
    }

    /**
     * Ge the list of attributes to fetch when reading the LDAP user entry
     *
     * Must returns array with index that start at 0 otherwise ldap_search returns a warning "Array initialization wrong"
     *
     * @access public
     * @return array
     */
    public function getAttributes()
    {
        return array_values(array_filter(array(
            $this->getAttributeUsername(),
            $this->getAttributeName(),
            $this->getAttributeEmail(),
            $this->getAttributeGroup(),
            $this->getAttributePhoto(),
            $this->getAttributeLanguage(),
        )));
    }

    /**
     * Get LDAP account id attribute
     *
     * @access public
     * @return string
     */
    public function getAttributeUsername()
    {
        if (! LDAP_USER_ATTRIBUTE_USERNAME) {
            throw new LogicException('LDAP username attribute empty, check the parameter LDAP_USER_ATTRIBUTE_USERNAME');
        }

        return strtolower(LDAP_USER_ATTRIBUTE_USERNAME);
    }

    /**
     * Get LDAP user name attribute
     *
     * @access public
     * @return string
     */
    public function getAttributeName()
    {
        if (! LDAP_USER_ATTRIBUTE_FULLNAME) {
            throw new LogicException('LDAP full name attribute empty, check the parameter LDAP_USER_ATTRIBUTE_FULLNAME');
        }

        return strtolower(LDAP_USER_ATTRIBUTE_FULLNAME);
    }

    /**
     * Get LDAP account email attribute
     *
     * @access public
     * @return string
     */
    public function getAttributeEmail()
    {
        if (! LDAP_USER_ATTRIBUTE_EMAIL) {
            throw new LogicException('LDAP email attribute empty, check the parameter LDAP_USER_ATTRIBUTE_EMAIL');
        }

        return strtolower(LDAP_USER_ATTRIBUTE_EMAIL);
    }

    /**
     * Get LDAP account memberOf attribute
     *
     * @access public
     * @return string
     */
    public function getAttributeGroup()
    {
        return strtolower(LDAP_USER_ATTRIBUTE_GROUPS);
    }

    /**
     * Get LDAP profile photo attribute
     *
     * @access public
     * @return string
     */
    public function getAttributePhoto()
    {
        return strtolower(LDAP_USER_ATTRIBUTE_PHOTO);
    }

    /**
     * Get LDAP language attribute
     *
     * @access public
     * @return string
     */
    public function getAttributeLanguage()
    {
        return strtolower(LDAP_USER_ATTRIBUTE_LANGUAGE);
    }

    /**
     * Get LDAP Group User filter
     *
     * @access public
     * @return string
     */
    public function getGroupUserFilter()
    {
        return LDAP_GROUP_USER_FILTER;
    }

    /**
     * Return true if LDAP Group User filter is defined
     *
     * @access public
     * @return string
     */
    public function hasGroupUserFilter()
    {
        return $this->getGroupUserFilter() !== '' && $this->getGroupUserFilter() !== null;
    }

    /**
     * Return true if LDAP Group mapping are configured
     *
     * @access public
     * @return boolean
     */
    public function hasGroupsConfigured()
    {
        return $this->getGroupAdminDn() || $this->getGroupManagerDn();
    }

    /**
     * Get LDAP admin group DN
     *
     * @access public
     * @return string
     */
    public function getGroupAdminDn()
    {
        return strtolower(LDAP_GROUP_ADMIN_DN);
    }

    /**
     * Get LDAP application manager group DN
     *
     * @access public
     * @return string
     */
    public function getGroupManagerDn()
    {
        return LDAP_GROUP_MANAGER_DN;
    }

    /**
     * Get LDAP user base DN
     *
     * @access public
     * @return string
     */
    public function getBasDn()
    {
        if (! LDAP_USER_BASE_DN) {
            throw new LogicException('LDAP user base DN empty, check the parameter LDAP_USER_BASE_DN');
        }

        return LDAP_USER_BASE_DN;
    }

    /**
     * Get LDAP user pattern
     *
     * @access public
     * @param  string  $username
     * @param  string  $filter
     * @return string
     */
    public function getLdapUserPattern($username, $filter = LDAP_USER_FILTER)
    {
        if (! $filter) {
            throw new LogicException('LDAP user filter empty, check the parameter LDAP_USER_FILTER');
        }

        return str_replace('%s', $username, $filter);
    }
}
