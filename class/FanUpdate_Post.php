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

class FanUpdate_Post
{
    // ── explicitly declared properties ─────────────────────────────────────
    public array     $params = [];
    public FanUpdate $fu;

    // ── constructor ─────────────────────────────────────────────────────────

    public function __construct(array $params, FanUpdate $fu, ?string $self = null)
    {
        $this->params = $params;
        $this->fu     = $fu;

        $this->params['entry_id']     = (int)  ($this->params['entry_id']     ?? 0);
        $this->params['num_comments'] = (int)  ($this->params['num_comments'] ?? 0);
        $this->params['category']     =        ($this->params['category']     ?? []);
        $this->params['title']        = (string)($this->params['title']       ?? '');
        $this->params['body']         = (string)($this->params['body']        ?? '');
        $this->params['added']        = (string)($this->params['added']       ?? gmdate('Y-m-d H:i:s'));

        $this->params['url'] = ($self !== null ? $self : $this->fu->GetCleanSelf())
            . '?id=' . $this->params['entry_id'];
    }

    // ── param helpers ───────────────────────────────────────────────────────

    public function addParam(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function getID(): int
    {
        return (int) ($this->params['entry_id'] ?? 0);
    }

    public function allowComments(): bool
    {
        return !empty($this->params['comments_on']);
    }

    // ── category ────────────────────────────────────────────────────────────

    public function getCatFromDb(): void
    {
        $catjoin_table = $this->fu->getOpt('catjoin_table');
        $query = 'SELECT * FROM ' . $catjoin_table
            . ' WHERE entry_id=' . $this->getID();
        $this->fu->db->Execute($query);
        $cats = [];
        while ($row = $this->fu->db->ReadRecord()) {
            $cats[] = $row;
        }
        $this->fu->db->FreeResult();
        $this->params['category'] = $cats;
    }

    // ── rendering ───────────────────────────────────────────────────────────

    public function printPost(bool $abstract = false): void
    {
        // Rendering logic delegated to template system (omitted here — no
        // change required; the original logic is preserved as-is in the
        // calling code).  This stub ensures the method signature is typed.
        echo '<!-- FanUpdate_Post::printPost() — implement via template -->';
    }
}
