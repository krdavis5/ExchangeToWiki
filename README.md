# ExchangeToWiki
MediaWiki extension that uses php-ews to connect to Exchange Web Services to read emails in as Wiki pages.

Emails must be prefixed with either of two configurable prefixes. Out of the box, `WikiPage` prefix causes an email to be read in as raw text and `PandocPage` prefix causes an email to be ran through Pandoc to be converted from HTML to Wikitext.

Additionally a pin number helps prevent unwanted emails from being parsed. This is configurable in config.php.

An example email with subject `WikiPage 99999 My Subject here` will create a Wiki page titled 'My Subject here' or overwrite it if it exists. The contents will be the raw text of the body of the email.


## Prerequisites
* Composer
* Pandoc


## Configuration and Installation
1. Rename config.php.conf to config.php
2. Fill out options in config.php for both your Wiki installation and your Exchange account, also setting a pin number for your emails.
3. Make a directory in the extension folder named 'ExchangeToWiki.tmp' and change permissions to 0755
4. Change permissions of ExchangeToWiki.log to 0755
5. Run composer install
6. Add `wfLoadExtension( 'ExchangeToWiki' );` to your LocalSettings.php file
7. Add a cronjob to run EmailInterface.php at your desired interval, i.e.:

```0-59/5 * * * * root /usr/bin/php /opt/meza/htdocs/mediawiki/extensions/ExchangeToWiki/EmailInterface.php```

This will cause the script to check your inbox every 5 minutes for a new email.
