<?php
declare(strict_types=1);
// fanupdate/index.php

// Only a still-present install.php can mean "not installed yet", so a
// finished install skips all of this and costs one is_file().
if (is_file(__DIR__ . '/install.php')) {
    require_once(__DIR__ . '/class/Service/InstallDetector.php');

    $config = null;
    if (is_file(__DIR__ . '/blog-config.php')) {
        require_once(__DIR__ . '/blog-config.php');
        $config = $fanupdate ?? [];
    }

    if ((new InstallDetector())->needsInstaller(__DIR__, $config)) {
        // Temporary redirect so browsers don't cache "front page -> installer".
        header('Location: install.php', true, 302);
        exit;
    }
}

header('Location: admin/index.php', true, 301);
exit;
