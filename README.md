# komga-yacreader-progress-sync

## What is it?

This PHP script will edit the YACReader SQLite databases directly and use the Komga API to sync reading progress between the two applications.  
This is a two sync so changes in Komga will be carried over to YACReader and vice versa.

It will also create a few reading lists for each library in YACReader that attempt to mimic the Recently Added Books and Recently Updated Series lists from Komga.

This assumes you are using Docker for these apps or at the very least the path to the books configured in these apps match and both are set to /books

For example, YacServer has my comics mounted in a docker mount of /books as does Komga.  Some file system matching needs to be used for Yacreader lookups

## How to use

 * Edit progress.php (Update Komga URL, username, password, base path and edit the folder mapping and komga library ids)
 * Install Composer (https://getcomposer.org/)
 * Run `composer install`
 * Run the script `php -f progress.php`

## Licence

This script is provided as is, very little if any support will be offered for it.  Therefore, feel free to modify and reuse this however you wish.
