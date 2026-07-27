<?php

/*****************************************************************************
 * FanUpdate — AccountRepositoryInterface
 *
 * Data-access contract for the admin account. admin_username/admin_password/
 * admin_email are all plain options-table rows, so every read/write here is
 * ordinary SqlConnection-backed SQL, same shape as every other domain. This
 * repository still depends on FanUpdate itself (in addition to SqlConnection)
 * because a username or password change requires FanUpdate::reissueToken()
 * to re-issue the HMAC session cookie so the admin stays logged in — that's
 * a FanUpdate responsibility, not a SqlConnection one.
 *
 * AccountService (see class/Service/AccountService.php) depends on this
 * interface rather than the concrete AccountRepository, so unit tests can
 * substitute a test double without touching FanUpdate, SqlConnection, or a
 * real database/config file.
 *
 * No business rules or presentation formatting belong here — this is pure
 * data access. Username-format validation, email-format validation, and
 * password confirmation/length rules stay in admin/account.php — those are
 * request-level validation, not a repository concern.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface AccountRepositoryInterface
{
    public function getAdminEmail(): string;

    public function getAdminUsername(): string;

    public function getAdminPasswordHash(): string;

    public function updateEmail(string $email): bool;

    public function updateUsername(string $username): bool;

    public function updatePasswordHash(string $hash): bool;

    public function reissueToken(string $username): void;
}
