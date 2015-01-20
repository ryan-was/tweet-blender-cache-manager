<?php
/*
Plugin Name: Tweet Blender Cache Manager [Modified]
Plugin URI: http://tweetblender.com/cache-manager
Description: Allows to manage individual cached tweets and backup/restore cache database
Version: 1.0.1
Author: Kirill Novitchenko
Author URI: http://kirill-novitchenko.com
*/

// include WP functions
function_exists('is_admin') || require_once(ABSPATH . "wp-blog-header.php");
//function_exists('check_admin_referer') || require_once(ABSPATH . WPINC . "/pluggable.php");

global $pluginUrl;
$pluginDir = plugin_dir_path( __FILE__ );
$pluginUrl = plugins_url( '' , __FILE__ );


if (isset($_POST['action']) && $_POST['action'] == 'delete') {

    // include JSON library
    class_exists('Services_JSON') || require('lib/JSON.php');
    $json = new Services_JSON();

    // make sure user is admin
    tb_cm_admin_only();

    // fix GoDaddy's 404 status
    status_header(200);

    // get ids of tweets to delete
    $tweets_to_delete = explode(',', $_POST['ids']);

    // delete cached tweets
    $deleted_tweets = tb_cm_delete_cached_tweets($tweets_to_delete);

    // return result
    if ($deleted_tweets === false) {
        echo $json->encode(array('ERROR' => 1, 'message' => 'Not able to remove tweets due to DB issues'));
    }
    else {
        $deleted_tweets == 1 ? $s = '' : $s = 's';
        echo $json->encode(array('OK' => 1, 'message' => $deleted_tweets . " tweet$s deleted"));
    }
    exit;
}

elseif (isset($_GET['action']) && $_GET['action'] == 'get_data') {

    // fix GoDaddy's 404 status
    status_header(200);

    // include JSON library
    class_exists('Services_JSON') || require('lib/JSON.php');
    $json = new Services_JSON();

    // make sure user is admin
    tb_cm_admin_only();

    // get sort field
    if (isset($_GET['sort_by']) && $_GET['sort_by'] != '') {
        $sort_field = esc_sql($_GET['sort_by']);
    }
    else {
        $sort_field = 'created_at';
    }

    // get sort order
    if (isset($_GET['sort_order']) && $_GET['sort_order'] != '') {
        $sort_order = esc_sql($_GET['sort_order']);
    }
    else {
        $sort_order = 'DESC';
    }

    // get page number
    $page_num = intval(esc_sql($_GET['page']));
    if ($page_num <= 0) { $page_num = 1; }

    // get number of records per page
    $tweets_num = intval(esc_sql($_GET['records_per_page']));
    if ($tweets_num <= 0) { $tweets_num = 10; }

    // get total count
    $total_records = tb_cm_get_total_count();

    // deal with cases where number per page is greater than total number available
    if (($page_num - 1) * $tweets_num > $total_records) {
        $page_num = 1;
    }

    // get cached tweets
    $tweets = tb_cm_get_cached_tweets($sort_field,$sort_order,$page_num,$tweets_num);

    // return
    echo $json->encode(array('cached_tweets' => $tweets, 'total_records' => $total_records, 'page' => $page_num));
    exit;
}

elseif (isset($_GET['action']) && $_GET['action'] == 'archive_backup') {

    // make sure user is admin
    tb_cm_admin_only();

    // fix GoDaddy's 404 status
    status_header(200);

    // perform backup
    tb_cm_archive_backup();

    exit;
}

elseif (isset($_POST['action']) && $_POST['action'] == 'archive_restore') {

    // make sure user is admin
    check_admin_referer('archive_restore','nonce');

    // fix GoDaddy's 404 status
    status_header(200);

    // perform restore
    tb_cm_archive_restore();

    exit;
}

/*
 * Loads javascript needed for Cache Manager operations
 */
function tb_admin_load_scripts_addon1() {
    global $pluginUrl;
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-form');
    wp_enqueue_script('jquery-pagination', $pluginUrl . '/js/pagination.js',array('jquery'));
    wp_enqueue_script('tb-cache-manager', $pluginUrl . '/js/cache_manager.js',array('jquery','jquery-pagination','jquery-form','tb-admin'),false,true);
}

/*
 * Loads style sheet needed for Cache Manager operations
 */
function tb_admin_load_styles_addon1() {
    global $pluginUrl;
    wp_enqueue_style('tb-cache-manager-css', $pluginUrl . '/css/cm.css');
}

/*
 * Generates HTML page for Cache Manager tool
 */
function tb_cm_get_cache_page_html() {
    $page_html = '<h3>Manage Archived Tweets</h3>';

    $records_per_page = 10;
    if (isset($_COOKIE['tb_cm_records_per_page'])) {
        $records_per_page = $_COOKIE['tb_cm_records_per_page'];
    }

    // list control tools
    $page_html .= '<div class="tablenav">';
    $page_html .= '<div class="tablenav-pages"><span class="displaying-num" id="counts"></span><span id="pagination"></span></div>';
    $page_html .= '<div class="alignleft actions"><label for="records_per_page" class="tb_cm">Show</label> <select id="records_per_page" name="records_per_page">';
    for ($i = 5; $i <= 50; $i+=5) {
        $page_html .= '<option value="' . $i . '"';
        if ($i == $records_per_page) {
            $page_html .= ' selected';
        }
        $page_html .= '>' . $i . '</option>';
    }
    $page_html .= '</select> <label for="records_per_page" class="tb_cm">per page</label></div><br class="clear"/>';
    $page_html .= '</div>';

    // table shell
    $page_html .= '<table id="cached-tweets" class="widefat page" cellspacing="0">';

    $control_row = '<tr>';
    $control_row .= '<th class="manage-column column-cb check-column"><input type="checkbox" name="select_all" /></th>';
    $control_row .= '<th class="manage-column sortable" id="sort-source">Source <img src="' . plugins_url('tweet-blender-cache-manager/img/bg.gif') . '"/></th>';
    $control_row .= '<th class="manage-column sortable" id="sort-tweet_text">Text <img src="' . plugins_url('tweet-blender-cache-manager/img/bg.gif') . '"/></th>';
    $control_row .= '<th class="manage-column sortable" id="sort-created_at">Cached Date <img src="' . plugins_url('tweet-blender-cache-manager/img/DESC.gif') . '"/></th>';
    $control_row .= '</tr>';

    // Show delete button
    $delete_button_html = '&nbsp; ↑ &nbsp;<input id="btn_delete" type="button" class="button-secondary action" value="Delete Selected Tweets" />';

    $page_html .= '<thead>' . $control_row . '</thead>';
    $page_html .= '<tbody><tr><td class="message" colspan="4">Loading...</td></tr></tbody>';
    $page_html .= '<tfoot><tr><th colspan="4" class="manage-column">' . $delete_button_html . '</th></tr></tfoot>';
    $page_html .= '</table>';

    // backup feature
    $page_html .= '<div class="box-left"><h3>Backup Archive</h3>';
    $page_html .= '<p class="help">Click the button below to download the entire archive as a CSV file.</p>';
    $page_html .= '<input id="btn_backup" type="button" class="button-secondary action" value="Backup Archive" /></div>';

    // restore feature
    $page_html .= '<div class="box-right"><h3>Restore Archive</h3>';
    $page_html .= '<p class="help">Select an archive file and click the button below to upload the archive and merge it with the current database. Duplicate tweets from the uploaded archive will be ignored.</p>';
    $page_html .= '<form id="restoreForm" action="#" method="post">' . wp_nonce_field('archive_restore','archive_restore_nonce',false,false) . '<input type="hidden" name="action" value="archive_restore" /><input type="file" name="archive_backup_file" id="archive_backup_file" /><input type="submit" class="button-secondary action" value="Restore Archive" /></form></div>';

    $page_html .= '<br class="clear"/>';
    return $page_html;
}

/*
 * Gets cached tweets - a slice of entire arhive
 * ordered by one column in one direction
 */
function tb_cm_get_cached_tweets($sort_field,$sort_order,$page,$tweets) {

    global $wpdb;
    $table_name = $wpdb->prefix . "tweetblender";

    $offset = ($page - 1) * $tweets;

    // get data from DB
    return $wpdb->get_results("
        SELECT DISTINCT div_id, source, tweet_text AS text, created_at AS date
        FROM $table_name
        ORDER BY $sort_field $sort_order
        LIMIT $offset, $tweets
    ");
}

/*
 * Gets total number of records in cache - used for pagination
 */
function tb_cm_get_total_count() {

    global $wpdb;
    $table_name = $wpdb->prefix . "tweetblender";

    return $wpdb->get_var("SELECT count(*) FROM $table_name");
}

/*
 * Makes sure request is from an admin page
 */
function tb_cm_admin_only() {
    if (isset($_POST['archive_restore_nonce'])) {
        check_admin_referer('archive_restore','archive_restore_nonce');
        if ( ! wp_verify_nonce( $_POST['archive_restore_nonce'], 'archive_restore' ) ) {
            die( 'Security check' );
        }
    } else {
        check_admin_referer('tb_cache_manager','security');
        if ( ! wp_verify_nonce( $_REQUEST['security'], 'tb_cache_manager' ) ) {
            die( 'Security check' );
        }
    }
}

/*
 * Deletes from cache all tweets with IDs in the array given as parameter
 */
function tb_cm_delete_cached_tweets($tweets_to_delete) {

    global $wpdb;
    $table_name = $wpdb->prefix . "tweetblender";
    $toDelete = implode("','",$tweets_to_delete);
    return $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE div_id IN ( %s )",$toDelete));
}

/*
 * Returns all tweets as CSV file
 */
function tb_cm_archive_backup() {

    global $wpdb;
    $table_name = $wpdb->prefix . "tweetblender";

    // get all cached tweets
    $tweets = $wpdb->get_results("SELECT div_id, source, tweet_text, tweet_json, created_at FROM $table_name ORDER BY div_id DESC");

    // output headers for CSV
    header("Content-type: application/force-download");
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"Tweet_Blender_cache_" . date("Y-m-d_h-ia") . ".csv\"");
    echo "ID,Source,Tweet Text,Tweet JSON,Create Timestamp\n";

    // output rows
    foreach ($tweets as $t) {
        echo sprintf('"%s","%s","%s","%s","%s"'."\n",
            addslashes($t->div_id),
            addslashes($t->source),
            addslashes($t->tweet_text),
            addslashes($t->tweet_json),
            addslashes($t->created_at)
        );
    }
}

/*
 * Takes a CSV file and imports into the cache DB
 * Skips records that already exist
 */
function tb_cm_archive_restore() {

    global $wpdb;
    $table_name = $wpdb->prefix . "tweetblender";

    // 10 * 1024 * 1024 = 10MB
    $max_upload_size = 10 * 1024 * 1024;

    // include JSON library
    class_exists('Services_JSON') || require('lib/JSON.php');
    $json = new Services_JSON();

    // if PHP limit was exceeded
    if ($_FILES['archive_backup_file']['size'] == 0 || $_FILES["archive_backup_file"]["error"] > 0) {
        $error_message = "File size exceeds server's maximum allowed upload size.";
    }
    else if ($_FILES['archive_backup_file']['size'] > 0 && $_FILES['archive_backup_file']['size'] <= $max_upload_size) {

        $temp_name = $_FILES['archive_backup_file']['tmp_name'];
        $file_name = $_FILES['archive_backup_file']['name'];

        $fp = fopen($temp_name, 'r');

        // remove the first line with column headers
        $csv_col_headers = fgets($fp);

        // iterate over CSV file rows and keep counters
        $inserts = 0; $ignores = 0; $errors = 0;
        while($tweet_csv = fgets($fp)) {

            $parts = explode('","', substr($tweet_csv,1,-1));

            // check existing
            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE div_id = '%s'",$parts[0]));
            if ($count > 0) {
                $ignores++;
            }
            // if not found - insert
            else {
                $result = $wpdb->insert($table_name,array(
                    'div_id' => $parts[0],
                    'source' => stripslashes($parts[1]),
                    'tweet_text' => stripslashes($parts[2]),
                    'tweet_json' => stripslashes($parts[3]),
                    'created_at' => $parts[4]
                ));

                // if insert was ok - increment insert counter
                if($result) {
                    $inserts++;
                }
                // else - increment error counter
                else {
                    $errors++;
                }
            }
        }
        fclose($fp);
    }
    else {
        $error_message = "File size exceeds application's maximum upload size.";
    }

    // if we had errors in the process - report them
    if (isset($error_message)) {
        echo $json->encode(array('ERROR' => 1,'message' => $error_message));
        return;
    }
    // if no errors - provide status message with counters
    else {
        $message = 'IMPORT COMPLETE: ' . ($inserts + $ignores + $errors) . " records processed\n\n" . $inserts . " records added to cache database.\n" . $ignores . " records ignored as duplicates.\n" . $errors . " records generated errors.";
        echo $json->encode(array('OK' => 1,'message' => $message));
        return;
    }
}
?>
