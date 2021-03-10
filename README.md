# komga-yacreader-progress-sync

## What is it?

This PHP script will edit the YACReader SQLite databases directly and use the Komga API to sync reading progress between the two applications.  
This is a two sync so changes in Komga will be carried over to YACReader and vice versa.

It will also create a few reading lists for each library in YACReader that attempt to mimic the Recently Added Books and Recently Updated Series lists from Komga.

## How to use

 * Edit progress.php (Update Komga URL, username, password, base path and edit the folder mapping and komga library ids)
 * run `composer install`
 * run the script `php -f progress.php`

## Licence

This script is provided as is, very little if any support will be offered for it.  Therefore, feel free to modify and reuse this however you wish.