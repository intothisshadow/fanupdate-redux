<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — ReleaseNotesRenderer
 *
 * Full GitHub Flavored Markdown rendering for GitHub release notes
 * (TODO.md item "8. Implement Full Markdown Rendering for GitHub Release
 * Notes"), replacing the previous pipeline of wpautop()/doHeaders()/
 * doLists() (the WordPress-derived/PHP-Markdown-subset formatter in
 * functions.php) that admin/update.php used for this one purpose.
 *
 * Backed by class/Parsedown.php (vendored, unmodified — see that file's
 * own header for attribution/licensing). Parsedown alone already covers
 * almost everything GFM-quality rendering needs: headers, bold/italic,
 * inline code, fenced code blocks with a language class, tables (with
 * column alignment), blockquotes, horizontal rules, links (inline,
 * reference-style, and bare-URL autolinking), images, and strikethrough
 * are all native Parsedown features — no ParsedownExtra or heavier
 * CommonMark library was needed for this project's requirements. The one
 * GFM feature Parsedown deliberately does not implement (see
 * https://github.com/erusev/parsedown/issues/119 — upstream considers
 * task lists outside core Parsedown's scope) is GFM task-list checkboxes
 * (`- [ ]` / `- [x]`), implemented here as a small HTML post-processing
 * step instead of by patching the vendored file, so a future Parsedown
 * version bump stays a straight drop-in file replacement.
 *
 * Security: constructs Parsedown with safe mode enabled (strips/escapes
 * raw HTML, neutralises `javascript:`-style link/image destinations —
 * satisfies TODO.md's "sanitize output to prevent XSS"/"disable or
 * carefully restrict raw HTML" requirements) and GFM-style line breaks
 * (setBreaksEnabled(true), matching how GitHub renders a single newline
 * within a paragraph as a <br>). Malformed Markdown is handled gracefully:
 * Parsedown's line-based parser never throws on malformed input, but
 * renderToHtml() still wraps the call in a try/catch and falls back to
 * plain escaped text as defence in depth.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class ReleaseNotesRenderer
{
    private readonly Parsedown $parsedown;

    /**
     * @param Parsedown|null $parsedown Optional injection point for tests;
     *        defaults to a real Parsedown instance configured for
     *        untrusted GitHub content (safe mode + GFM-style line breaks).
     */
    public function __construct(?Parsedown $parsedown = null)
    {
        $this->parsedown = $parsedown ?? new Parsedown();
        $this->parsedown->setSafeMode(true);
        $this->parsedown->setBreaksEnabled(true);
    }

    /**
     * Render a release note body (Markdown or plain text — GitHub allows
     * either) to sanitised HTML. Returns an empty string for empty/
     * whitespace-only input, same as the previous wpautop()-based
     * pipeline's effective behaviour at the one call site in
     * admin/update.php.
     */
    public function renderToHtml(string $markdown): string
    {
        $markdown = trim($markdown);

        if ($markdown === '') {
            return '';
        }

        try {
            $html = $this->parsedown->text($markdown);
        } catch (\Throwable) {
            // Defence in depth — Parsedown's own parser doesn't throw on
            // malformed input, but a rendering failure must never take
            // down the Updates page; fall back to plain escaped text.
            return '<p>' . htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return $this->renderTaskListCheckboxes($html);
    }

    /**
     * Post-process Parsedown's HTML output to turn a GFM task-list marker
     * — literal "[ ] "/"[x] "/"[X] " text at the start of a <li> (Parsedown
     * has no special handling for square brackets not followed by "(" or
     * "[", so it passes them through as plain text) — into a disabled
     * checkbox, matching GitHub's own task-list rendering. Handles both
     * "tight" list items (<li>[ ] text</li>) and "loose" ones where
     * Parsedown wraps the item in its own <p> (<li><p>[ ] text</p></li>).
     * Runs only on Parsedown's own safe-mode-sanitised output, and the
     * checkbox markup itself is a fixed string this class controls — no
     * attacker-influenced content is ever interpreted as HTML here.
     */
    private function renderTaskListCheckboxes(string $html): string
    {
        return (string) preg_replace_callback(
            '/<li>(\s*<p>)?\[([ xX])\]\s+/',
            static function (array $matches): string {
                $checked = strtolower($matches[2]) === 'x';
                $openTag = $matches[1] ?? '';

                return '<li class="fu-task-list-item">' . $openTag
                    . '<input type="checkbox" disabled' . ($checked ? ' checked' : '') . ' /> ';
            },
            $html
        );
    }
}
