#!/usr/local/bin/php
<?php

$podcast_storage = "{$_SERVER['HOME']}/storage/Audio/Podcasts";
$poddie_config_file = "{$_SERVER['HOME']}/.poddie.feeds";
$poddie_log_fetched = "{$_SERVER['HOME']}/.poddie.fetched";
$poddie_id3tag_bin = "/usr/local/bin/id3tag";

$poddie_already_fetched = file_exists($poddie_log_fetched) ? file_get_contents($poddie_log_fetched) : "";
$downloaded_files_count = 0;

$poddie_config = file($poddie_config_file);

foreach($poddie_config as $podcast_feed) {
    $episodes_kept = 0;
    list($podcast_title, $podcast_url, $episodes_to_keep) = explode(';', trim($podcast_feed));
    if(!file_exists("$podcast_storage/$podcast_title")) {
        echo "New podcast subscription detected: $podcast_title.\n";
        exec("mkdir -p '$podcast_storage/$podcast_title'");
    }
    foreach(simplexml_load_string(file_get_contents(trim($podcast_url)))->channel->item as $item) {
        if(++$episodes_kept >= $episodes_to_keep) break;
        $url = (string) $item->enclosure['url'];
	$episode_title_filename_extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $episode_title_filename = str_replace('..', '.', str_replace("  ", " ", date('Y-m-d', strtotime((string) $item->pubDate)) . " - " .  preg_replace("/[^a-zæøåA-ZÆØÅ0-9.\-]/", " ", (string) $item->title) . ".$episode_title_filename_extension"));
        if($url != '' && strpos($poddie_already_fetched, $url) === false) {
            echo "Fetching '$url' into '$podcast_storage/$podcast_title/$episode_title_filename'\n";
            exec("fetch -q -o '$podcast_storage/$podcast_title/$episode_title_filename' '$url'");
            $id3tag = substr($episode_title_filename, 0, strrpos($episode_title_filename, '.'));
            exec("$poddie_id3tag_bin --song='$id3tag' '$podcast_storage/$podcast_title/$episode_title_filename'");
            log_fetched($url);
            $downloaded_files_count++;
        }
    }
    
    $downloaded_files = scan_dir("$podcast_storage/$podcast_title");
    for($index = intval($episodes_to_keep); $index <= count($downloaded_files) - 1; $index++) {
        $file_to_remove = "$podcast_storage/$podcast_title/{$downloaded_files[$index]}";
        echo "Removing $index from $podcast_title ($file_to_remove)\n";
        unlink($file_to_remove);
    }
}

$number_of_podcasts = count($poddie_config);
if ($downloaded_files_count > 0) echo "Downloaded $downloaded_files_count files from $number_of_podcasts podcast feeds.\n";


function scan_dir($dir) {
    $ignored = array('.', '..', '.svn', '.htaccess');

    $files = array();    
    foreach (scandir($dir) as $file) {
        if (in_array($file, $ignored)) continue;
        $files[$file] = filemtime($dir . '/' . $file);
    }

    arsort($files);
    $files = array_keys($files);

    return ($files) ? $files : false;
}

function log_fetched($podcast_url) {
    global $poddie_log_fetched;
    $logfile = fopen($poddie_log_fetched, 'a');
    fwrite($logfile, "$podcast_url\n");
    fclose($logfile);
}

?>