<?php

$feedsFile = "feeds.json";

function loadFeeds(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $feeds = json_decode(file_get_contents($file), true);

    return is_array($feeds) ? $feeds : [];
}

function saveFeeds(string $file, array $feeds): void
{
    file_put_contents(
        $file,
        json_encode($feeds, JSON_PRETTY_PRINT)
    );
}

function sanitizeHtml(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();

    $dom->loadHTML(
        '<?xml encoding="utf-8" ?>' . $html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    libxml_clear_errors();

    $dangerousTags = [
        'script',
        'iframe',
        'object',
        'embed',
        'form',
        'input',
        'button',
        'textarea',
        'select'
    ];

    foreach ($dangerousTags as $tag) {

        while (true) {

            $nodes = $dom->getElementsByTagName($tag);

            if ($nodes->length === 0) {
                break;
            }

            $node = $nodes->item(0);

            $node->parentNode->removeChild($node);
        }
    }

    $xpath = new DOMXPath($dom);

    foreach ($xpath->query('//*') as $node) {

        if (!$node->hasAttributes()) {
            continue;
        }

        $attributes = [];

        foreach ($node->attributes as $attr) {
            $attributes[] = $attr->name;
        }

        foreach ($attributes as $name) {

            $value = $node->getAttribute($name);

            if (stripos($name, 'on') === 0) {
                $node->removeAttribute($name);
                continue;
            }

            if (
                in_array($name, ['href', 'src']) &&
                preg_match('/^\s*javascript:/i', $value)
            ) {
                $node->removeAttribute($name);
            }
        }
    }

    return $dom->saveHTML();
}

$feeds = loadFeeds($feedsFile);

$rssUrl = trim($_GET['rss'] ?? '');

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>RSS Reader</title>

<style>
body {
    margin: 0;
    font-family: sans-serif;
    display: flex;
}

aside {
    width: 260px;
    min-height: 100vh;
    background: #f2f2f2;
    padding: 1rem;
    box-sizing: border-box;
}

main {
    flex: 1;
    padding: 1rem;
}

.feed-link {
    display: block;
    margin-bottom: 8px;
    text-decoration: none;
}

.feed-link:hover {
    text-decoration: underline;
}

input[type=text] {
    width: 100%;
    box-sizing: border-box;
    padding: 8px;
}

button {
    margin-top: 8px;
    padding: 8px 16px;
}

article {
    margin-bottom: 2rem;
}

.feed-content img {
    max-width: 100%;
    height: auto;
}

.feed-content pre {
    overflow-x: auto;
}
</style>

</head>
<body>

<aside>

<h2>Saved Feeds</h2>

<?php if (empty($feeds)): ?>

<p>No saved feeds yet.</p>

<?php else: ?>

    <?php foreach ($feeds as $feed): ?>

        <a
            class="feed-link"
            href="?rss=<?= urlencode($feed['url']) ?>"
        >
            <?= htmlspecialchars($feed['title']) ?>
        </a>

    <?php endforeach; ?>

<?php endif; ?>

<hr>

<form method="GET">
    <input
        type="text"
        name="rss"
        placeholder="RSS Feed URL"
        required
    >

    <button type="submit">
        Load Feed
    </button>
</form>

</aside>

<main>

<?php

if ($rssUrl !== '') {

    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0\r\n"
        ]
    ]);

    $data = @file_get_contents(
        $rssUrl,
        false,
        $context
    );

    if ($data === false) {

        echo "<h2>Could not download RSS feed.</h2>";

    } else {

        $rss = @simplexml_load_string($data);

        if ($rss === false) {

            echo "<h2>Invalid RSS feed.</h2>";

        } else {

            $feedTitle = (string)$rss->channel->title;

            echo "<h1>" .
                htmlspecialchars($feedTitle) .
                "</h1>";

            $exists = false;

            foreach ($feeds as $feed) {

                if ($feed['url'] === $rssUrl) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {

                $feeds[] = [
                    'title' => $feedTitle,
                    'url' => $rssUrl
                ];

                saveFeeds(
                    $feedsFile,
                    $feeds
                );
            }

            foreach ($rss->channel->item as $item) {

                $title = htmlspecialchars(
                    (string)$item->title
                );

                $link = htmlspecialchars(
                    (string)$item->link
                );

                $contentEncoded = (string)
                    $item
                    ->children(
                        'http://purl.org/rss/1.0/modules/content/'
                    )
                    ->encoded;

                $body = !empty($contentEncoded)
                    ? $contentEncoded
                    : (string)$item->description;

                $body = sanitizeHtml($body);

                echo "<article>";

                echo "<h2>$title</h2>";

                echo "<div class='feed-content'>";
                echo $body;
                echo "</div>";

                echo "<p>";
                echo "<a href='$link' target='_blank'>";
                echo "Read Original Article";
                echo "</a>";
                echo "</p>";

                echo "<hr>";

                echo "</article>";
            }
        }
    }
}

?>

</main>

</body>
</html>