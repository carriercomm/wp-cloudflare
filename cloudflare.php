<?php
/**
 * Plugin Name: CloudFlare
 * Plugin URI:  http://www.cloudflare.com/wiki/CloudFlareWordPressPlugin
 * Description: CloudFlare integrates your blog with the CloudFlare platform.
 * Version:     1.4.0
 * Author:      Ian Pye, Jerome Chen, James Greene (CloudFlare Team)
 * Author URI:  http://www.cloudflare.com
 * License:     GPLv2
 * Text Domain: cloudflare
 * Domain Path: /languages
 */

/*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Make sure we don't expose any info if called directly
if ( ! function_exists( 'add_action' ) ) {
	echo "Hi there!  I'm just a plugin, not much I can do when called directly.";
	exit;
}

require_once 'ip_in_range.php';

class CloudFlare {
	private $version  = '1.4.0';
	private $api_host = 'ssl://www.cloudflare.com';
	private $api_port = 443;

	private $is_cf    = false;
	private $domain;
	private $api_key;
	private $api_email;

	public function __construct() {
		if( isset( $_SERVER["HTTP_CF_CONNECTING_IP"] ) ) {
			$this->is_cf = true;
		}

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		add_action( 'init', array( $this, 'init' ), 1 );
		add_action( 'admin_menu', array( $this, 'add_config_page' ) );

		add_action( 'wp_set_comment_status', array( $this, 'set_comment_status' ), 1, 2 );
	}

	public function load_textdomain() {
		if( is_admin() ) {
			load_plugin_textdomain( 'cloudflare', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}
	}


	public function init() {
		if ( strpos( $_SERVER["REMOTE_ADDR"], ':' ) === false ) {
			$cf_ip_ranges = array(
				'204.93.240.0/24',
				'204.93.177.0/24',
				'199.27.128.0/21',
				'173.245.48.0/20',
				'103.21.244.0/22',
				'103.22.200.0/22',
				'103.31.4.0/22',
				'141.101.64.0/18',
				'108.162.192.0/18',
				'190.93.240.0/20',
				'188.114.96.0/20',
				'197.234.240.0/22',
				'198.41.128.0/17',
				'162.158.0.0/15'
			);

			// IPV4: Update the REMOTE_ADDR value if the current REMOTE_ADDR value is in the specified range.
			foreach ( $cf_ip_ranges as $range ) {
				if ( ipv4_in_range($_SERVER["REMOTE_ADDR"], $range ) ) {
					if ( $_SERVER["HTTP_CF_CONNECTING_IP"] ) {
						$_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_CF_CONNECTING_IP"];
					}

					break;
				}
			}
		}
		else {
			$cf_ip_ranges = array(
				'2400:cb00::/32',
				'2606:4700::/32',
				'2803:f800::/32'
			);

			$ipv6 = get_ipv6_full( $_SERVER["REMOTE_ADDR"] );

			foreach ( $cf_ip_ranges as $range ) {
				if ( ipv6_in_range( $ipv6, $range ) ) {
					if ( $_SERVER["HTTP_CF_CONNECTING_IP"] ) {
						$_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_CF_CONNECTING_IP"];
					}

					break;
				}
			}
		}

		// Let people know that the CF WP plugin is turned on.
		if ( ! headers_sent() ) {
			header( 'X-CF-Powered-By: WP ' . $this->version );
		}
	}

	public function add_config_page() {
		add_submenu_page(
			'plugins.php',
			__( 'CloudFlare Configuration', 'cloudflare' ),
			__( 'CloudFlare', 'cloudflare' ),
			'manage_options',
			'cloudflare',
			array( $this, 'show_config_page' )
		);
	}

	public function show_config_page() {
		global $wpdb;

		if ( ! current_user_can('manage_options') ) {
			die( __( 'Cheatin&#8217; uh?' ) );
		}

		// get raw domain - may include www.
		$urlparts   = parse_url( home_url() );
		$raw_domain = $urlparts["host"];

		$curl_installed = function_exists('curl_init');

		if ( $curl_installed ) {
			// Load the API settings
			$this->load_keys();

			// Attempt to get the matching host from CF
			$this->domain = $this->get_domain( $this->api_key, $this->api_email, $raw_domain );

			// If not found, default to pulling the domain via client side.
			if ( ! $this->domain ) {
				$this->domain = $raw_domain;
			}
		}
		else {
			$this->domain = $raw_domain;    
		}

		$db_results = array();
				   
		if ( isset( $_POST['submit'] ) && check_admin_referer( 'cloudflare-db-api','cloudflare-db-api-nonce' ) ) {
			$key      = $_POST['key'];
			$email    = $_POST['email'];
			$dev_mode = esc_sql( $_POST["dev_mode"] );

			if ( empty( $key ) ) {
				$key_status = 'empty';
				$ms[]       = 'new_key_empty';
				delete_option('cloudflare_api_key');
			}
			else {
				$ms[] = 'new_key_valid';
				update_option( 'cloudflare_api_key', esc_sql( $key) );
				update_option( 'cloudflare_api_key_set_once', "TRUE" );
			}

			if ( empty( $email ) || ! is_email( $email ) ) {
				$email_status = 'empty';
				$ms[]         = 'new_email_empty';
				delete_option('cloudflare_api_email');
			}
			else {
				$ms[] = 'new_email_valid';
				update_option( 'cloudflare_api_email', esc_sql( $email) );
				update_option( 'cloudflare_api_email_set_once', "TRUE" );
			}


			$messages = array(
				'new_key_empty'   => array( 'color' => 'aa0', 'text' => __( 'Your key has been cleared.', 'cloudflare' ) ),
				'new_key_valid'   => array( 'color' => '2d2', 'text' => __('Your key has been verified. Happy blogging!', 'cloudflare' ) ),
				'new_email_empty' => array( 'color' => 'aa0', 'text' => __( 'Your email has been cleared.', 'cloudflare' ) ),
				'new_email_valid' => array( 'color' => '2d2', 'text' => __( 'Your email has been verified. Happy blogging!', 'cloudflare' ) )
			);

			if ( $curl_installed ) {
				if ( $key != '' && $email != '' ) {
					$this->set_dev_mode( esc_sql( $key ), esc_sql( $email ), $this->domain, $dev_mode );

					if ( $dev_mode ) {
						$ms[] = 'dev_mode_on';
					}
					else {
						$ms[] = 'dev_mode_off';
					}
				}

				$messages['dev_mode_on']  = array( 'color' => '2d2', 'text' => __( 'Development mode is On. Happy blogging!', 'cloudflare' ) );
				$messages['dev_mode_off'] = array( 'color' => 'aa0', 'text' => __( 'Development mode is Off. Happy blogging!', 'cloudflare' ) );
			}
		}
		?>

		<?php if ( ! empty( $_POST['submit'] ) ) { ?>
			<div id="message" class="updated fade"><p><strong><?php _e( 'Options saved.', 'cloudflare' ) ?></strong></p></div>
		<?php } ?>

		<div class="wrap">

			<?php if ( $this->is_cf ) { ?>
				<h3><?php _e( 'You are currently using CloudFlare!', 'cloudflare' ); ?></h3>
			<?php } ?>

			<h4><?php _e( 'CLOUDFLARE WORDPRESS PLUGIN:', 'cloudflare' ); ?></h4>

			<?php _e( 'CloudFlare has developed a plugin for WordPress. By using the CloudFlare WordPress Plugin, you receive:', 'cloudflare' ); ?> 
			<ol>
				<li><?php _e( 'Correct IP Address information for comments posted to your site', 'cloudflare' ); ?></li>
				<li><?php _e( 'Better protection as spammers from your WordPress blog get reported to CloudFlare', 'cloudflare' ); ?></li>
			</ol>

			<h4><?php _e( 'VERSION COMPATIBILITY:', 'cloudflare' ); ?></h4>

			<?php _e( 'The plugin is compatible with WordPress version 2.8.6 and later. The plugin will not install unless you have a compatible platform.', 'cloudflare' ); ?>

			<h4><?php _e( 'THINGS YOU NEED TO KNOW:', 'cloudflare' ); ?></h4>

			<ol>
				<li><?php _e( "The main purpose of this plugin is to ensure you have no change to your originating IPs when using CloudFlare. Since CloudFlare acts a reverse proxy, connecting IPs now come from CloudFlare's range. This plugin will ensure you can continue to see the originating IP. Once you install the plugin, the IP benefit will be activated.", 'cloudflare' ); ?></li>

				<li><?php _e( "Every time you click the 'spam' button on your blog, this threat information is sent to CloudFlare to ensure you are constantly getting the best site protection.", 'cloudflare' ); ?></li>

				<li><?php _e( 'We recommend that any user on CloudFlare with WordPress use this plugin.', 'cloudflare' ); ?></li>

				<li><?php _e( 'NOTE: This plugin is complementary to Akismet and W3 Total Cache. We recommend that you continue to use those services.', 'cloudflare' ); ?></li> 
			</ol>

			<h4><?php _e( 'MORE INFORMATION ON CLOUDFLARE:', 'cloudflare' ); ?></h4>

			<?php printf( __( 'CloudFlare is a service that makes websites load faster and protects sites from online spammers and hackers. Any website with a root domain (ie www.mydomain.com) can use CloudFlare. On average, it takes less than 5 minutes to sign up. You can learn more here: <a href="%1$s">CloudFlare.com</a>.', 'cloudflare' ), 'http://www.cloudflare.com/' ); ?>

			<?php 
				// Load the API settings
				$this->load_keys();

				$dev_mode = 'off';
				if ( $curl_installed && $this->api_key && $this->api_email ) {
					$dev_mode = $this->get_dev_mode_status( $this->api_key, $this->api_email, $this->domain );
				}
			?>

			<hr />

			<form action="" method="post" id="cloudflare-conf">
			<?php wp_nonce_field( 'cloudflare-db-api','cloudflare-db-api-nonce' ); ?>

			<?php if ( ! $this->api_key || ! $this->api_email ) { ?>
				<p><?php printf( __( 'Input your API key from your CloudFlare Accounts Settings page here. To find your API key, log in to <a href="%1$s">CloudFlare</a> and go to \'Account\'.', 'cloudflare' ), 'https://www.cloudflare.com/my-account.html' ); ?></p>
			<?php } ?>

			<?php if ( isset( $ms ) ) { foreach ( $ms as $m ) { ?>
			<p style="padding: .5em; color: #<?php echo $messages[ $m ]['color']; ?>; font-weight: bold;"><?php echo $messages[ $m ]['text']; ?></p>
			<?php } } ?>

			<h3>
				<label for="key"><?php _e( 'CloudFlare API Key', 'cloudflare' ); ?></label>
			</h3>
			<p>
				<input id="key" name="key" type="text" size="50" maxlength="48" value="<?php echo $this->api_key; ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" />
				('<a href="https://www.cloudflare.com/my-account.html"><?php _e( 'Get this?', 'cloudflare' ); ?></a>)
			</p>

			<h3>
				<label for="email"><?php _e( 'CloudFlare API Email', 'cloudflare' ); ?></label>
			</h3>
			<p>
				<input id="email" name="email" type="text" size="50" maxlength="48" value="<?php echo $this->api_email; ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" />
				('<a href="https://www.cloudflare.com/my-account.html"><?php _e( 'Get this?', 'cloudflare' ); ?></a>)
			</p>

			<h3>
				<label for="dev_mode"><?php _e( 'Development Mode', 'cloudflare' ); ?></label>
				<span style="font-size:9pt;">(<a href="https://support.cloudflare.com/entries/22280726-what-does-cloudflare-development-mode-mean" target="_blank"><?php _e( 'What is this?', 'cloudflare' ); ?></a>)</span>
			</h3>

			<?php if ( $curl_installed ) { ?>
			<div style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;">
				<input type="radio" name="dev_mode" value="0" <? if ($dev_mode == "off") echo "checked"; ?>> <?php _e( 'Off', 'cloudflare' ); ?>
				<input type="radio" name="dev_mode" value="1" <? if ($dev_mode == "on") echo "checked"; ?>> <?php _e( 'On', 'cloudflare' ); ?>
			</div>
			<?php
			} else {
				_e( 'You cannot toggle development mode because cURL is not installed for your domain.  Please contact a server administrator for assistance with installing cURL.', 'cloudflare' );
			}
			?>

			</p>

			<p class="submit"><input type="submit" name="submit" value="<?php _e( 'Update options &raquo;', 'cloudflare' ); ?>" /></p>

			</form>

		</div>
		<?php
	}

	// Now actually allow CF to see when a comment is approved/not-approved.
	public function set_comment_status( $id, $status ) {
		$this->load_keys();

		if ( ! $this->api_key || ! $this->api_email ) {
			return;
		}

		// ajax/external-event.html?email=ian@cloudflare.com&t=94606855d7e42adf3b9e2fd004c7660b941b8e55aa42d&evnt_v={%22dd%22:%22d%22}&evnt_t=WP_SPAM
		$comment = get_comment( $id );
		$value   = array(
			'a'   => $comment->comment_author, 
			'am'  => $comment->comment_author_email,
			'ip'  => $comment->comment_author_IP,
			'con' => substr( $comment->comment_content, 0, 100 )
		);
		$url = "/ajax/external-event.html?evnt_v=" . urlencode( json_encode( $value ) ) . "&u=$cloudflare_api_email&tkn=$cloudflare_api_key&evnt_t=";

		// If spam, send this info over to CloudFlare.
		if ( $status == 'spam' ) {
			$url .= "WP_SPAM";
			$fp   = @fsockopen( $this->api_host, $this->api_port, $errno, $errstr, 30 );

			if ( $fp ) {
				$out  = "GET $url HTTP/1.1\r\n";
				$out .= "Host: www.cloudflare.com\r\n";
				$out .= "Connection: Close\r\n\r\n";
				fwrite($fp, $out);
				$res = '';

				while ( ! feof( $fp ) ) {
					$res .= fgets( $fp, 128 );
				}

				fclose( $fp );
			}
		}
	}


	private function load_keys() {
		if ( ! $this->api_key ) {
			$this->api_key = get_option('cloudflare_api_key');
		}

		if ( ! $this->api_email ) {
			$this->api_email = get_option('cloudflare_api_email');
		}
	}

	private function get_dev_mode_status($token, $email, $zone) {
		$url = 'https://www.cloudflare.com/api_json.html';
		$fields = array(
			'a'     => "zone_load",
			'tkn'   => $token,
			'email' => $email,
			'z'     => $zone
		);

		$fields_string = '';
		foreach($fields as $key=>$value) { 
			$fields_string .= $key.'='.$value.'&';
		}

		rtrim( $fields_string, '&' );
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POST, count( $fields ) );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields_string );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
		$result = curl_exec( $ch );
		$result = json_decode( $result );
		curl_close( $ch );

		if ( isset( $result->response ) ) {
			if ( $result->response->zone->obj->zone_status_class == 'status-dev-mode' ) {
				return "on";
			}
		}

		return "off";
	}

	private function set_dev_mode( $token, $email, $zone, $value ) {
		$url = 'https://www.cloudflare.com/api_json.html';
		$fields = array(
			'a'=>"devmode",
			'tkn'=>$token,
			'email'=>$email,
			'z'=>$zone,
			'v'=>$value
		);

		$fields_string = '';
		foreach($fields as $key=>$value) { 
			$fields_string .= $key.'='.$value.'&';
		}

		rtrim( $fields_string,'&' );
		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_POST,count($fields));
		curl_setopt($ch,CURLOPT_POSTFIELDS,$fields_string);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$result = curl_exec($ch);
		$result = json_decode($result);
		curl_close($ch);
	}

	private function get_domain( $token, $email, $raw_domain ) {
		$url = 'https://www.cloudflare.com/api_json.html';
			$fields = array(
			'a'     => "zone_load_multi",
			'tkn'   => $token,
			'email' => $email
		);

		$fields_string = '';
		foreach($fields as $key=>$value) { 
			$fields_string .= $key.'='.$value.'&';
		}

		rtrim($fields_string, '&');

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POST,count( $fields ) );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields_string );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
		$result = curl_exec( $ch );
		$result = json_decode( $result );
		curl_close( $ch );

		if ( isset( $result->response ) ) {
			$zone_count = $result->response->zones->count;
			if ( $zone_count > 0 ) {
				for ( $i = 0; $i < $zone_count; $i++ ) {
					$zone_name = $result->response->zones->objs[$i]->zone_name;

					if ( strpos( $raw_domain, $zone_name ) !== fasle ) {
						return $zone_name;
					}
				}
			}
		}

		return null;
	}

}

$cloudflare = new CloudFlare;
