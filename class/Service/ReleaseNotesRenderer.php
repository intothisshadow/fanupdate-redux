<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — ReleaseNotesRenderer
 *
 * GitHub Flavored Markdown rendering for release notes, backed by the
 * vendored, unmodified class/Parsedown.php. Parsedown lacks GFM task-list
 * checkboxes (`- [ ]` / `- [x]`), so those are added by HTML
 * post-processing here, keeping a Parsedown upgrade a drop-in replacement.
 *
 * Security: Parsedown runs in safe mode (escapes raw HTML, neutralises
 * `javascript:` URLs) with GFM-style line breaks. renderToHtml() also falls
 * back to escaped plain text if rendering ever throws.
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
     * @param Parsedown|null $parsedown Test injection point; defaults to a real instance.
     */
    public function __construct(?Parsedown $parsedown = null)
    {
        $this->parsedown = $parsedown ?? new Parsedown();
        $this->parsedown->setSafeMode(true);
        $this->parsedown->setBreaksEnabled(true);
    }

    /** Renders a release note body (Markdown or plain text) to sanitised HTML; '' for blank input. */
    public function renderToHtml(string $markdown): string
    {
        $markdown = trim($markdown);

        if ($markdown === '') {
            return '';
        }

        try {
            $html = $this->parsedown->text($markdown);
        } catch (\Throwable) {
            // A rendering failure must never take down the Updates page.
            return '<p>' . htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return $this->renderTaskListCheckboxes($html);
    }

    /**
     * Turns a leading "[ ] "/"[x] " in a <li> (tight, or loose with a <p>)
     * into a disabled checkbox. Runs only on Parsedown's sanitised output and
     * emits fixed markup, so no untrusted content is interpreted as HTML.
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
