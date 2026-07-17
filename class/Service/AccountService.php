<?php

/*****************************************************************************
 * FanUpdate — AccountService
 *
 * Business logic for the admin account: reading the current email/username
 * for display, verifying the current password before allowing a password
 * change, and updating the username/email/password. Depends on
 * AccountRepositoryInterface (constructor injection), not a concrete
 * repository or the FanUpdate singleton, so it can be unit tested with a
 * fake/mock repository.
 *
 * None of updateUsername()/updateEmail()/updatePassword() check whether
 * the underlying write succeeded — the caller (admin/account.php) always
 * shows a success message once request-level validation has passed. This
 * is deliberate, not an oversight.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class AccountService
{
    public function __construct(
        private readonly AccountRepositoryInterface $account,
    ) {
    }

    public function getCurrentEmail(): string
    {
        return $this->account->getAdminEmail();
    }

    public function getCurrentUsername(): string
    {
        return $this->account->getAdminUsername();
    }

    public function verifyCurrentPassword(string $password): bool
    {
        return password_verify($password, $this->account->getAdminPasswordHash());
    }

    public function updateUsername(string $username): void
    {
        $this->account->updateUsername($username);
        $this->account->reissueToken($username);
    }

    public function updateEmail(string $email): void
    {
        $this->account->updateEmail($email);
    }

    public function updatePassword(string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->account->updatePasswordHash($hash);
        $this->account->reissueToken($this->account->getAdminUsername());
    }
}
