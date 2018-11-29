<?php

poddie_setup();

$poddie_already_fetched = file_exists(PODDIE_FETCHED_LOGFILE) ? file_get_contents(PODDIE_FETCHED_LOGFILE) : "";
$downloaded_files_count = $poddie_config_line_number = 0;
$poddie_config = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", file(PODDIE_FEEDS_FILE));

foreach($poddie_config as $poddie_config_line) {
    $poddie_config_line_number++;
    $episodes_kept = 0;

    if(empty(trim($poddie_config_line))) {
        continue; //silently into the night
    }

    if(!is_valid_poddie_config_line($poddie_config_line)) {
        echo "Feed config '$poddie_config' at line $poddie_config_line_number is not valid. Skipping.\n";
        continue;
    }

    list($podcast_title, $podcast_url, $episodes_to_keep) = explode(';', trim($poddie_config_line));

    if(!is_feed_alive($podcast_url)) {
        echo "$podcast_title ($podcast_url) does not exist. Skipping.\n";
        continue;
    }

    echo "Processing podcast: $podcast_title ($podcast_url), keeping last $episodes_to_keep episode" . plural($episodes_to_keep). "\n";

    $podcast_simplexml = simplexml_load_string(file_get_contents(trim($podcast_url)));
    if(!$podcast_simplexml) {
        echo "$podcast_title ($podcast_url) is not providing valid XML. Skipping.\n";
        continue;
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
        if($url != '' && strpos($poddie_already_fetched, $url) === false) {
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
        echo "Removing #$index from $podcast_title ($file_to_remove)\n";
        unlink($file_to_remove);
    }
}

$number_of_podcasts = count($poddie_config);
if ($downloaded_files_count > 0) echo "Downloaded $downloaded_files_count files from $number_of_podcasts podcast feeds.\n";


function poddie_setup() {

    verify_requirements();

    define("PODDIE_CONFIG_FILE", dirname($_SERVER['SCRIPT_FILENAME']) . "/poddie.config");
    define("PODDIE_FEEDS_FILE", dirname($_SERVER['SCRIPT_FILENAME']) . "/poddie.feeds");
    define("PODDIE_FETCHED_LOGFILE", dirname($_SERVER['SCRIPT_FILENAME']) . "/poddie.fetched");

    define("PODDIE_ID3TAG_BIN", get_poddie_config("id3tag"));
    define("PODDIE_PODCAST_STORAGE", get_poddie_config("podcast_storage"));

    date_default_timezone_set(get_poddie_config("timezone"));
}

function verify_requirements() {
    if(!extension_loaded('SimpleXML')) poddie_die("ERROR: Poddie requirement: SimpleXML", 1);
}

function poddie_die($msg, $exitcode) {
    fwrite(STDERR, "$msg\n");
    exit($exitcode); // A response code other than 0 is a failure
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

function is_feed_alive($url) {
    $handle = curl_init($url);
    curl_setopt($handle,  CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($handle,  CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($handle,  CURLOPT_MAXREDIRS, 10);
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

function is_valid_poddie_config_line($str) {
    return substr_count($str, ';') == 2;
}

function plural($num) {
    if(!empty($num) && is_numeric($num) && $num > 1)
        return 's';
}

?>
