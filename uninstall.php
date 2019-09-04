<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

global $wpdb;
global $table_name_feed, $table_name_tags, $table_name_map;

$table_name_feed = $wpdb->prefix . 'phabricator_feed_recs';
$table_name_tags = $wpdb->prefix . 'phabricator_feed_tags';
$table_name_map = $wpdb->prefix . 'phabricator_feed_map';
  
delete_option('phabricator_feed_import');

$wpdb->query("DROP TABLE IF EXISTS {$table_name_map}");
$wpdb->query("DROP TABLE IF EXISTS {$table_name_tags}");
$wpdb->query("DROP TABLE IF EXISTS {$table_name_feed}");

?>
