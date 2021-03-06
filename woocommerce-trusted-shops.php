<?php
/**
 * Plugin Name: Trustbadge Reviews for WooCommerce
 * Plugin URI: http://www.trustedshops.co.uk/
 * Description: Adds Seller and Product Reviews or Trusted Shops Integration to your WooCommerce Shop.
 * Version: 3.0.2
 * Author: Vendidero
 * Author URI: http://vendidero.de
 * Requires at least: 3.8
 * Tested up to: 5.1
 *
 * Text Domain: woocommerce-trusted-shops
 * Domain Path: /i18n/languages/
 *
 * @author Vendidero
 */
if ( ! defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly

if ( ! class_exists( 'WooCommerce_Trusted_Shops' ) ) :

final class WooCommerce_Trusted_Shops {

	/**
	 * Current WooCommerce Trusted Shops Version
	 *
	 * @var string
	 */
	public $version = '3.0.2';

	/**
	 * Single instance of WooCommerce Trusted Shops Main Class
	 *
	 * @var object
	 */
	protected static $_instance = null;

	public $emails = null;

	public $trusted_shops = null;

    public $compatibilities = array();

	/**
	 * Main WooCommerce_Trusted_Shops Instance
	 *
	 * Ensures that only one instance of WooCommerce_Trusted_Shops is loaded or can be loaded.
	 *
	 * @static
	 * @see WC_trusted_shops()
	 * @return WooCommerce_Trusted_Shops - Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'woocommerce-trusted-shops' ), '1.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'woocommerce-trusted-shops' ), '1.0' );
	}

	/**
	 * Global getter
	 *
	 * @param string  $key
	 * @return mixed
	 */
	public function __get( $key ) {
		return self::$key;
	}

	/**
	 * adds some initialization hooks and inits WooCommerce Trusted Shops
	 */
	public function __construct() {
		// Auto-load classes on demand
		if ( function_exists( "__autoload" ) ) {
			spl_autoload_register( "__autoload" );
		}

		spl_autoload_register( array( $this, 'autoload' ) );

		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
			
		// Check if dependecies are installed
		$init = WC_TS_Dependencies::instance();

		if ( ! $init->is_loadable() )
			return;

		// Define constants
		$this->define_constants();

		// Include required files
		$this->includes();

		// Hooks
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
		add_action( 'init', array( $this, 'init' ), 1 );
        add_action( 'plugins_loaded', array( $this, 'setup_compatibility' ), 0 );
		add_filter( 'woocommerce_locate_template', array( $this, 'filter_templates' ), 0, 3 );

		// Initialize Trusted Shops module
		$this->trusted_shops = new WC_Trusted_Shops( $this, array(
				'supports'	 => array(),
                'signup_url' => 'http://www.trustbadge.com/de/Preise/',
                'path'       => WC_TRUSTED_SHOPS_ABSPATH . 'includes/',
			)
		);

		// Loaded action
		do_action( 'woocommerce_trusted_shops_loaded' );
	}

	/**
	 * Init Trusted Shops when WordPress initializes.
	 */
	public function init() {
		
		// Before init action
		do_action( 'before_woocommerce_trusted_shops_init' );

        $this->load_plugin_textdomain();
		
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_admin_styles' ) );
		add_action( 'admin_init', array( $this, 'redirect_settings' ) );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ), 15 );

        add_action( 'admin_print_styles', array( $this, 'add_notices' ), 1 );

		// Change email template path if is germanized email template
		add_filter( 'woocommerce_template_directory', array( $this, 'set_woocommerce_template_dir' ), 10, 2 );
		add_filter( 'woocommerce_locate_core_template', array( $this, 'set_woocommerce_core_template_dir' ), 10, 3 );

        add_action( 'woocommerce_admin_field_gzd_toggle', array( $this, 'toggle_input' ), 10 );
        add_filter( 'woocommerce_admin_settings_sanitize_option', array( $this, 'save_toggle_input_field' ), 0, 3 );

		// Init action
		do_action( 'woocommerce_trusted_shops_init' );
	}

    /**
     * Add notices + styles if needed.
     */
    public function add_notices() {
        $screen          = get_current_screen();
        $screen_id       = $screen ? $screen->id : '';
        $show_on_screens = array(
            'dashboard',
            'plugins',
        );

        $wc_screen_ids   = function_exists( 'wc_get_screen_ids' ) ? wc_get_screen_ids() : array();

        // Notices should only show on WooCommerce screens, the main dashboard, and on the plugins screen.
        if ( ! in_array( $screen_id, $wc_screen_ids, true ) && ! in_array( $screen_id, $show_on_screens, true ) ) {
            return;
        }

        if ( get_option( '_wc_ts_needs_update' ) == 1 ) {
            if ( current_user_can( 'manage_woocommerce' ) ) {
                wp_enqueue_style( 'woocommerce-activation', plugins_url( '/assets/css/activation.css', WC_PLUGIN_FILE ) );
                wp_enqueue_style( 'woocommerce-gzd-activation', plugins_url( '/assets/css/woocommerce-trusted-shops-activation.css', WC_TRUSTED_SHOPS_PLUGIN_FILE ) );

                add_action( 'admin_notices', array( $this, 'install_notice' ) );
            }
        }
    }

    /**
     * Show the install notices
     */
    public function install_notice() {
        // If we need to update, include a message with the update button
        if ( get_option( '_wc_ts_needs_update' ) == 1 ) {
            include( WC_TRUSTED_SHOPS_ABSPATH . 'includes/admin/views/html-notice-update.php' );
        }
    }

    public function toggle_input( $value ) {
        // Custom attribute handling.
        $custom_attributes = array();

        if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
            foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
                $custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
            }
        }

        // Description handling.
        $field_description = WC_Admin_Settings::get_field_description( $value );
        $description       = $field_description['description'];
        $tooltip_html      = $field_description['tooltip_html'];
        $option_value      = WC_Admin_Settings::get_option( $value['id'], $value['default'] );

        ?><tr valign="top">
        <th scope="row" class="titledesc">
            <span class="wc-gzd-label-wrap"><?php echo esc_html( $value['title'] ); ?> <?php echo $tooltip_html; // WPCS: XSS ok. ?></span>
        </th>
        <td class="forminp forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
            <a href="#" class="woocommerce-gzd-input-toggle-trigger">
                <span id="<?php echo esc_attr( $value['id'] ); ?>-toggle" class="woocommerce-gzd-input-toggle woocommerce-input-toggle woocommerce-input-toggle--<?php echo ( 'yes' === $option_value ? 'enabled' : 'disabled' ); ?>"><?php echo ( 'yes' === $option_value ? _x( 'Yes', 'trusted-shops', 'woocommerce-trusted-shops' ) : _x( 'No', 'trusted-shops', 'woocommerce-trusted-shops' ) ); ?></span>
            </a>
            <input
                name="<?php echo esc_attr( $value['id'] ); ?>"
                id="<?php echo esc_attr( $value['id'] ); ?>"
                type="checkbox"
                style="display: none; <?php echo esc_attr( $value['css'] ); ?>"
                value="1"
                class="<?php echo esc_attr( $value['class'] ); ?>"
                <?php checked( $option_value, 'yes' ); ?>
                <?php echo implode( ' ', $custom_attributes ); // WPCS: XSS ok. ?>
            /><?php echo esc_html( $value['suffix'] ); ?> <?php echo $description; // WPCS: XSS ok. ?>
        </td>
        </tr>
        <?php
    }

    public function save_toggle_input_field( $value, $option, $raw_value ) {
        if ( 'gzd_toggle' === $option['type'] ) {
            $value = '1' === $raw_value || 'yes' === $raw_value ? 'yes' : 'no';
        }

        return $value;
    }

	public function set_woocommerce_core_template_dir( $core_file, $template, $template_base ) {
		if ( ! file_exists( $template_base . $template ) && file_exists( $this->plugin_path() . '/templates/' . $template ) )
			$core_file = $this->plugin_path() . '/templates/' . $template;

		return $core_file;
	}

	public function set_woocommerce_template_dir( $dir, $template ) {
		if ( file_exists( WC_trusted_shops()->plugin_path() . '/templates/' . $template ) )
			return 'woocommerce-trusted-shops';
		return $dir;
	}

	public function admin_footer_text( $footer_text ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Check to make sure we're on a WooCommerce admin page
		if ( isset( $_GET[ 'tab' ] ) && 'trusted-shops' === $_GET[ 'tab' ] ) {
			$footer_text = sprintf( _x( 'If the <strong>App</strong> helped you, please leave a %s&#9733;&#9733;&#9733;&#9733;&#9733;%s in the Wordpress plugin repository.', 'trusted-shops', 'woocommerce-trusted-shops' ), '<a href="https://wordpress.org/support/view/plugin-reviews/woocommerce-trusted-shops?rate=5#postform" target="_blank" class="wc-rating-link">', '</a>' );
		}

		return $footer_text;
	}

	public function redirect_settings() {
		if ( ( isset( $_GET[ 'tab' ] ) && $_GET[ 'tab' ] === 'trusted-shops' && ( ! isset( $_GET[ 'section' ] ) || empty( $_GET[ 'section' ] ) ) ) ) {
			// Redirect
			wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=trusted-shops&section=trusted_shops' ) );
		} 
	}

	/**
	 * Auto-load WC_Trusted_Shops classes on demand to reduce memory consumption.
	 *
	 * @param mixed   $class
	 * @return void
	 */
	public function autoload( $class ) {
        $class = strtolower( $class );
        $path  = $this->plugin_path() . '/includes/';

        if ( 0 !== strpos( $class, 'wc_ts_' ) && 0 !== strpos( $class, 'wc_trusted_shops' ) ) {
            return;
        }

		$file = 'class-' . str_replace( '_', '-', $class ) . '.php';

        if ( strpos( $class, 'wc_ts_compatibility' ) !== false ) {
            $path = $this->plugin_path() . '/includes/compatibility/';
        }

		if ( $path && is_readable( $path . $file ) ) {
			include_once $path . $file;
			return;
		}
	}

	/**
	 * Get the plugin url.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Get the plugin path.
	 *
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Get the language path
	 *
	 * @return string
	 */
	public function language_path() {
		return $this->plugin_path() . '/i18n/languages';
	}

	/**
	 * Define WC_Germanized Constants
	 */
	private function define_constants() {
		define( 'WC_TRUSTED_SHOPS_PLUGIN_FILE', __FILE__ );
        define( 'WC_TRUSTED_SHOPS_ABSPATH', dirname( WC_TRUSTED_SHOPS_PLUGIN_FILE ) . '/' );
		define( 'WC_TRUSTED_SHOPS_VERSION', $this->version );
	}

    public function setup_compatibility() {
        $plugins = apply_filters( 'woocommerce_ts_compatibilities',
            array(
                'wpml-string-translation',
            )
        );

        foreach ( $plugins as $comp ) {
            $classname = str_replace( ' ', '_', 'WC_TS_Compatibility_' . ucwords( str_replace( '-', ' ', $comp ) ) );

            if ( class_exists( $classname ) ) {
                $this->compatibilities[ $comp ] = new $classname();
            }
        }
    }

    public function get_compatibility( $name ) {
        return ( isset( $this->compatibilities[ $name ] ) ? $this->compatibilities[ $name ] : false );
    }

	/**
	 * Include required core files used in admin and on the frontend.
	 */
	private function includes() {
        include_once WC_TRUSTED_SHOPS_ABSPATH . 'includes/abstracts/abstract-wc-ts-compatibility.php';
        include_once WC_TRUSTED_SHOPS_ABSPATH . 'includes/class-wc-ts-install.php';
	}

	/**
	 * Filter WooCommerce Templates to look into /templates before looking within theme folder
	 *
	 * @param string  $template
	 * @param string  $template_name
	 * @param string  $template_path
	 * @return string
	 */
	public function filter_templates( $template, $template_name, $template_path ) {

		if ( ! $template_path ) {
			$template_path = WC()->template_path();
		}

		// Check Theme
		$theme_template = locate_template(
			array(
				trailingslashit( $template_path ) . $template_name,
				$template_name
			)
		);

		// Make filter gzd_compatible
		$template_name = apply_filters( 'woocommerce_trusted_shops_template_name', $template_name );

		// Load Default
		if ( ! $theme_template ) {
			if ( file_exists( $this->plugin_path() . '/templates/' . $template_name ) )
				$template = $this->plugin_path() . '/templates/' . $template_name;
		} else
			$template = $theme_template;

		return apply_filters( 'woocommerce_trusted_shops_filter_template', $template, $template_name, $template_path );
	}

	/**
	 * Load Localisation files for WooCommerce Germanized.
	 */
	public function load_plugin_textdomain() {
        $locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
        $locale = apply_filters( 'plugin_locale', $locale, 'woocommerce-trusted-shops' );

        unload_textdomain( 'woocommerce-trusted-shops' );
        load_textdomain( 'woocommerce-trusted-shops', trailingslashit( WP_LANG_DIR ) . 'woocommerce-trusted-shops/woocommerce-trusted-shops-' . $locale . '.mo' );
        load_plugin_textdomain( 'woocommerce-trusted-shops', false, plugin_basename( dirname( __FILE__ ) ) . '/i18n/languages/' );
	}

	/**
	 * Show action links on the plugin screen
	 *
	 * @param mixed   $links
	 * @return array
	 */
	public function action_links( $links ) {
		return array_merge( array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=trusted-shops&section=trusted_shops' ) . '">' . _x( 'Settings', 'trusted-shops', 'woocommerce-trusted-shops' ) . '</a>',
		), $links );
	}

	/**
	 * Add custom styles to Admin
	 */
	public function add_admin_styles() {
		$screen = get_current_screen();
		
		if ( isset( $_GET[ 'tab' ] ) && $_GET[ 'tab' ] == 'trusted-shops' ) {

		    if ( isset( $_GET[ 'section' ] ) ) {
				$section = sanitize_text_field( $_GET[ 'section' ] );
            }

			if ( $section === 'trusted_shops' ) {
				do_action( 'woocommerce_gzd_load_trusted_shops_script' );
            }
		}
	}

	/**
	 * Add WooCommerce Germanized Settings Tab
	 *
	 * @param array   $integrations
	 * @return array
	 */
	public function add_settings( $integrations ) {
		$integrations[] = new WC_TS_Settings_Handler();
		return $integrations;
	}

	/**
	 * Add Custom Email templates
	 *
	 * @param array   $mails
	 * @return array
	 */
	public function add_emails( $mails ) {
		$mails[ 'WC_TS_Email_Customer_Trusted_Shops' ] = include 'includes/emails/class-wc-ts-email-customer-trusted-shops.php';
		return $mails;
	}
}

endif;

/**
 * Returns the global instance of WooCommerce Germanized
 */
function WC_trusted_shops() {
	return WooCommerce_Trusted_Shops::instance();
}

$GLOBALS['woocommerce_trusted_shops'] = WC_trusted_shops();

?>
