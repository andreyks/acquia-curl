# acquia-curl

## Installation

1. Download ZIP, extract
2. $ cd acquia-curl-master
3. Edit acquia-curl.ini. Set valid email and token
4. Update domain cache "./acquia-curl.php reset"
5. Execute "./acquia-curl.php" to view usage

## Commands

Build domain cache. Run it in first and after domain add/delete. Usage: ./acquia-curl.php reset

View current cache. Usage: ./acquia-curl.php view

Reset varnish for domains. Usage: ./acquia-curl.php varnish domain1 domain2 ...

Execute "./acquia-curl.php" to view available commands

## Roadmap

Cover most used command from acquia cloud api https://cloudapi.acquia.com/