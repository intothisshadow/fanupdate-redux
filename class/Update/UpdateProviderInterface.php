<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpdateProviderInterface
 *
 * Contract for a remote update source. GitHubUpdateProvider is the only
 * implementation today; the interface exists so UpdateManager (and
 * admin/update.php) never depend on GitHub-specific details, and future
 * providers (GitLab, a self-hosted update server, a custom repository) can
 * be added without touching anything above this layer.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

interface UpdateProviderInterface
{
    /**
     * Fetch metadata for the newest available release on the given channel.
     *
     * @param string $channel 'stable' (default) or 'prerelease'.
     * @throws \RuntimeException when the provider cannot be reached, the
     *         response cannot be parsed, or no matching release is found.
     */
    public function getLatestRelease(string $channel = 'stable'): ReleaseInfo;

    /** Human-readable provider name shown in the Update Source panel, e.g. "GitHub". */
    public function getProviderName(): string;

    /** Human-readable "owner/repo"-style source identifier. */
    public function getSourceLabel(): string;

    /**
     * Hostnames a release's downloadUrl (and any redirect it follows) is
     * allowed to resolve to. DownloadManager checks every hop against this
     * list before following it, so a release payload can never redirect
     * the download to an attacker-controlled host.
     *
     * @return array<int, string>
     */
    public function getAllowedDownloadHosts(): array;
}
