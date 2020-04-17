<?php
/**
 * Settings management and UI
 *
 * @since 0.2.0
 */
namespace wpCloud\StatelessMedia {

  if( !class_exists( 'wpCloud\StatelessMedia\Settings' ) ) {

    final class Settings extends \UsabilityDynamics\Settings {

      /**
       * @var false|null|string
       */
      public $setup_wizard_ui = null;

      /**
       * @var Array
       */
      public $wildcards = array();

      /**
       * @var false|null|string
       */
      public $stateless_settings = null;

      /**
       * Instance of
       *  - ud_get_stateless_media
       *  - wpCloud\StatelessMedia\Bootstrap
       * @var false|null|string
       */
      public $bootstrap = null;

      private $settings = array(
        'mode'                   => array('WP_STATELESS_MEDIA_MODE', 'cdn'),
        'body_rewrite'           => array('WP_STATELESS_MEDIA_BODY_REWRITE', 'false'),
        'body_rewrite_types'     => array('WP_STATELESS_MEDIA_BODY_REWRITE_TYPES', 'jpg jpeg png gif pdf'),
        'bucket'                 => array('WP_STATELESS_MEDIA_BUCKET', ''),
        'root_dir'               => array('WP_STATELESS_MEDIA_ROOT_DIR', ['/%date_year%/%date_month%/', '/sites/%site_id%/%date_year%/%date_month%/']),
        'key_json'               => array('WP_STATELESS_MEDIA_JSON_KEY', ''),
        'cache_control'          => array('WP_STATELESS_MEDIA_CACHE_CONTROL', ''),
        'delete_remote'          => array('WP_STATELESS_MEDIA_DELETE_REMOTE', 'true'),
        'custom_domain'          => array('WP_STATELESS_MEDIA_CUSTOM_DOMAIN', ''),
        'organize_media'         => array('', 'true'),
        'hashify_file_name'      => array(['WP_STATELESS_MEDIA_HASH_FILENAME' => 'WP_STATELESS_MEDIA_CACHE_BUSTING'], 'false'),
      );

      private $network_only_settings = array(
        'hide_settings_panel'   => array('WP_STATELESS_MEDIA_HIDE_SETTINGS_PANEL', false),
        'hide_setup_assistant'  => array('WP_STATELESS_MEDIA_HIDE_SETUP_ASSISTANT', false),
      );

      private $strings = array(
        'network' => 'Currently configured via Network Settings.',
        'constant' => 'Currently configured via a constant.',
        'environment' => 'Currently configured via an environment variable.',
      );

      /**
       *
       * Settings constructor.
       * @param null $bootstrap
       */
      public function __construct($bootstrap = null) {
        $this->bootstrap = $bootstrap ? $bootstrap : ud_get_stateless_media();


        /* Add 'Settings' link for SM plugin on plugins page. */
        $_basename = plugin_basename( $this->bootstrap->boot_file );

        parent::__construct( array(
          'store'       => 'options',
          'format'      => 'json',
          'data'        => array(
            'sm' => array()
          )
        ));

        // Setting sm variable
        $this->refresh();

        $this->set('page_url.stateless_setup', $this->bootstrap->get_settings_page_url('?page=stateless-setup'));
        $this->set('page_url.stateless_settings', $this->bootstrap->get_settings_page_url('?page=stateless-settings'));

        /** Register options */
        add_action( 'init', array( $this, 'init' ), 3 );
        // apply wildcard to root dir.
        add_filter( 'wp_stateless_handle_root_dir', array( $this, 'root_dir_wildcards' ));

        $site_url = parse_url( site_url() );
        $site_url['path'] = isset($site_url['path']) ? $site_url['path'] : '';
        $this->wildcards = array(
          '%date_year%'            => [
                                    date('Y'),
                                    __("year", $this->bootstrap->domain),
                                    __("The year of the post, four digits, for example 2004.", $this->bootstrap->domain),
                                 ],
          '%date_month%'           => [
                                    date('m'),
                                    __("monthnum", $this->bootstrap->domain),
                                    __("Month of the year, for example 05.", $this->bootstrap->domain),
                                 ],
          '%site_id%'         => [
                                    get_current_blog_id(),
                                    __("site id", $this->bootstrap->domain),
                                    __("Site ID, for example 1.", $this->bootstrap->domain),
                                 ],
          '%site_url%'        => [
                                    trim( $site_url['host'] . $site_url['path'], '/ ' ),
                                    __("site url", $this->bootstrap->domain),
                                    __("Site URL, for example example.com/site-1.", $this->bootstrap->domain),
                                 ],
          '%site_url_host%'   => [
                                    trim( $site_url['host'], '/ ' ),
                                    __("host name", $this->bootstrap->domain),
                                    __("Host name, for example example.com.", $this->bootstrap->domain),
                                 ],
          '%site_url_path%'   => [
                                    trim( $site_url['path'], '/ ' ),
                                    __("site path", $this->bootstrap->domain),
                                    __("Site path, for example site-1.", $this->bootstrap->domain),
                                 ],
        );
      }

      /**
       * Init
       */
      public function init(){
        $this->save_media_settings();

        add_action('admin_menu', array( $this, 'admin_menu' ));
        /**
         * Manage specific Network Settings
         */
        if( is_network_admin() ) {
          add_action( 'network_admin_menu', array( $this, 'network_admin_menu' ));
        }

      }

      /**
       * Refresh settings
       */
      public function refresh() {
        $this->set( "sm.readonly", []);
        $google_app_key_file = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS');

        foreach ($this->settings as $option => $array) {
          $value    = '';
          $_option  = 'sm_' . $option;
          $constant = $array[0]; // Constant name
          $default  = is_array($array[1]) ? $array[1] : array($array[1], $array[1]); // Default value

          // Getting settings
          $value = get_option($_option, $default[0]);

          if ($option == 'body_rewrite_types' && empty($value) && !is_multisite()) {
            $value = $default[0];
          }

          if ($option == 'hashify_file_name' && $this->get("sm.mode") == 'stateless') {
            $value = true;
          }

          // If constant is set then override by constant
          if(is_array($constant)){
            foreach($constant as $old_const => $new_const){
              if(defined($new_const)){
                $value = constant($new_const);
                $this->set( "sm.readonly.{$option}", "constant" );
                break;
              }
              if(is_string($old_const) && defined($old_const)){
                $value = constant($old_const);
                $this->bootstrap->errors->add( array(
                  'key' => $new_const,
                  'title' => sprintf( __( "%s: Deprecated Notice (%s)", $this->bootstrap->domain ), $this->bootstrap->name, $new_const ),
                  'message' => sprintf(__("<i>%s</i> constant is deprecated, please use <i>%s</i> instead.", $this->bootstrap->domain), $old_const, $new_const),
                ), 'notice' );
                $this->set( "sm.readonly.{$option}", "constant" );
                break;
              }
            }
          }
          elseif(defined($constant)){
            $value = constant($constant);
            $this->set( "sm.readonly.{$option}", "constant" );
          }

          // Getting network settings
          if(is_multisite() && !$this->get( "sm.readonly.{$option}")){

            $network = get_site_option( $_option, $default[1] );
            // If network settings available then override by network settings.
            if($network || is_network_admin()){
              $value = $network;
              if(!is_network_admin())
                $this->set( "sm.readonly.{$option}", "network" );
            }

          }

          // Converting to string true false for angular.
          if(is_bool($value)){
            $value = $value === true ? "true" : "false";
          }

          $this->set( "sm.$option", $value);
        }

        // Network only settings, to hide settings page
        foreach ($this->network_only_settings as $option => $array) {
          $value    = '';
          $_option  = 'sm_' . $option;
          $constant = $array[0]; // Constant name
          $default  = $array[1]; // Default value

          // If constant is set then override by constant
          if(is_array($constant)){
            foreach($constant as $old_const => $new_const){
              if(defined($new_const)){
                $value = constant($new_const);
                break;
              }
              if(is_string($old_const) && defined($old_const)){
                $value = constant($old_const);
                trigger_error(__(sprintf("<i>%s</i> constant is deprecated, please use <i>%s</i> instead.", $old_const, $new_const)), E_USER_WARNING);
                break;
              }
            }
          }
          elseif(defined($constant)){
            $value = constant($constant);
          }
          // Getting network settings
          elseif(is_multisite()){
            $value = get_site_option( $_option, $default );
          }

          // Converting to string true false for angular.
          if(is_bool($value)){
            $value = $value === true ? "true" : "false";
          }

          $this->set( "sm.$option", $value);
        }

        /**
         * JSON key file path
         */
        /* Use constant value for JSON key file path, if set. */
        if (defined('WP_STATELESS_MEDIA_KEY_FILE_PATH') || $google_app_key_file !== false) {
          /* Maybe fix the path to p12 file. */
          $key_file_path = (defined('WP_STATELESS_MEDIA_KEY_FILE_PATH')) ? WP_STATELESS_MEDIA_KEY_FILE_PATH : $google_app_key_file;

          if( !empty( $key_file_path ) ) {
            $upload_dir = wp_upload_dir();
            /* Check if file exists */
            switch( true ) {
              /* Determine if default path is correct */
              case (file_exists($key_file_path)):
                /* Path is correct. Do nothing */
                break;
              /* Look using WP root. */
              case (file_exists( ABSPATH . $key_file_path ) ):
                $key_file_path = ABSPATH . $key_file_path;
                break;
              /* Look in wp-content dir */
              case (file_exists( WP_CONTENT_DIR . $key_file_path ) ):
                $key_file_path = WP_CONTENT_DIR . $key_file_path;
                break;
              /* Look in uploads dir */
              case (file_exists( wp_normalize_path( $upload_dir[ 'basedir' ] ) . '/' . $key_file_path ) ):
                $key_file_path = wp_normalize_path( $upload_dir[ 'basedir' ] ) . '/' . $key_file_path;
                break;
              /* Look using Plugin root */
              case (file_exists($this->bootstrap->path( $key_file_path, 'dir') ) ):
                $key_file_path = $this->bootstrap->path( $key_file_path, 'dir' );
                break;

            }
            if(is_readable($key_file_path)) {
              $this->set( 'sm.key_json', file_get_contents($key_file_path) );
              if(defined('WP_STATELESS_MEDIA_KEY_FILE_PATH'))
                $this->set( "sm.readonly.key_json", "constant" );
              else
                $this->set("sm.readonly.key_json", "environment");
            }
          }
        }

        $this->set( 'sm.strings', $this->strings );
      }

      /**
       * Remove settings
       * @param bool $network
       */
      public function reset($network = false) {
        foreach ($this->settings as $option => $array) {
          if($option == 'organize_media')
            continue;
          $_option = 'sm_' . $option;

          if($network && current_user_can('manage_network')){
            delete_site_option($_option);
            delete_option($_option);
          }
          else{
            delete_option($_option);
          }
        }

        foreach ($this->network_only_settings as $option => $array) {
          $_option = 'sm_' . $option;
          if($network && current_user_can('manage_network')){
            delete_site_option($_option);
            delete_option($_option);
          }
        }

        $this->set('sm', []);
        $this->refresh();
      }

      /**
       * Replacing wildcards with real values
       * @param $root_dir
       * @return mixed|null|string|string[]
       */
      public function root_dir_wildcards( $root_dir ) {

        $wildcards = apply_filters('wp_stateless_root_dir_wildcard', $this->wildcards);

        if ( is_array( $wildcards ) && !empty( $wildcards ) ) {
          foreach ($wildcards as $wildcard => $replace) {
            if (!empty($wildcard)) {
              $root_dir = str_replace($wildcard, $replace[0], $root_dir);
            }
          }
        }
        //removing all special chars except slash
        $root_dir = preg_replace('/[^A-Za-z0-9\/]/', '', $root_dir);
        $root_dir = preg_replace('/(\/+)/', '/', $root_dir);
        $root_dir = trim( $root_dir, '/ ' ); // Remove any forward slash and empty space.

        return $root_dir;
      }

      /**
       * Add menu options
       */
      public function admin_menu() {
        $key_json = $this->get('sm.key_json');
        if($this->get('sm.hide_setup_assistant') != 'true' && empty($key_json) ){
          $this->setup_wizard_ui = add_media_page( __( 'Stateless Setup', $this->bootstrap->domain ), __( 'Stateless Setup', $this->bootstrap->domain ), 'manage_options', 'stateless-setup', array($this, 'setup_wizard_interface') );
        }

        if($this->get('sm.hide_settings_panel') != 'true'){
          $this->stateless_settings = add_media_page( __( 'Stateless Settings', $this->bootstrap->domain ), __( 'Stateless Settings', $this->bootstrap->domain ), 'manage_options', 'stateless-settings', array($this, 'settings_interface') );
        }
      }

      /**
       * Add menu options
       * @param $slug
       */
      public function network_admin_menu($slug) {
        $this->setup_wizard_ui = add_submenu_page( 'settings.php', __( 'Stateless Setup', $this->bootstrap->domain ), __( 'Stateless Setup', $this->bootstrap->domain ), 'manage_options', 'stateless-setup', array($this, 'setup_wizard_interface') );
        $this->stateless_settings = add_submenu_page( 'settings.php', __( 'Stateless Settings', $this->bootstrap->domain ), __( 'Stateless Settings', $this->bootstrap->domain ), 'manage_options', 'stateless-settings', array($this, 'settings_interface') );
      }

      /**
       * Draw interface
       */
      public function settings_interface() {
        $wildcards = apply_filters('wp_stateless_root_dir_wildcard', $this->wildcards);
        include $this->bootstrap->path( '/static/views/settings_interface.php', 'dir' );
      }

      /**
       * Draw interface
       */
      public function regenerate_interface() {
        include $this->bootstrap->path( '/static/views/regenerate_interface.php', 'dir' );
      }

      /**
       * Draw interface
       */
      public function setup_wizard_interface() {
        $step = !empty($_GET['step'])?$_GET['step']:'';
        switch ($step) {
          case 'google-login':
          case 'setup-project':
          case 'finish':
            include $this->bootstrap->path( '/static/views/setup_wizard_interface.php', 'dir' );
            break;

          default:
            include $this->bootstrap->path( '/static/views/stateless_splash_screen.php', 'dir' );
            break;
        }
      }

      /**
       * Handles saving SM data.
       *
       * @author alim@UD
       */
      public function save_media_settings(){
        if(isset($_POST['action']) && $_POST['action'] == 'stateless_settings' && wp_verify_nonce( $_POST['_smnonce'], 'wp-stateless-settings' )){

          $settings = apply_filters('stateless::settings::save', $_POST['sm']);
          foreach ( $settings as $name => $value ) {
            $option = 'sm_'. $name;

            if($name == 'organize_media'){
              $option = 'uploads_use_yearmonth_folders';
            }
            elseif($name == 'key_json'){
              $value = stripslashes($value);
            }

            // Be sure to cleanup values before saving
            $value = trim($value);

            if(is_network_admin()){
              update_site_option( $option, $value );
            }
            else{
              update_option( $option, $value );
            }
          }

          $this->bootstrap->flush_transients();
          $this->refresh();
        }
      }

      /**
       * Wrapper for setting value.
       * @param string $key
       * @param bool $value
       * @param bool $bypass_validation
       * @return \UsabilityDynamics\Settings
       */
      public function set( $key = '', $value = false, $bypass_validation = false ) {
        return parent::set( $key, $value, $bypass_validation );
      }

    }

  }

}
