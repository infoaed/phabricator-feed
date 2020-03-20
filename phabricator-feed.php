<?php
/**
* Plugin Name: Phabricator Feed Importer
* Plugin URI: https://github.com/infoaed/phabricator-feed
* Description: This imports feeds from Phabricator workflow management.
* Version: 1.1
* Author: Märt Põder
* Author URI: http://gafgaf.infoaed.ee/
* License: GPL2
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* GitHub Plugin URI: https://github.com/infoaed/phabricator-feed
* GitHub Branch: master
**/

// prereq in feed importer main directory
// git clone https://github.com/phacility/libphutil.git
// also need: php-curl

require_once 'libphutil/src/__phutil_library_init__.php';

global $wpdb;
global $table_name_feed, $table_name_tags, $table_name_map;

$table_name_feed = $wpdb->prefix . 'phabricator_feed_recs';
$table_name_tags = $wpdb->prefix . 'phabricator_feed_tags';
$table_name_map = $wpdb->prefix . 'phabricator_feed_map';

function phabricator_feed_get_data() {
    global $wpdb;
    global $table_name_feed, $table_name_tags, $table_name_map;
    
    $options = get_option("phabricator_feed_import");

    try {

        $conduit_token = $options["conduit_token"];
        $conduit_uri = new PhutilURI($options['conduit_uri']);
        $conduit_host = (string) $conduit_uri->setPath('/');
        $conduit_uri = (string) $conduit_uri->setPath('/api/');
        $conduit = new ConduitClient($conduit_uri);
        $conduit->setConduitToken($conduit_token);

        // "projects": [ "WMEE" ], "statuses": ["open"]
        $tasks = array();
        $tags = array();
        
        $param = ['constraints' => ['projects' => [$options["source_project"]], 'statuses' => ['open']], 'attachments' => [ 'projects' => 'true', 'columns' => 'true' ]];
        $tasks = $conduit->callMethodSynchronous('maniphest.search', $param);

        $delete = $wpdb->query("TRUNCATE TABLE $table_name_feed");
        $delete = $wpdb->query("TRUNCATE TABLE $table_name_tags");
        $delete = $wpdb->query("TRUNCATE TABLE $table_name_map");
        
        if (count($tasks) > 0) {

            foreach ($tasks['data'] as $task) {
                $tasks_sql[] = $wpdb->prepare("(%d,%s)", $task['id'], $task['fields']['name']);
                foreach ($task['attachments']['projects']['projectPHIDs'] as $tag) {
                    $tasks_map_sql[] = $wpdb->prepare("(%d,%s)", $task['id'], $tag);
                    $project_phids[] = $tag;
                }
            }
            
            $param = ['constraints' => ['phids' => $project_phids]];
            $projects = $conduit->callMethodSynchronous('project.search', $param);
            
            if (count($projects) > 0) {
                foreach ($projects['data'] as $tag) {
                    $slug = $tag['fields']['slug'];
                    if (strlen($slug) == 0) $slug = str_replace(" ", "_", strtolower($tag['fields']['name']));
                    $tags_sql[] = $wpdb->prepare("(%s,%s,%s)", $tag['phid'], $tag['fields']['name'], $slug);
                    $tags[$tag['phid']] = $slug;
                }
            }

            foreach ($tasks['data'] as $task) {
                foreach ($task['attachments']['columns']['boards'] as $project_id => $tag) {
                    $project_slug = str_replace(" ", "_", strtolower($tags[$project_id]));
                    foreach ($tag['columns'] as $col) {
                        $tasks_map_sql[] = $wpdb->prepare("(%d,%s)", $task['id'], $col['phid']);
                        if (!in_array($col['id'], isset($cols) ? $cols : array())) {
                            $slug = $project_slug . "_" . str_replace(" ", "_", strtolower($col['name']));
                            $tags_sql[] = $wpdb->prepare("(%s,%s,%s)", $col['phid'], $col['name'], $slug);
                            $cols[] = $col['id'];
                        }
                    }
                }
            }


            $tags_sql = implode( ",\n", $tags_sql );
            $query = "INSERT INTO $table_name_tags (id, name, slug) VALUES {$tags_sql}";
            $wpdb->query($query);

            $tasks_sql = implode( ",\n", $tasks_sql );
            $query = "INSERT INTO $table_name_feed (id, name) VALUES {$tasks_sql}";
            $wpdb->query($query);

            $tasks_map_sql = implode( ",\n", $tasks_map_sql );
            $query = "INSERT INTO $table_name_map (rec_id, tag_id) VALUES {$tasks_map_sql}";
            $wpdb->query($query);

            $options["last_updated"] = current_time("timestamp");

            update_option("phabricator_feed_import", $options);
            add_shortcodes();
        }

    } catch (ConduitClientException $ex) {
        
        error_log($ex);
    }
}

function add_shortcodes() {
    global $wpdb;
    global $table_name_tags;
    
    $result = $wpdb->get_results ( "
        SELECT DISTINCT $table_name_tags.slug
        FROM $table_name_tags
    " );

    if (count($result) > 0) {
        foreach ( $result as $project ) {
            add_shortcode("phabricator_feed_".$project->slug, function() use ($project) { return wp_feed_shortcode($project->slug); });
        }
    }
}

function wp_feed_shortcode($slug) {
    global $wpdb;
    global $table_name_feed, $table_name_tags, $table_name_map;
    
    $options = get_option("phabricator_feed_import");

    if ($options['last_updated'] + $options['update_interval'] < current_time("timestamp")) {
        phabricator_feed_get_data();
    }

    $result = $wpdb->get_results ( "
        SELECT $table_name_feed.id AS id, $table_name_feed.name, GROUP_CONCAT($table_name_tags.name) AS tag
        FROM $table_name_map
        INNER JOIN $table_name_tags ON $table_name_tags.id = $table_name_map.tag_id
        INNER JOIN $table_name_feed ON $table_name_feed.id = $table_name_map.rec_id
        WHERE $table_name_tags.slug IN ('$slug')
        GROUP BY id
    " );

    $text = "";
    if (count($result) > 0) {
        $text .= "<ul class=\"". $slug. "-phabricator\">";
        foreach ( $result as $page ) {
            $text .= "<li><a href=\"https://phabricator.wikimedia.org/T" . $page->id . "\">" . $page->name . "</a></li>\n";
        }
        $text .= "</ul>";
    } else {
        $text .= "<ul class=\"". $slug. "-phabricator-none\">";
        $text .= "</ul>";
    }

    return $text;
}

register_activation_hook( __FILE__, 'phabricator_feed_install' );
register_activation_hook( __FILE__, 'phabricator_feed_get_data' );
register_uninstall_hook(__FILE__, 'phabricator_feed_uninstall');

add_shortcodes();

function phabricator_feed_install() {
    global $wpdb;
    global $table_name_feed, $table_name_tags, $table_name_map;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = array();

    $sql[] = "CREATE TABLE $table_name_feed (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name tinytext NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $table_name_tags (
        id varchar(50) NOT NULL,
        name tinytext NOT NULL,
        slug tinytext NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $table_name_map (
        tag_id varchar(50) NOT NULL,
        rec_id mediumint(9) NOT NULL
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta($sql);
    
    $default_interval = 100;
    add_option("phabricator_feed_import", Array("update_interval" => $default_interval, "conduit_uri" => "https://phabricator.wikimedia.org/api/", "conduit_token" => "api-token_which_starts_with_api", "last_updated" => current_time("timestamp")-$default_interval, "source_project" => "WMEE"));
}

function phabricator_feed_uninstall() {
    global $wpdb;
    global $table_name_feed, $table_name_tags, $table_name_map;

    $table_name_feed = $wpdb->prefix . 'phabricator_feed_recs';
    $table_name_tags = $wpdb->prefix . 'phabricator_feed_tags';
    $table_name_map = $wpdb->prefix . 'phabricator_feed_map';
      
    delete_option('phabricator_feed_import');

    $wpdb->query("DROP TABLE IF EXISTS {$table_name_map}");
    $wpdb->query("DROP TABLE IF EXISTS {$table_name_tags}");
    $wpdb->query("DROP TABLE IF EXISTS {$table_name_feed}");
}

/* the rest of it is settings */

function phabricator_feed_options_init_fn(){
    register_setting("phabricator_feed_import", "phabricator_feed_import", 'phabricator_feed_options_validate' );
    add_settings_section('default', 'Basic settings', 'section_text_fn', "phabricator_feed_import");    
    add_settings_field('source_project', 'Source project', 'setting_source_project_fn', "phabricator_feed_import");
    add_settings_field('update_interval', 'Update interval (minutes)', 'setting_update_interval_fn', "phabricator_feed_import");
    add_settings_field('last_updated', 'Last updated', 'setting_last_updated_fn', "phabricator_feed_import");
    add_settings_field('conduit_uri', 'Conduit URI', 'setting_conduit_uri_fn', "phabricator_feed_import");
    add_settings_field('conduit_token', 'Conduit token', 'setting_conduit_token_fn', "phabricator_feed_import");
}

function section_text_fn() {
}

function setting_update_interval_fn() {
    $options = get_option("phabricator_feed_import");
    echo "<input id='update_interval' name='phabricator_feed_import[update_interval]' size='10' type='text' value='{$options['update_interval']}' />";
}

function setting_conduit_token_fn() {
    $options = get_option("phabricator_feed_import");
    echo "<input id='conduit_token' name='phabricator_feed_import[conduit_token]' size='40' type='text' value='{$options['conduit_token']}' />";
}

function setting_conduit_uri_fn() {
    $options = get_option("phabricator_feed_import");
    echo "<input id='conduit_uri' name='phabricator_feed_import[conduit_uri]' size='40' type='text' value='{$options['conduit_uri']}' />";
}

function setting_source_project_fn() {
    $options = get_option("phabricator_feed_import");
    echo "<input id='source_project' name='phabricator_feed_import[source_project]' size='40' type='text' value='{$options['source_project']}' />";
}

function setting_last_updated_fn() {
    $options = get_option("phabricator_feed_import");
    echo "<input id='last_updated' name='phabricator_feed_import[last_updated]' size='40' type='text' value='{$options['last_updated']}' />";
}

function phabricator_feed_options_validate($input) {
    $new_input = array();
    if( isset( $input['conduit_token'] ) )
        $new_input['conduit_token'] = sanitize_text_field( $input['conduit_token'] );

    if( isset( $input['conduit_uri'] ) )
        $new_input['conduit_uri'] = sanitize_text_field( $input['conduit_uri'] );

    if( isset( $input['source_project'] ) )
        $new_input['source_project'] = sanitize_text_field( $input['source_project'] );

    if( isset( $input['update_interval'] ) )
        $new_input['update_interval'] = absint( $input['update_interval'] );

    if( isset( $input['last_updated'] ) )
        $new_input['last_updated'] = absint( $input['last_updated'] );

    return $new_input;
}

function phabricator_feed_options_add_page_fn() {
    add_options_page('Phabricator Feed Importer', 'Phabricator Feed', 'administrator', "phabricator_feed_import", 'phabricator_feed_options_page_fn');
}

function phabricator_feed_options_page_fn() {
    global $wpdb;
    global $table_name_tags;
    ?>
    <div class="wrap">
        <h1>Phabricator Feed Importer Settings</h1>
        <form method="post" action="options.php">
        <?php
            settings_fields("phabricator_feed_import");
            do_settings_sections("phabricator_feed_import");
            submit_button()
        ?>
        </form>

        <h2>Current shortcodes</h2>
        <ul>
        <?php

        $result = $wpdb->get_results ( "
            SELECT DISTINCT $table_name_tags.slug
            FROM $table_name_tags
        " );

        if (count($result) > 0) {
            foreach ( $result as $project ) {
                echo "<li><code>phabricator_feed_".$project->slug."</code></li>";
            }
        }
        ?>
        </ul>
    </div>
    <?php
}

add_action('admin_init', 'phabricator_feed_options_init_fn' );
add_action('admin_menu', 'phabricator_feed_options_add_page_fn');

function salcode_add_plugin_page_settings_link( $links ) {
    $links[] = '<a href="' . admin_url( 'options-general.php?page=phabricator_feed_import' ) . '">' . __('Settings') . '</a>';
    return $links;
} 

add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'salcode_add_plugin_page_settings_link');



?>
