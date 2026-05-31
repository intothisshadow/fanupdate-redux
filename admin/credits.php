<?php
declare(strict_types=1);
/*****************************************************************************
 * FanUpdate — credits.php
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 Redux contributor
 *
 * GNU General Public License v3 or later.
 * See <https://www.gnu.org/licenses/gpl-3.0.html> for details.
 ******************************************************************************/

require_once('blog-config.php');
require_once('functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$fu->getHeader('Credits');

?>

<h2>Credits &amp; Version Info</h2>

<div class="col12">

    <h3>This installation</h3>

    <table>
        <tbody>
            <tr class="odd">
                <th scope="row">Version</th>
                <td><?php echo htmlspecialchars(FU_VERSION, ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <tr>
                <th scope="row">Release</th>
                <td><?php echo htmlspecialchars(FU_RELEASE_NAME, ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <tr class="odd">
                <th scope="row">Released</th>
                <td><?php echo htmlspecialchars(FU_RELEASE_DATE, ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <tr>
                <th scope="row">DB schema</th>
                <td><?php echo htmlspecialchars(FU_DB_VERSION, ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <tr class="odd">
                <th scope="row">PHP</th>
                <td><?php echo htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
        </tbody>
    </table>

    <h3>Contributors</h3>

    <?php foreach (FU_CONTRIBUTORS as $i => $person): ?>
    <div class="option <?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">
        <p>
            <strong>
                <?php if (!empty($person['url'])): ?>
                    <a href="<?php echo htmlspecialchars($person['url'], ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank"><?php echo htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                <?php else: ?>
                    <?php echo htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </strong>
            — <?php echo htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8'); ?>
            (<?php echo htmlspecialchars($person['years'], ENT_QUOTES, 'UTF-8'); ?>)
        </p>
        <p style="margin: 0.25em 0 0 0; color: #666; font-size: 0.9em;">
            <?php echo htmlspecialchars($person['contribution'], ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <?php if (!empty($person['email'])): ?>
        <p style="margin: 0.25em 0 0 0; font-size: 0.9em;">
            <a href="mailto:<?php echo htmlspecialchars($person['email'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($person['email'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

</div><!-- END .col12 -->

<div class="col22">

    <h3>Third-party libraries</h3>
    <p style="font-size: 0.9em; color: #666;">
        FanUpdate incorporates or is derived from the following open-source works.
        All are used with permission under their respective licences.
    </p>

    <?php foreach (FU_LIBRARIES as $i => $lib): ?>
    <div class="option <?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">
        <p>
            <strong>
                <a href="<?php echo htmlspecialchars($lib['url'], ENT_QUOTES, 'UTF-8'); ?>"
                   target="_blank"><?php echo htmlspecialchars($lib['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </strong>
            — &copy; <?php echo htmlspecialchars($lib['years'], ENT_QUOTES, 'UTF-8'); ?>
            <?php echo htmlspecialchars($lib['author'], ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <p style="margin: 0.25em 0 0 0; color: #666; font-size: 0.9em;">
            <?php echo htmlspecialchars($lib['used_in'], ENT_QUOTES, 'UTF-8'); ?>
        </p>
    </div>
    <?php endforeach; ?>

    <h3>Licence</h3>

    <p>
        FanUpdate is free software released under the
        <a href="<?php echo htmlspecialchars(FU_LICENCE_URL, ENT_QUOTES, 'UTF-8'); ?>"
           target="_blank"><?php echo htmlspecialchars(FU_LICENCE, ENT_QUOTES, 'UTF-8'); ?></a>.
    </p>

    <p style="font-size: 0.9em; color: #666;">
        Third-party class files (SqlConnection, FeedWriter, FeedItem) retain
        their original authors' licence declarations and are not affected by
        this project's licence upgrade.
    </p>

</div><!-- END .col22 -->

<?php $fu->getFooter(); ?>
