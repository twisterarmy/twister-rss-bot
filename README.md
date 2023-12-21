# twister-rss-bot-php

RSS Bot for Twister P2P

## Requirements

* `php-8.2`
* `php-curl`
* `php-mbstring`
* `php-pdo`
* `php-sqlite3`

## Install

### Production

* `composer create-project twisterarmy/twister-rss-bot`

### Development

* `git clone https://github.com/twisterarmy/twister-rss-bot-php.git`
* `cd twister-rss-bot-php`
* `composer install`

## Config

* `cp config.example.json config.json`
* `nano config.json` add twister connection and RSS feeds

## Usage

* `@hourly php src/cli/bot.php`