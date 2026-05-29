<?php
/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Refactored for PHP 8.2+:
 *  - All dynamic properties declared explicitly
 *  - Strict scalar type hints and return types added
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class FanUpdate_Comment
{
    // ── explicitly declared properties ─────────────────────────────────────
    public array     $params = [];
    public FanUpdate $fu;

    // ── constructor ─────────────────────────────────────────────────────────

    public function __construct(array $params, FanUpdate $fu, ?string $self = null)
    {
        $this->params = $params;
        $this->fu     = $fu;

        $this->params['comment_id'] = (int)   ($this->params['comment_id'] ?? 0);
        $this->params['entry_id']   = (int)   ($this->params['entry_id']   ?? 0);
        $this->params['name']       = (string)($this->params['name']       ?? 'Anonymous');
        $this->params['email']      = (string)($this->params['email']      ?? '');
        $this->params['url']        = (string)($this->params['url']        ?? '');
        $this->params['comment']    = (string)($this->params['comment']    ?? '');
        $this->params['added']      = (string)($this->params['added']      ?? date('Y-m-d H:i:s'));

        $base = $self !== null ? $self : $this->fu->GetCleanSelf();
        $this->params['link_url'] = $base
            . '?id=' . $this->params['entry_id']
            . '#comment' . $this->params['comment_id'];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    public function addParam(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function getID(): int
    {
        return (int) ($this->params['comment_id'] ?? 0);
    }

    public function getEntryID(): int
    {
        return (int) ($this->params['entry_id'] ?? 0);
    }
}
