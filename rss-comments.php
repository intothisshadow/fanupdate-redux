<?php
declare(strict_types=1);

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Generates the RSS 2.0 feed of comments, either site-wide or for a single
 * blog entry (when ?id= is supplied).
 ******************************************************************************/

require_once('blog-config.php');
require_once('functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();

require_once('class/FeedWriter.php');
$feed = new FeedWriter(RSS2);

if (isset($_GET['id'])) {

    $id = (int)$_GET['id'];

    $query = 'SELECT b.* FROM ' . $fu->getOpt('blog_table') . ' b WHERE b.entry_id = ? LIMIT 1';

    $fu->db->Execute($query, 'Could not execute query.', [$id]);
    $row = $fu->db->GetRecord();
    // getOpt('blog_page') returns mixed; FanUpdate_Post expects ?string.
    $post = new FanUpdate_Post($row, $fu, $fu->getOpt('blog_page') ?: null);

    $feed->setTitle($fu->getOpt('site_name') . ': Comments on ' . $post->getTitle());
    $feed->setLink($post->getCommentsUrl());
    $feed->setDescription('The latest comments on "' . $post->getTitle() . '" from ' . $fu->getOpt('site_name') . '.');

    $query = 'SELECT c.*
    FROM ' . $fu->getOpt('comments_table') . ' c
    WHERE c.entry_id = ? AND c.approved > 0
    ORDER BY c.added DESC LIMIT 20';
    $query_params = [$id];

    $query_added = 'SELECT c.added
	FROM ' . $fu->getOpt('comments_table') . ' c
	WHERE c.entry_id = ? AND c.approved > 0
	ORDER BY c.added DESC LIMIT 1';
    $query_added_params = [$id];

} else {

    $feed->setTitle($fu->getOpt('site_name') . ': Comments');
    $feed->setLink((string)($fu->getOpt('blog_page') ?: ''));
    $feed->setDescription('The latest comments from ' . $fu->getOpt('site_name') . '.');

    $query = 'SELECT c.*
    FROM ' . $fu->getOpt('comments_table') . ' c
    WHERE c.approved > 0
    ORDER BY c.added DESC LIMIT 20';
    $query_params = [];

    $query_added = 'SELECT c.added
	FROM ' . $fu->getOpt('comments_table') . ' c
	WHERE c.approved > 0
	ORDER BY c.added DESC LIMIT 1';
    $query_added_params = [];
}

$fu->db->Execute($query_added, 'Could not execute query.', $query_added_params);
if ($added = $fu->db->GetFirstCell()) {
    // date('r', ...) produces RFC-2822 format required by the RSS spec.
    $feed->setChannelElement('pubDate', date('r', strtotime($added . ' UTC') ?: time()));
}

// lastBuildDate = time this feed document was generated (always "now").
$feed->setChannelElement('lastBuildDate', date('r'));

$feed->setChannelElement('language', 'en-us');
$feed->setAuthor(
    (string)($fu->getOpt('admin_email') ?: ''),
    (string)($fu->getOpt('site_name') ?: '')
);
$feed->setChannelElement('generator', 'FanUpdate ' . $fu->getOpt('version'));

// Pass full namespace URI — addNamespace() requires both prefix and URI.
$feed->addNamespace('content', 'http://purl.org/rss/1.0/modules/content/');

$fu->db->Execute($query, 'Could not execute query.', $query_params);

while ($row = $fu->db->ReadRecord()) {

    // getOpt('blog_page') returns mixed; FanUpdate_Comment expects ?string.
    $cmt = new FanUpdate_Comment($row, $fu, $fu->getOpt('blog_page') ?: null);

    $item = $feed->createNewItem();

    $item->setTitle($cmt->getAbstract());
    $item->setLink($cmt->getLinkUrl(), true);
    $item->setDate($cmt->getDate() . ' GMT');
    $item->setDescription(strip_tags($cmt->getBody()));
    $item->addElement('content:encoded', $cmt->getBodyFormatted());
    $item->setAuthor($cmt->getEmail(), $cmt->getName());

    $feed->addItem($item);
}

$feed->generateFeed();
