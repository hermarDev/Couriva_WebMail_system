<?php
/**
 * Couriva - Active Directory / LDAP Configuration (Section 54)
 * Supports Windows Server Active Directory and OpenLDAP authentication
 */

if (!defined('WEBMAIL_INIT') && php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

return [
    /*
    |--------------------------------------------------------------------------
    | Active Directory / LDAP Authentication Toggle
    |--------------------------------------------------------------------------
    */
    'enabled' => getenv('LDAP_ENABLED') === 'true' || getenv('LDAP_ENABLED') === '1',

    /*
    |--------------------------------------------------------------------------
    | LDAP Server Connection Settings
    |--------------------------------------------------------------------------
    */
    'host'    => getenv('LDAP_HOST') !== false ? getenv('LDAP_HOST') : '127.0.0.1',
    'port'    => getenv('LDAP_PORT') !== false ? (int)getenv('LDAP_PORT') : 389,
    'use_ssl' => getenv('LDAP_USE_SSL') === 'true',
    'use_tls' => getenv('LDAP_USE_TLS') === 'true',
    'timeout' => getenv('LDAP_TIMEOUT') !== false ? (int)getenv('LDAP_TIMEOUT') : 10,

    /*
    |--------------------------------------------------------------------------
    | Active Directory Domain & Base DN
    |--------------------------------------------------------------------------
    */
    'domain'         => getenv('LDAP_DOMAIN') !== false ? getenv('LDAP_DOMAIN') : 'couriva.local',
    'base_dn'        => getenv('LDAP_BASE_DN') !== false ? getenv('LDAP_BASE_DN') : 'dc=couriva,dc=local',
    'account_suffix' => getenv('LDAP_ACCOUNT_SUFFIX') !== false ? getenv('LDAP_ACCOUNT_SUFFIX') : '@couriva.local',

    /*
    |--------------------------------------------------------------------------
    | Service Account Bind Credentials (Optional for searching directory)
    |--------------------------------------------------------------------------
    */
    'bind_dn'       => getenv('LDAP_BIND_DN') !== false ? getenv('LDAP_BIND_DN') : '',
    'bind_password' => getenv('LDAP_BIND_PASSWORD') !== false ? getenv('LDAP_BIND_PASSWORD') : '',

    /*
    |--------------------------------------------------------------------------
    | Search Filter & User Sync
    |--------------------------------------------------------------------------
    */
    'user_filter'       => getenv('LDAP_USER_FILTER') !== false 
        ? getenv('LDAP_USER_FILTER') 
        : '(&(objectClass=user)(|(mail={email})(userPrincipalName={email})(sAMAccountName={username})))',
    'fallback_to_local' => getenv('LDAP_FALLBACK_LOCAL') !== 'false',
    'auto_create_user'  => getenv('LDAP_AUTO_CREATE') !== 'false',
    'default_role'      => 'user',
];
