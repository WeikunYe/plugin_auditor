<?php
/*
Plugin Name: Nexty Plugin Reporter
Plugin URI: hhttps://www.nexty.com.au/
Description: A plugin to generate reports of other plugins
Version: 1.0.0
Author: Weikun Ye
Author URI: weikun.ye@nexty.com.au
License: GPL
Copyright: Weikun Ye
Text Domain: plugin-reportor
Domain Path: /languages

*/

if (!function_exists('is_admin')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit();
}

define('PLUGIN_AUDIT_VERSION', '2.4.3');
define('PLUGIN_AUDIT_RELEASE_DATE', date_i18n('F j, Y', '1397937230'));
define('PLUGIN_AUDIT_DIR', plugin_dir_path(__FILE__));
define('PLUGIN_AUDIT_URL', plugin_dir_url(__FILE__));

if (!class_exists("Plugin_Audit")) :

class Plugin_Audit {

    var $settings, $options_page, $db_version = 1;

    function __construct() {

        // Load example settings page
        if (!class_exists("Plugin_Audit_Settings")) {
            require(PLUGIN_AUDIT_DIR . 'settings.php');
        }

        $this->settings = new Plugin_Audit_Settings();

        // add_action('init', array($this,'init'));
        add_action('admin_init', array($this,'admin_init'));
        add_action( 'plugins_loaded' , array( $this , 'plugin_auditor_load_textdomain' ) );

        register_activation_hook(__FILE__, array($this,'activate'));
        register_uninstall_hook(__FILE__, array('Plugin_Audit','uninstall'));
    }

    /**
     * Load plugin textdomain.
     *
     */
    public function plugin_auditor_load_textdomain() {
        load_plugin_textdomain( 'plugin-auditor', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' ); 
    }

    /*
        Propagates pfunction to all blogs within our multisite setup.
        More details -
        http://shibashake.com/wordpress-theme/write-a-plugin-for-wordpress-multi-site

        If not multisite, then we just run pfunction for our single blog.
    */
    public static function network_propagate($pfunction, $networkwide) {
        global $wpdb;

        if (function_exists('is_multisite') && is_multisite()) {
            // check if it is a network activation - if so, run the activation function
            // for each blog id
            if ($networkwide) {
                $old_blog = $wpdb->blogid;
                // Get all blog ids
                $blogids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
                foreach ($blogids as $blog_id) {
                    switch_to_blog($blog_id);
                    call_user_func($pfunction, $networkwide);
                }
                switch_to_blog($old_blog);
                return;
            }
        }
        call_user_func($pfunction, $networkwide);
    }

    function activate($networkwide) {
        $this->network_propagate(array($this, '_activate'), $networkwide);
    }

    public static function uninstall($networkwide) {
        Plugin_Audit::network_propagate(array('Plugin_Audit', '_uninstall'), $networkwide);
    }

    /*
        Plugin activation code here.
    */
    protected function _activate() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'plugin_audit';

        $charset_collate = '';

        if ( ! empty( $wpdb->charset ) ) {
          $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
        }

        if ( ! empty( $wpdb->collate ) ) {
          $charset_collate .= " COLLATE {$wpdb->collate}";
        }

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) NOT NULL,
            `status` int NOT NULL,
            `note` text,
            `plugin_data` text,
            `old_plugin_data` text,
            `plugin_path` varchar(255) NOT NULL,
            `wp_version` varchar(255),
            `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        dbDelta( $sql );

        add_option( 'pa_db_version', $this->db_version );
    }

    /**
    * Plugin deactivation code here.
    */
    public static function _uninstall() {
        global $wpdb;
        global $pa_db_version;

        $table_name = $wpdb->prefix . 'plugin_audit';

        $sql = "DROP TABLE $table_name;";

        $wpdb->query( $sql );

        delete_option( 'pa_db_version' );
        delete_option( 'pa_plugins' );
        delete_option( 'pa_active_plugins' );
    }

    /**
     * Load the actions that must be done when "save" or "dismiss" are submited and get plugin's data
     */
    public function admin_init() {
        global $wpdb, $log;

        $user_ID = get_current_user_id();

        $table_name = $wpdb->prefix . 'plugin_audit';

        $query = "SELECT * FROM $table_name WHERE user = NULL";

        if(isset($_POST['save_note'])) {
            if(!empty($_POST['note'])) {
                $wpdb->update(
                    $table_name,
                    array('note' => sanitize_text_field($_POST['note'])),
                    array('id' => intval($_POST['log_id'])),
                    array('%s'),
                    array('%d')
                );
            };
        } else {
            if (isset($_POST['not_now'])) {
                $id_log = intval($_POST['log_id']);
                $log = $wpdb->get_row("SELECT * FROM $table_name WHERE id = $id_log AND status = 1");

                if ( $log->note != NULL ) {
                     return;
                } else {
                    $wpdb->update( 
                        $table_name,
                        array( 'note' => '' ), 
                        array( 'id' => intval($_POST['log_id']))
                    );
                }
            }
        }   

        $all_plugins = get_plugins();
        $all_plugins_keys = array_keys($all_plugins);
        $active_plugins = (array) get_option('active_plugins', array());
        $pa_plugins = (array) get_option('pa_plugins', array());
        $pa_plugins_keys = array_keys($pa_plugins);
        $pa_active_plugins = (array) get_option('pa_active_plugins', array());

        foreach($all_plugins as $plugin => $data) {
            $common_data = array(
                'user_id' => $user_ID,
                'plugin_path' => trim($plugin),
                'plugin_data' => $data,
            );
            if(isset($pa_plugins[$plugin])) {
                $common_data['old_plugin_data'] = $pa_plugins[$plugin];
            }
            if(!in_array($plugin, $pa_plugins_keys)) {
                $this->log_action(array_merge($common_data, array(
                    'status'    => 1,
                )));
            } elseif(in_array($plugin, $active_plugins) && !in_array($plugin, $pa_active_plugins)) {
                $this->log_action(array_merge($common_data, array(
                    'status'    => 2,
                )));
            }
            if(!in_array($plugin, $active_plugins) && in_array($plugin, $pa_active_plugins)) {
                $this->log_action(array_merge($common_data, array(
                    'status'    => 3,
                )));
            }
            if(!empty($pa_plugins[$plugin]['Version']) && $data['Version'] != $pa_plugins[$plugin]['Version']) {
                $this->log_action(array_merge($common_data, array(
                    'status' => 4,
                )));
            }
        }

        foreach($pa_plugins_keys as $plugin) {
            if(!in_array($plugin, $all_plugins_keys)) {
                $this->log_action(array(
                    'user_id' => $user_ID,
                    'status' => 5,
                    'plugin_path' => trim($plugin),
                    'plugin_data' => $pa_plugins[$plugin],
                    'old_plugin_data' => $pa_plugins[$plugin],
                ));
            }
        }

        update_option('pa_plugins', $all_plugins);
        update_option('pa_active_plugins', $active_plugins);

        if ( function_exists('is_multisite') && is_multisite() && is_network_admin() ) {
            add_action('network_admin_notices', array($this, 'add_note_nag'), 99);
        }

        if ( ! is_multisite() ) {
            add_action('admin_notices', array($this, 'add_note_nag'), 99);
        }
    }

    /**
     * Add note nag in the top of page where the user can comment about a plugin that was installed
     */
    public function add_note_nag() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'plugin_audit';

        $list = $wpdb->get_results("SELECT plugin_path FROM $table_name WHERE status = 5", ARRAY_A);

        foreach ( $list as $path ) 
        {
            $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE plugin_path = %s",$path));
        }

        /* This query retrieve the fields that need to receive data - aka note */
        $log = $wpdb->get_row("SELECT * FROM $table_name WHERE note IS NULL AND status = 1 AND plugin_path <> 'plugin-auditor/plugin-audit.php'");


 ?>
    

<?php
        
    }

    /**
     * Encode the plugin data and insert in the database
     */
    protected function log_action($data) {
        global $wpdb, $wp_version;

        $table_name = $wpdb->prefix . 'plugin_audit';

        if(isset($data['plugin_data']) && !is_string($data['plugin_data'])) {
            $data['plugin_data'] = json_encode($data['plugin_data']);
        }

        if(isset($data['old_plugin_data']) && !is_string($data['old_plugin_data'])) {
            $data['old_plugin_data'] = json_encode($data['old_plugin_data']);
        }

        $data = array_merge($data, array(
            'wp_version' => $wp_version,
        ));

        $wpdb->insert($table_name, $data);
    }

} // end class
endif;

// Initialize our plugin object.
global $plugin_audit;
if (class_exists("Plugin_Audit") && !$plugin_audit) {
    $plugin_audit = new Plugin_Audit();
}
?>