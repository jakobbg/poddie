#!/usr/local/bin/php
<?php

poddie_setup();

$poddie_already_fetched = file_exists(PODDIE_FETCHED_LOGFILE) ? file_get_contents(PODDIE_FETCHED_LOGFILE) : "";
$downloaded_files_count = 0;

$poddie_config = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", file(PODDIE_FEEDS_FILE));

foreach($poddie_config as $podcast_feed) {
    $episodes_kept = 0;
    list($podcast_title, $podcast_url, $episodes_to_keep) = explode(';', trim($podcast_feed));

    if(!is_podcast_feed_alive($podcast_url)) {
        echo "$podcast_title ($podcast_url) does not exist. Skipping.\n";
        break;
    }

    $podcast_simplexml = simplexml_load_string(file_get_contents(trim($podcast_url)));
    if(!$podcast_simplexml) {
        echo "$podcast_title ($podcast_url) is not providing valid XML. Skipping.\n";
        break;
    }

    if(!file_exists(PODDIE_PODCAST_STORAGE . "/$podcast_title")) {
        echo "New podcast subscription detected: $podcast_title.\n";
        exec("mkdir -p '" . PODDIE_PODCAST_STORAGE . "/$podcast_title'");
    }

    foreach($podcast_simplexml->channel->item as $item) {
        if(++$episodes_kept >= $episodes_to_keep) break;
        $url = (string) $item->enclosure['url'];
        $episode_title_filename_extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $episode_title_filename = date('Y-m-d', strtotime((string) $item->pubDate)) . " - " . sanitize_filename(remove_timestamp((string) $item->title)) . ".$episode_title_filename_extension";
        if($url != '' && (!file_exists(PODDIE_PODCAST_STORAGE . "/$podcast_title/$episode_title_filename")) && strpos($poddie_already_fetched, $url) === false) {
            echo "Fetching '$url' into '" . PODDIE_PODCAST_STORAGE . "/$podcast_title/$episode_title_filename'\n";
            download($url, PODDIE_PODCAST_STORAGE . "/$podcast_title/$episode_title_filename");
            $id3tag = substr($episode_title_filename, 0, strrpos($episode_title_filename, '.'));
            exec(PODDIE_ID3TAG_BIN . " --song='$id3tag' '" . PODDIE_PODCAST_STORAGE . "/$podcast_title/$episode_title_filename'");
            log_fetched($url);
            $downloaded_files_count++;
        }
    }
    
    $downloaded_files = scan_dir(PODDIE_PODCAST_STORAGE . "/$podcast_title");
    for($index = intval($episodes_to_keep); $index <= count($downloaded_files) - 1; $index++) {
        $file_to_remove = PODDIE_PODCAST_STORAGE . "/$podcast_title/{$downloaded_files[$index]}";
        echo "Removing $index from $podcast_title ($file_to_remove)\n";
        unlink($file_to_remove);
    }
}

$number_of_podcasts = count($poddie_config);
if ($downloaded_files_count > 0) echo "Downloaded $downloaded_files_count files from $number_of_podcasts podcast feeds.\n";


function poddie_setup() {
    define("PODDIE_CONFIG_FILE", dirname($_SERVER['SCRIPT_FILENAME']) . "/poddie.config");
    define("PODDIE_FEEDS_FILE", dirname($_SERVER['SCRIPT_FILENAME']) . "/poddie.feeds");
    define("PODDIE_FETCHED_LOGFILE", dirname($_SERVER['SCRIPT_FILENAME']) . "/poddie.fetched");

    define("PODDIE_ID3TAG_BIN", get_poddie_config("id3tag"));
    define("PODDIE_PODCAST_STORAGE", get_poddie_config("podcast_storage"));
    date_default_timezone_set(get_poddie_config("timezone"));

}

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
    $logfile = fopen(PODDIE_FETCHED_LOGFILE, 'a');
    fwrite($logfile, "$podcast_url\n");
    fclose($logfile);
}

function is_podcast_feed_alive($url) {
    $handle = curl_init($url);
    curl_setopt($handle,  CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($handle);
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return ($httpCode == 200);
}

function get_poddie_config($key) {
    return parse_ini_file(PODDIE_CONFIG_FILE)[$key];
}

function download($file_source, $file_target) {
    $rh = fopen($file_source, 'rb');
    $wh = fopen($file_target, 'wb');
    if ($rh===false || $wh===false) {
        // error reading or opening file
        return false;
    }
    while (!feof($rh)) {
    if (fwrite($wh, fread($rh, 1024)) === FALSE) {
        // 'Download error: Cannot write to file ('.$file_target.')';
        return true;
        }
    }
    fclose($rh);
    fclose($wh);
    // No error
    return true;
}

function remove_timestamp($str) {
    return preg_replace('/(\d{1,2})[[:punct:]](\d{1,2})[[:punct:]](\d{2,4})/', '', $str);
}

function sanitize_filename($str) {
    $str = trim(preg_replace("/[^a-zæøåA-ZÆØÅ0-9.\-]/", " ", $str));
    $str = str_replace('..', '.', $str);
    $str = str_replace("  ", " ", $str);
    return $str;
}

?>
