<?php

/*****************************************************************************
 * FanUpdate — AccountRepository
 *
 * Data access for the admin account. Depends on FanUpdate directly (not
 * just SqlConnection) because the username and password hash are persisted
 * to blog-config.php via FanUpdate::updateConfigKey(), and a successful
 * username/password change requires FanUpdate::reissueToken() to keep the
 * admin logged in — neither is a SqlConnection operation. The email update,
 * by contrast, is a plain SqlConnection query like every other domain's
 * repository.
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
        return $this->fu->updateConfigKey('admin_username', $username);
    }

    public function updatePasswordHash(string $hash): bool
    {
        return $this->fu->updateConfigKey('admin_password', $hash);
    }

    public function reissueToken(string $username): void
    {
        $this->fu->reissueToken($username);
    }
}
