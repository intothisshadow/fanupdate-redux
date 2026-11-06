<?php
/*****************************************************************************
 * FanUpdate — admin/logs.php
 *
 * Two tabs: "Activity" (the database-backed admin event log, filterable and
 * paginated) and "Errors" (the file-based PHP error log, newest first).
 * Storage and querying live in LogService / ErrorLogService; this page only
 * parses input, enforces CSRF on the Clear actions, and renders.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav = true;

$siteTimezone    = $fu->timezone();
$logService      = $fu->logService();
$errorLogService = new ErrorLogService(fu_error_log_directory(), dirname(__DIR__));

const FU_LOGS_PER_PAGE = 50;
const FU_ERRORS_SHOWN  = 100;

$tab = (($_GET['tab'] ?? '') === 'errors') ? 'errors' : 'activity';

// ─────────────────────────────────────────────────────────────────────────────
// POST handler (Clear actions)
// ─────────────────────────────────────────────────────────────────────────────

$postAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

if ($postAction !== '' && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif ($postAction === 'clear_activity') {
    if ($logService->clear()) {
        $fu->logEvent(LogService::OPTIONS_CHANGED, 'Activity log cleared.');
        $fu->addSuccess('Activity log cleared.');
    } else {
        $fu->addErr('Could not clear the activity log.');
    }
} elseif ($postAction === 'clear_errors') {
    $tab = 'errors';
    if ($errorLogService->clear()) {
        $fu->addSuccess('Error log cleared.');
    } else {
        $fu->addErr('Could not clear the error log — check that the data/logs directory is writable.');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Activity tab: filters + pagination
// ─────────────────────────────────────────────────────────────────────────────

$filters = [
    'event_type' => is_string($_GET['event_type'] ?? null) ? $_GET['event_type'] : '',
    'search'     => is_string($_GET['search'] ?? null) ? mb_substr(trim($_GET['search']), 0, 100) : '',
    'date_from'  => is_string($_GET['date_from'] ?? null) ? $_GET['date_from'] : '',
    'date_to'    => is_string($_GET['date_to'] ?? null) ? $_GET['date_to'] : '',
];

$page       = max(1, (int) ($_GET['page'] ?? 1));
$rows       = [];
$total      = 0;
$totalPages = 1;

if ($tab === 'activity') {
    // Retention pruning also runs here so a quiet site still gets trimmed.
    $logService->prune($fu->logRetentionDays());

    $total      = $logService->countByFilters($filters);
    $totalPages = max(1, (int) ceil($total / FU_LOGS_PER_PAGE));
    $page       = min($page, $totalPages);
    $rows       = $logService->query($filters, $page, FU_LOGS_PER_PAGE);
}

$errorRows = $tab === 'errors' ? $errorLogService->recent(FU_ERRORS_SHOWN) : [];

/** @param array<string, string|int> $extra */
$logsUrl = static function (array $extra) use ($filters): string {
    $query = array_filter(array_merge($filters, $extra), static fn ($v): bool => $v !== '' && $v !== null);

    return 'logs.php' . ($query === [] ? '' : '?' . http_build_query($query));
};

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$fu->getHeader('Logs');
?>

<div class="page-header">
    <h2>Logs</h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li<?php echo $tab === 'activity' ? ' class="active"' : ''; ?>><a href="logs.php">Activity</a></li>
        <li<?php echo $tab === 'errors' ? ' class="active"' : ''; ?>><a href="logs.php?tab=errors">Errors</a></li>
    </ul>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();

if ($tab === 'activity') { ?>

<p class="hint small">
    Security and admin events, shown in your timezone (<?php echo $e($siteTimezone); ?>).
    <?php echo $fu->logRetentionDays() === 0
        ? 'Entries are kept forever.'
        : 'Entries older than ' . $fu->logRetentionDays() . ' days are removed automatically.'; ?>
    Change this under <a href="options.php">Options</a>.
</p>

<form action="logs.php" method="get" style="margin-bottom:1.5rem;">
    <p>
        <label for="event_type">Event</label>
        <select id="event_type" name="event_type" style="width:auto;">
            <option value="">All events</option>
            <?php foreach (LogService::EVENT_TYPES as $type => $label) { ?>
                <option value="<?php echo $e($type); ?>"<?php echo $filters['event_type'] === $type ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
            <?php } ?>
        </select>
        <label for="search">Search</label>
        <input type="text" id="search" name="search" maxlength="100" style="width:auto;"
               value="<?php echo $e($filters['search']); ?>" />
        <label for="date_from">From</label>
        <input type="date" id="date_from" name="date_from" style="width:auto;" value="<?php echo $e($filters['date_from']); ?>" />
        <label for="date_to">To</label>
        <input type="date" id="date_to" name="date_to" style="width:auto;" value="<?php echo $e($filters['date_to']); ?>" />
        <button type="submit" class="button">Filter</button>
        <a href="logs.php">Reset</a>
    </p>
</form>

    <?php if ($rows === []) { ?>
        <p>No log entries<?php echo $total === 0 && array_filter($filters) !== [] ? ' match these filters' : ' yet'; ?>.</p>
    <?php } else { ?>
        <table aria-label="Activity log">
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">Event</th>
                    <th scope="col">User</th>
                    <th scope="col">IP</th>
                    <th scope="col">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $class = 'odd';
                foreach ($rows as $row) {
                    $class = ($class === 'even') ? 'odd' : 'even';
                    ?>
                    <tr class="<?php echo $class; ?>">
                        <td><?php echo $e(fu_format_utc_in_timezone((string) $row['created_at'], $siteTimezone)); ?></td>
                        <td><?php echo $e(LogService::EVENT_TYPES[$row['event_type']] ?? (string) $row['event_type']); ?></td>
                        <td><?php echo $e((string) $row['username']); ?></td>
                        <td><?php echo $e((string) $row['ip']); ?></td>
                        <td><?php echo $e((string) $row['message']); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <p class="hint small"><?php echo (int) $total; ?> entr<?php echo $total === 1 ? 'y' : 'ies'; ?>, page <?php echo $page; ?> of <?php echo $totalPages; ?>.</p>

        <?php if ($totalPages > 1) { ?>
            <p class="paginate">
                <?php if ($page > 1) { ?><a href="<?php echo $e($logsUrl(['page' => $page - 1])); ?>">&laquo; Newer</a><?php } ?>
                <strong><?php echo $page; ?></strong>
                <?php if ($page < $totalPages) { ?><a href="<?php echo $e($logsUrl(['page' => $page + 1])); ?>">Older &raquo;</a><?php } ?>
            </p>
        <?php } ?>
    <?php } ?>

    <form action="logs.php" method="post" style="margin-top:1.5rem;"
          onsubmit="return confirm('Clear the entire activity log? This cannot be undone.');">
        <?php echo $fu->csrfField(); ?>
        <button type="submit" name="action" value="clear_activity" class="delete">Clear activity log</button>
    </form>

<?php } else { ?>

<p class="hint small">
    PHP warnings, errors and uncaught exceptions recorded by FanUpdate, newest first, in
    your timezone (<?php echo $e($siteTimezone); ?>)
    (showing up to <?php echo FU_ERRORS_SHOWN; ?>; <?php echo (int) $errorLogService->count(); ?> stored).
    The file rotates automatically at 512&nbsp;KB.
</p>

    <?php if ($errorRows === []) { ?>
        <p>No errors recorded.</p>
    <?php } else { ?>
        <table aria-label="Error log">
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">Level</th>
                    <th scope="col">Message</th>
                    <th scope="col">Location</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $class = 'odd';
                foreach ($errorRows as $row) {
                    $class = ($class === 'even') ? 'odd' : 'even';
                    ?>
                    <tr class="<?php echo $class; ?>">
                        <td><?php echo $e(fu_format_utc_in_timezone($row['time'], $siteTimezone)); ?></td>
                        <td><?php echo $e($row['level']); ?></td>
                        <td><?php echo $e($row['message']); ?></td>
                        <td><?php echo $e($row['file']); ?><?php echo $row['line'] > 0 ? ':' . $row['line'] : ''; ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <form action="logs.php?tab=errors" method="post" style="margin-top:1.5rem;"
              onsubmit="return confirm('Clear the error log? This cannot be undone.');">
            <?php echo $fu->csrfField(); ?>
            <button type="submit" name="action" value="clear_errors" class="delete">Clear error log</button>
        </form>
    <?php } ?>

<?php }

$fu->getFooter();
