<?php
require "vendor/autoload.php";

use GuzzleHttp\Client;

// KOMGA API
$client = new GuzzleHttp\Client(['base_uri' => 'https://url-to-komga/api/v1/', 'auth' => ['komga-username', 'komga-password']]);
$logFile = 'progress';

$basePath = '/mnt/user/Manga';

// YacServer Library Paths - folder name is used to map to komga library
$libraries = [
    $basePath . '/action',
    $basePath . '/drama',
    $basePath . '/fantasy',
    $basePath . '/romance',
    $basePath . '/sol',
    $basePath . '/seinen',
    $basePath . '/adventure',
    $basePath . '/webshows',
    $basePath . '/manhwa',
];

$komgaLibraryMap = [
    'action' => '02014MR2Q40SF',
    'adventure' => '02014PJDK491P',
    'drama' => '02014RJSV4EXP',
    'fantasy' => '02014WHMV4677',
    'manhwa' => '02014Z5WK404V',
    'romance' => '020151SDB4EBV',
    'seinen' => '020154DQB41GE',
    'sol' => '02015675V41KZ',
    'webshows' => '020157PR747RB',
];

// Try to open the DB first to wakeup the disk(s) if they are in standby in Unraid
$db = new SQLite3($basePath . '/action/.yacreaderlibrary/library.ydb');
sleep(30);
$db->close();
unset($db);

foreach ($libraries as $directory) {
    $dirParts = explode('/', $directory);
    $genre = $dirParts[4];

    echo "Syncing ".$genre."....";

    // Copy library file to scripts working path for simplicity
    copy($directory . '/.yacreaderlibrary/library.ydb', './library.ydb');
    $db = new SQLite3('./library.ydb');

    // Delete existing reading lists
    $db->query('DELETE FROM reading_list');
    $db->query('DELETE FROM comic_reading_list');

    // Generate Recently Added Series reading list for each library in YacReader
    $db->exec("INSERT INTO reading_list(id,name) VALUES (1,'Recently Added Series')");
    $recentlyAddedId = $db->lastInsertRowID();
    $response = $client->request('GET', 'series', [
        'query' => [
            'size' => 20,
            'library_id' => $komgaLibraryMap[$genre],
            'sort' => 'created,desc'
        ]
    ]);
    $komgaCollection = json_decode($response->getBody(), true);
    $count=0;
    foreach ($komgaCollection['content'] as $series) {
        $count++;
        $dbid = $db->querySingle('SELECT id FROM folder where path = "' . str_replace('/books/'.$genre,'',$series['url']) . '"');
        $comicId = $db->querySingle('SELECT id FROM comic WHERE parentId = "' . $dbid . '" ORDER BY id ASC LIMIT 1');

        $statement = $db->prepare('INSERT INTO comic_reading_list (reading_list_id,comic_id,ordering) VALUES (1,:comicId,:sort)');
        $statement->bindValue(':comicId', $comicId);
        $statement->bindValue(':sort', $count);
        $statement->execute();
    }

    // Generate Recently Added Chapters reading list for each library in YacReader
    $db->exec("INSERT INTO reading_list(id,name) VALUES (2,'Recently Added Chapters')");
    $recentlyAddedId = $db->lastInsertRowID();
    $response = $client->request('GET', 'books', [
        'query' => [
            'size' => 20,
            'library_id' => $komgaLibraryMap[$genre],
            'sort' => 'created,desc'
        ]
    ]);
    $komgaCollection = json_decode($response->getBody(), true);
    $count = 0;
    foreach ($komgaCollection['content'] as $series) {
        $count++;
        $comicId = $db->querySingle('SELECT id FROM comic WHERE path = "' . str_replace('/books/'.$genre,'',$series['url']) . '"');

        $statement = $db->prepare('INSERT INTO comic_reading_list (reading_list_id,comic_id,ordering) VALUES (2,:comicId,:sort)');
        $statement->bindValue(':comicId', $comicId);
        $statement->bindValue(':sort', $count);
        $statement->execute();
    }

    // loop through all the books in komga and update reading status in YacReader.  Whilst also checking the reading status in YacReader and if different update the status in komga
    $process = true;
    $page = 0;
    while ($process) {
        $response = $client->request('GET', 'books', [
            'query' => [
                'library_id' => $komgaLibraryMap[$genre],
                'page' => $page,
                'size' => 100
            ]
        ]);
        $komgaBooks = json_decode($response->getBody(), true);

        // process read status
        foreach ($komgaBooks['content'] as $book) {
            // lets lookup the book in yacreader
            $comicYacName = str_replace('/books/' . $genre, '', $book['url']);
            if ($genre === 'manhwa' && strpos($comicYacName, 'Fallen') !== false) {
                echo 'searching for ' . $comicYacName . "\r\n";
            }
            $statement = $db->prepare('select comic.id as comicId, comic_info.id as comicInfoId, comic_info.read,comic_info.currentPage, comic_info.numPages from comic INNER JOIN comic_info ON comic.comicInfoId = comic_info.id  WHERE path= :comic');
            $statement->bindValue(':comic', $comicYacName);
            $result = $statement->execute();
            $yacBook = $result->fetchArray();

            if (is_array($book['readProgress'])) {
                // komga has some read status on it so lets compare to yacreader
                if ($book['readProgress']['completed']) {
                    if (!$yacBook['read']) {
                        // book is completed in komga
                        $statement = $db->prepare('UPDATE comic_info SET read=1,currentPage=:currentPage WHERE id=:comicInfoId');
                        $statement->bindValue(':currentPage', $book['readProgress']['page']);
                        $statement->bindValue(':comicInfoId', $yacBook['comicInfoId']);
                        $statement->execute();
                        //logger('Marking ' . $book['name'] . ' as completed in YacServer', $logFile);
                    }
                } else {
                    if ($book['readProgress']['page'] > $yacBook['currentPage']) {
                        // more progress in komga
                        $statement = $db->prepare('UPDATE comic_info SET currentPage=:currentPage WHERE id=:comicInfoId');
                        $statement->bindValue(':currentPage', $book['readProgress']['page']);
                        $statement->bindValue(':comicInfoId', $yacBook['comicInfoId']);
                        $statement->execute();
                        //logger('Updating read progress of '.$book['name'] . ' in YacServer', $logFile);
                    } elseif ($book['readProgress']['page'] < $yacBook['currentPage']) {
                        // more progress in yacreader
                        try {
                            $response = $client->request('PATCH', 'books/'.$book['id'].'/read-progress', [
                                'json' => [
                                    'completed' => false,
                                    'page' => $yacBook['currentPage'],
                                ]
                            ]);
                            //logger('Updating read progress of '.$book['name'] . ' in Komga', $logFile);
                        } catch (Exception $e) {
                            $cpage = $yacBook['currentPage'];
                            echo "Error updating book reading status [".$book['id']."]|[".$cpage."]\n";
                            //logger('Error updating read progress of '.$book['name'] . ' in komga [id:'.$book['id'].'|page:'.$cpage.']', $logFile);
                        }
                    }
                }

                // compare see which has highest page read and sync that to the other
            } else {
                // No read status in komga, lets check yacready and sync to komga
                if ($yacBook['read'] === 1 || $yacBook['currentPage'] !== 1) {
                    if ($yacBook['numPages'] === $yacBook['currentPage'] || $yacBook['read'] === 1) {
                        // mark as completed
                        try {
                            $response = $client->request('PATCH', 'books/'.$book['id'].'/read-progress', [
                                'json' => [
                                    'completed' => 'true'
                                ],
                                'headers' => [
                                    'Content-Type' => 'application/json'
                                ]
                            ]);
                            //logger('Marking '.$book['name'] . ' as completed in komga', $logFile);
                        } catch (Exception $e) {
                            //logger('Error marking book as complete '.$book['name'] . ' in komga [id:'.$book['id'].']', $logFile);
                            echo "Error marking book as complete [".$book['id']."][".$e->getMessage()."]\n";
                        }
                    } else {
                        try {
                            $response = $client->request('PATCH', 'books/'.$book['id'].'/read-progress', [
                                'json' => [
                                    'completed' => 'false',
                                    'page' => $yacBook['currentPage']
                                ],
                                'headers' => [
                                    'Content-Type' => 'application/json'
                                ]
                            ]);
                            //logger('Updated '.$book['name'] . ' progress in komga', $logFile);
                        } catch (Exception $e) {
                            //logger('Error updating progress of '.$book['name'] . ' in komga [id:'.$book['id'].']', $logFile);
                            echo "Error marking book as complete book [".$book['id']."][".$e->getMessage()."]\n";
                        }
                    }
                }
            }
        }

        // go to next page if there is one
        if ($page === ($komgaBooks['totalPages']-1)) {
            $process = false;
        } else {
            $page++;
        }
    }

    // Close and save yacserver databases
    $db->close();

    // back up library
    copy($directory . '/.yacreaderlibrary/library.ydb', $directory . '/.yacreaderlibrary/library.backup.'.time().'.ydb');
    unlink($directory . '/.yacreaderlibrary/library.ydb');
    sleep(2);
    copy('./library.ydb', $directory . '/.yacreaderlibrary/library.ydb');
    unlink('./library.ydb');
    echo "Finished\n";
    flush();

    // Clean up backups (only keep last 7 days)
    $days = 7;
    $dirName = $directory . '/.yacreaderlibrary';
    if (is_dir($dirName)) {
        foreach (new DirectoryIterator($dirName) as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->isDir() || strpos($fileInfo->getFilename(), 'library.backup.') === false) {
                continue;
            }
            if (time() - $fileInfo->getCTime() > ($days * 86400)) {
                unlink($fileInfo->getRealPath());
            }
        }
    }
}

//logger(date('r') . ' Updated progress', $logFile);
