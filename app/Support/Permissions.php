<?php

namespace App\Support;

/**
 * Single source of truth for roles and permissions.
 * Seeded by Database\Seeders\RolesAndPermissionsSeeder.
 */
final class Permissions
{
    public const ROLE_SUPER_ADMIN = 'Super Admin';
    public const ROLE_ADMIN = 'Admin';
    public const ROLE_AGENT = 'Agent';
    public const ROLE_CUSTOMER = 'Customer';  // self-registered business; sees only its own numbers, limited by its plan

    public const DASHBOARD_VIEW = 'dashboard.view';
    public const USERS_MANAGE = 'users.manage';
    public const ACCOUNTS_MANAGE = 'accounts.manage';      // add / edit / delete / credentials
    public const ACCOUNTS_ACCESS_ALL = 'accounts.access_all'; // see every WhatsApp number without assignment
    public const CHATS_VIEW = 'chats.view';
    public const CHATS_SEND = 'chats.send';
    public const CONTACTS_VIEW = 'contacts.view';
    public const CONTACTS_MANAGE = 'contacts.manage';
    public const CONTACTS_IMPORT_EXPORT = 'contacts.import_export';
    public const TEMPLATES_VIEW = 'templates.view';
    public const TEMPLATES_SYNC = 'templates.sync';
    public const TEMPLATES_SEND = 'templates.send';
    public const ACTIVITY_VIEW = 'activity.view';
    public const SETTINGS_MANAGE = 'settings.manage';     // Meta Tech Provider app settings (Super Admin)
    public const PLANS_MANAGE = 'plans.manage';           // create plans, registration on/off (Super Admin)
    public const BOTS_MANAGE = 'bots.manage';             // bot flows

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::DASHBOARD_VIEW,
            self::USERS_MANAGE,
            self::ACCOUNTS_MANAGE,
            self::ACCOUNTS_ACCESS_ALL,
            self::CHATS_VIEW,
            self::CHATS_SEND,
            self::CONTACTS_VIEW,
            self::CONTACTS_MANAGE,
            self::CONTACTS_IMPORT_EXPORT,
            self::TEMPLATES_VIEW,
            self::TEMPLATES_SYNC,
            self::TEMPLATES_SEND,
            self::ACTIVITY_VIEW,
            self::SETTINGS_MANAGE,
            self::PLANS_MANAGE,
            self::BOTS_MANAGE,
        ];
    }

    /**
     * Permissions per role. Super Admin bypasses every check (Gate::before).
     *
     * @return array<string, array<int, string>>
     */
    public static function roleMap(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => self::all(),
            self::ROLE_ADMIN => array_values(array_diff(self::all(), [self::USERS_MANAGE, self::SETTINGS_MANAGE, self::PLANS_MANAGE])),
            self::ROLE_AGENT => [
                self::DASHBOARD_VIEW,
                self::CHATS_VIEW,
                self::CHATS_SEND,
                self::CONTACTS_VIEW,
                self::CONTACTS_MANAGE,
                self::TEMPLATES_VIEW,
                self::TEMPLATES_SEND,
            ],
            // No ACCOUNTS_ACCESS_ALL → policies limit a Customer to the numbers it owns.
            self::ROLE_CUSTOMER => [
                self::DASHBOARD_VIEW,
                self::ACCOUNTS_MANAGE,
                self::CHATS_VIEW,
                self::CHATS_SEND,
                self::CONTACTS_VIEW,
                self::CONTACTS_MANAGE,
                self::CONTACTS_IMPORT_EXPORT,
                self::TEMPLATES_VIEW,
                self::TEMPLATES_SYNC,
                self::TEMPLATES_SEND,
                self::BOTS_MANAGE,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function roles(): array
    {
        return array_keys(self::roleMap());
    }
}
