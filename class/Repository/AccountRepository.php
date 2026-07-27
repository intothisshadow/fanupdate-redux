<?php

/*****************************************************************************
 * FanUpdate — AccountRepository
 *
 * Data access for the admin account. admin_username/admin_password/
 * admin_email are all plain options-table rows now (seeded once by
 * install.php on fresh install — see its docblock), so every read/write
 * here is an ordinary SqlConnection query, same shape as every other
 * domain's repository. Still depends on FanUpdate directly, though: a
 * successful username/password change requires FanUpdate::reissueToken()
 * to re-issue the HMAC session cookie (derived from admin_username/
 * admin_password) so the admin stays logged in — that's a FanUpdate
 * responsibility, not a SqlConnection one.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class AccountRepository implements AccountRepositoryInterface
{
    public function __construct(
        private readonly FanUpdate $fu,
        private readonly SqlConnection $db,
        private readonly string $optionsTable,
    ) {
    }

    public function getAdminEmail(): string
    {
        return (string) $this->fu->getOpt('admin_email');
    }

    public function getAdminUsername(): string
    {
        return (string) $this->fu->getOpt('admin_username', true);
    }

    public function getAdminPasswordHash(): string
    {
        return (string) $this->fu->getOpt('admin_password', true);
    }

    public function updateEmail(string $email): bool
    {
        return $this->db->Execute(
            'UPDATE ' . $this->optionsTable . "
             SET optvalue = ? WHERE optkey = 'admin_email'",
            'Could not update email.',
            [$email]
        );
    }

    public function updateUsername(string $username): bool
    {
        $ok = $this->db->Execute(
            'UPDATE ' . $this->optionsTable . "
             SET optvalue = ? WHERE optkey = 'admin_username'",
            'Could not update username.',
            [$username]
        );

        if ($ok) {
            $this->fu->addOpt('admin_username', $username);
        }

        return $ok;
    }

    public function updatePasswordHash(string $hash): bool
    {
        $ok = $this->db->Execute(
            'UPDATE ' . $this->optionsTable . "
             SET optvalue = ? WHERE optkey = 'admin_password'",
            'Could not update password.',
            [$hash]
        );

        if ($ok) {
            $this->fu->addOpt('admin_password', $hash);
        }

        return $ok;
    }

    public function reissueToken(string $username): void
    {
        $this->fu->reissueToken($username);
    }
}
