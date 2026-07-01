<?php
declare(strict_types=1);

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Bug fix (Redux 2.0.1):
 *  - RSS channel pubDate and lastBuildDate were set via setPubDate() /
 *    setBuildDate() which do not exist on FeedWriter. The calls raised
 *    fatal errors, silently breaking all RSS feeds. Fixed: replaced both
 *    with setChannelElement('pubDate', ...) / setChannelElement('lastBuildDate', ...).
 *    date('r', ...) produces the RFC-2822 format RSS readers expect.
 *  - addNamespace() calls passed only one argument; the method requires two
 *    (prefix + URI). Added the explicit URI for 'content', 'slash', and 'wfw'.
 *  - getOpt() returns mixed; FeedWriter::setAuthor() and FanUpdate_Post::__construct()
 *    expect typed parameters. Added (string)(... ?: '') / (... ?: null) casts.
 *
 * Redux 1.5.1 fix: replaced DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL X HOUR)
 * with UTC_TIMESTAMP(). See show-blog.php for full explanation.
 ******************************************************************************/

require_once('blog-config.php');
require_once('functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();

require_once('class/FeedWriter.php');
$feed = new FeedWriter(RSS2);

require_once('class/FileHandler.php');
$fh = new FileHandler();

if (isset($_GET['c'])) {

    $c = (int)$_GET['c'];

    if ($c === 0) {
        $subject = 'Collective';
    } else {
        $query_cat = 'SELECT c.' . $fu->getOpt('col_subj') . ' AS cat_name
        FROM ' . $fu->getOpt('catjoin_table') . ' j
        JOIN ' . $fu->getOpt('collective_table') . ' c ON j.cat_id=c.' . $fu->getOpt('col_id') . '
        WHERE j.cat_id = ?';

        $subject = $fu->db->GetFirstCell($query_cat, [$c]);
    }

    $feed->setTitle($fu->getOpt('site_name') . ': ' . $subject);
    $feed->setLink($fu->getOpt('blog_page') . '?c=' . $c);
    $feed->setDescription('The latest updates for ' . $subject . ' from ' . $fu->getOpt('site_name') . '.');

    $query = 'SELECT b.*, COUNT(c.comment_id) AS num_comments
    FROM ' . $fu->getOpt('blog_table') . ' b
    JOIN ' . $fu->getOpt('catjoin_table') . ' j ON b.entry_id=j.entry_id
    LEFT JOIN ' . $fu->getOpt('comments_table') . ' c ON b.entry_id=c.entry_id
    WHERE b.is_public > 0 AND b.added <= UTC_TIMESTAMP() AND j.cat_id = ?
    GROUP BY b.entry_id
    ORDER BY b.added DESC LIMIT 20';
    $query_params = [$c];

    $query_added = 'SELECT b.added
    FROM ' . $fu->getOpt('blog_table') . ' b
    JOIN ' . $fu->getOpt('catjoin_table') . ' j ON b.entry_id=j.entry_id
    WHERE b.is_public > 0 AND b.added <= UTC_TIMESTAMP() AND j.cat_id = ?
    ORDER BY b.added DESC LIMIT 1';
    $query_added_params = [$c];

} else {

    $feed->setTitle((string)($fu->getOpt('site_name') ?: ''));
    $feed->setLink((string)($fu->getOpt('blog_page') ?: ''));
    $feed->setDescription('The latest updates from ' . $fu->getOpt('site_name') . '.');

    $query = 'SELECT b.*, COUNT(c.comment_id) AS num_comments
    FROM ' . $fu->getOpt('blog_table') . ' b
    LEFT JOIN ' . $fu->getOpt('comments_table') . ' c ON b.entry_id=c.entry_id
    WHERE b.is_public > 0 AND b.added <= UTC_TIMESTAMP()
    GROUP BY b.entry_id
    ORDER BY b.added DESC LIMIT 20';
    $query_params = [];

    $query_added = 'SELECT b.added
    FROM ' . $fu->getOpt('blog_table') . ' b
    WHERE b.is_public > 0 AND b.added <= UTC_TIMESTAMP()
    ORDER BY b.added DESC LIMIT 1';
    $query_added_params = [];
}

$fu->db->Execute($query_added, 'Could not execute query.', $query_added_params);
if ($added = $fu->db->GetFirstCell()) {
    // date('r', ...) produces RFC-2822 format required by the RSS spec.
    // strtotime(...' UTC') ensures the stored UTC timestamp is parsed correctly.
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

// Pass full namespace URIs — addNamespace() requires both prefix and URI.
$feed->addNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
$feed->addNamespace('slash',   'http://purl.org/rss/1.0/modules/slash/');
$feed->addNamespace('wfw',     'http://wellformedweb.org/CommentAPI/');

$fu->db->Execute($query, 'Could not execute query.', $query_params);

while ($row = $fu->db->ReadRecord()) {

    // getOpt('blog_page') returns mixed; FanUpdate_Post expects ?string.
    $post = new FanUpdate_Post($row, $fu, $fu->getOpt('blog_page') ?: null);

    $html_text = $post->getBodyFormatted();
    $html_text = str_replace(
        ['href="/', 'src="/'],
        ['href="' . $_SERVER['SERVER_NAME'] . '/', 'src="' . $_SERVER['SERVER_NAME'] . '/'],
        $html_text
    );

    $item = $feed->createNewItem();

    $item->setTitle($post->getTitle());
    $item->setAuthor(
        (string)($fu->getOpt('admin_email') ?: ''),
        (string)($fu->getOpt('site_name') ?: '')
    );
    $item->setLink($post->getUrl(), true);
    $item->setDate($post->getDate() . ' GMT');
    $item->setDescription(strip_tags($post->getBody()));
    $item->addElement('content:encoded', $html_text);

    if ($post->allowComments()) {
        $item->addElement('comments', $post->getCommentsUrl());
        $item->addElement('wfw:commentRss', $post->getCommentsFeedUrl());
        if ($row['num_comments'] > 0) {
            $item->addElement('slash:comments', $row['num_comments']);
        }
    }

    // find enclosures
    preg_match_all(
        '/<a\s[^>]*href=(\"??)(([^\" >]*?)\.(mov|m4a|mp4|m4v|zip|mp3|pdf))\\1[^>]*>(.*)<\/a>/siU',
        $html_text,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $url      = $match[2];
        $filename = basename($match[2]);
        $type     = $fh->getMimeType($filename);
        $size     = 0;
        $url_p    = parse_url($url);
        $filepath = $_SERVER['DOCUMENT_ROOT'] . ($url_p['path'] ?? '');
        if (file_exists($filepath)) {
            $size = $fh->getSize($filepath);
        }
        $item->setEnclosure($url, $size, $type);
    }

    $feed->addItem($item);
}

$feed->generateFeed();
