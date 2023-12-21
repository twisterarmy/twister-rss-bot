<?php

// Load dependencies
require __DIR__ . '/../../vendor/autoload.php';

// Load config
$config = json_decode(
    file_get_contents(
        __DIR__ . '/../../config.json'
    )
);

// Connect twister
try
{
    $twister = new \Twisterarmy\Twister\Client(
        $config->twister->protocol,
        $config->twister->host,
        $config->twister->port,
        $config->twister->username,
        $config->twister->password
    );
}

catch (Exception $e)
{
    var_dump(
        $e->getMessage()
    );

    exit;
}

// Connect DB
try
{
    @mkdir(
        sprintf(
            '%s/../../storage',
            __DIR__
        )
    );

    $database = new PDO(
        sprintf(
            'sqlite:%s',
            sprintf(
                '%s/../../storage/%s',
                __DIR__,
                $config->sqlite->database
            )
        ),
        $config->sqlite->username,
        $config->sqlite->password
    );

    $database->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_OBJ
    );
}

catch (Exception $e)
{
    var_dump(
        $e->getMessage()
    );

    exit;
}

// Collect feeds data
foreach ($config->feed as $feed)
{
    // Create account database if not exists
    $database->query(
        sprintf(
            'CREATE TABLE IF NOT EXISTS "%s"
            (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL CHECK("id" >= 0),
                "hash" CHAR(32) NOT NULL,
                "message" VARCHAR(256) NOT NULL,
                "time" INTEGER NOT NULL,
                "sent" INTEGER NULL,
                CONSTRAINT "UNIQUE"
                UNIQUE("hash")
            )',
            $feed->target
        )
    );

    // Save feed to the database pool
    foreach ((array) \Twisterarmy\Twister\Tools\Rss::feed($feed->source) as $data)
    {
        // Generate record hash
        $hash = md5(
            $data['message']
        );

        // Check record not exist
        $query = $database->prepare(
            sprintf(
                'SELECT COUNT(*) AS `total` FROM %s WHERE `hash` = ?',
                $feed->target
            )
        );

        $query->execute(
            [
                $hash
            ]
        );

        if ($query->fetch()->total)
        {
            continue;
        }

        // Add new record
        $query = $database->prepare(
            sprintf(
                'INSERT INTO %s (`hash`, `message`, `time`) VALUES (?, ?, ?)',
                $feed->target
            )
        );

        $query->execute(
            [
                $hash,
                $data['message'],
                $data['time'],
            ]
        );
    }

    // Process messages queue by time ASC
    $query = $database->query(
        sprintf(
            'SELECT `id`, `message` FROM %s WHERE `sent` IS NULL ORDER BY `time` ASC LIMIT %s',
            $feed->target,
            $feed->queue->limit,
        )
    );

    // Apply keywords
    $search = [];
    foreach ($feed->keywords as $keyword)
    {
        $search[] = sprintf(
            ' %s', // make sure keyword is not a part of another construction by separator (e.g. URL)
            $keyword
        );
    }

    $replace = [];
    foreach ($feed->keywords as $keyword)
    {
        $replace[] = sprintf(
            ' #%s',
            $keyword
        );
    }

    // Send each message to the twister account
    foreach ($query->fetchAll() as $queue)
    {
        // Get post k
        if (null === $posts = $twister->getPosts([$feed->target], 1))
        {
            echo sprintf(
                _('Could not receive twister posts for "%s" %s'),
                $feed->target,
                PHP_EOL
            );

            continue;
        }

        if (isset($posts['result'][0]['userpost']['k']))
        {
            $k = (int) $posts['result'][0]['userpost']['k'] + 1;
        }

        else
        {
            $k = 1; // initial post
        }

        // Apply replacements
        $message = str_replace(
            $search,
            $replace,
            $queue->message
        );

        // Keep message original on length limit reached
        if (mb_strlen($message) > 256)
        {
            $message = $queue->message;
        }

        $errors = [];

        $twister->newPostMessage(
            $feed->target,
            $k,
            $message,
            $errors
        );

        if ($errors)
        {
            var_dump(
                $errors
            );

            continue;
        }

        // Update time sent on success
        $database->query(
            sprintf(
                'UPDATE %s SET `sent` = %s WHERE `id` = %s LIMIT 1',
                $feed->target,
                time(),
                $queue->id
            )
        );

        // Apply delay
        sleep(
            $feed->queue->delay
        );
    }
}
