<?php

/**
 * Plugin Name: PartsPal - Smarter Auto Parts
 * Plugin URI:  https://www.parts-pal.com/
 * Description: Add data fitment and vehicle compatibility to your store.
 * Version:     0.3.2
 * Author:      Partly Group Limited
 * Author URI:  https://partly.co.nz/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

/*
The PartsPal WordPress plugin is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

The PartsPal WordPress plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with the PartsPal WordPress plugin. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

// ======================================== Constants ========================================

//define("PARTSPAL_PROXY_DOMAIN", 'http://localhost:4200');
//define('PARTSPAL_PROXY_DOMAIN', 'https://proxy.partly.pro');
define('PARTSPAL_PROXY_DOMAIN', 'https://proxy.partly.co.nz');
//define('PARTSPAL_API_DOMAIN', 'https://api.partly.pro');
define('PARTSPAL_API_DOMAIN', 'https://api.parts-pal.com');
define('PARTSPAL_BASE_URL', '/a/partspal');
define('PARTSPAL_DEFAULT_MAIN_SELECTOR', '#site-content');
define('PARTSPAL_DEFAULT_VIN_ENABLED', 1);
define('PARTSPAL_DEFAULT_HAS_BEEN_SETUP', false);

// ======================================== App proxy ========================================

function partspal_is_partspal_url()
{
	return substr($_SERVER['REQUEST_URI'], 0, strlen(PARTSPAL_BASE_URL)) === PARTSPAL_BASE_URL;
}

function partspal_override_404()
{
	global $wp_query;

	if (partspal_is_partspal_url()) {
		status_header(200);
		$wp_query->is_404 = false;
	}
}

add_filter('template_redirect', 'partspal_override_404');

function partspal_alter_the_query($request)
{
	// WordPress takes the query parameter `p` as the page number
	// If using the app proxy, we want this parameter to be only used by the app proxy
	if (partspal_is_partspal_url()) {
		unset($request['p']);
	}
	return $request;
}

add_filter('request', 'partspal_alter_the_query');

ob_start();

add_action(
	'shutdown',
	function () {
		$final = '';

		// We'll need to get the number of ob levels we're in, so that we can iterate over each, collecting
		// that buffer's output into the final output.
		$levels = ob_get_level();

		for ($i = 0; $i < $levels; $i++) {
			$final .= ob_get_clean();
		}

		// Apply any filters to the final output
		echo apply_filters('final_output', $final);
	},
	0
);

function partspal_get_main_selector_node($doc)
{
	$main_selector = trim(get_option('partspal_main_selector', PARTSPAL_DEFAULT_MAIN_SELECTOR));

	switch ($main_selector[0]) {
		case '#': # id
			$selector = substr($main_selector, 1);
			return $doc->getElementById($selector);
		case '.': # class
			$selector = substr($main_selector, 1);
			$finder = new DomXPath($doc);
			$nodes = $finder->query(
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' $selector ')]"
			);
			return $nodes->item(0);
		default:
			# tag
			return $doc->getElementsByTagName($main_selector)->item(0);
	}
}

function partspal_fill_main_selector_with_ssr_content($output)
{
	if (partspal_is_partspal_url()) {
		$doc = new DOMDocument();
		@$doc->loadHTML($output);

		// Get content to inject
		$href = explode(PARTSPAL_BASE_URL, $_SERVER['REQUEST_URI'])[1];
		$req_url = PARTSPAL_PROXY_DOMAIN . '/wordpress' . PARTSPAL_BASE_URL . $href;
		$req_url .=
			(parse_url($req_url, PHP_URL_QUERY) ? '&shop=' : '?shop=') .
			get_option('partspal_store_alias', str_replace(array('http://', 'https://'), '', get_site_url()));
		$response = wp_remote_get($req_url);
		$response_body =
			!is_wp_error($response) && array_key_exists('body', $response)
				? $response['body']
				: '<p style="text-align: center"><b>⚠ Error: could not load PartsPal content.</b></p>';

		// Remove main selector's children and replace with dummy element
		$site_content = partspal_get_main_selector_node($doc);
		$dummyElement = '<div>DUMMY ELEMENT</div>';
		$fragment = $site_content->ownerDocument->createDocumentFragment();
		$fragment->appendXML($dummyElement);
		while ($site_content->hasChildNodes()) {
			$site_content->removeChild($site_content->firstChild);
		}
		$site_content->appendChild($fragment);

		// Replace dummy element with content
		$output = str_replace($dummyElement, $response_body, $doc->saveHTML());
	}
	return $output;
}

add_filter('final_output', 'partspal_fill_main_selector_with_ssr_content');

// ======================================== POST endpoint ========================================

function partspal_register_key(WP_REST_Request $request)
{
	// Requests will be of the format:
	//  {
	//    "key_id": 1,
	//    "user_id": 123,
	//    "consumer_key": "ck_xxxxxxxxxxxxxxxx",
	//    "consumer_secret": "cs_xxxxxxxxxxxxxxxx",
	//    "key_permissions": "read_write"
	//  }
	update_option('partspal_woocommerce_consumer_key', $request['consumer_key']);
	update_option('partspal_woocommerce_consumer_secret', $request['consumer_secret']);

	$WORDPRESS_INSTALL_URL = PARTSPAL_API_DOMAIN . '/apps/wordpress/install';

	$params = [
		'key_id' => $request['key_id'],
		'user_id' => $request['user_id'],
		'consumer_key' => $request['consumer_key'],
		'consumer_secret' => $request['consumer_secret'],
		'key_permissions' => $request['key_permissions'],
		'is_valid' => true,
		'body_params' => $request->get_body_params(),
	];

	$body = wp_json_encode($params);

	wp_remote_post($WORDPRESS_INSTALL_URL, [
		'body' => $body,
		'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
		'method' => 'POST',
		'data_format' => 'body',
	]);
}

function register_partspal_register_key_route()
{
	register_rest_route('partspal/v1', '/register-key', [
		'methods' => 'POST',
		'callback' => 'partspal_register_key',
	]);
}

add_action('rest_api_init', 'register_partspal_register_key_route');

// ======================================== Plugin lifecycle hooks ========================================

function partspal_clear_options()
{
	$settingOptions = [
		'partspal_store_alias',
		'partspal_vin',
		'partspal_theme',
		'partspal_main_selector',
		'partspal_navigation_callback',
		'partspal_has_been_setup',
		'partspal_woocommerce_consumer_key',
		'partspal_woocommerce_consumer_secret',
	];

	foreach ($settingOptions as $settingName) {
		delete_option($settingName);
	}
}

register_uninstall_hook(__FILE__, 'partspal_clear_options');

// ======================================== Settings page ========================================

function partspal_settings_init()
{
	// register a new section in the "partspal-options" page
	add_settings_section(
		'partspal_settings_section',
		'',
		'partspal_settings_section_cb',
		'partspal-options'
	);

	// Store alias setting

	register_setting('partspal-options', 'partspal_store_alias');

	add_settings_field(
		'partspal_store_alias_field',
		'Store alias',
		'partspal_store_alias_field_cb',
		'partspal-options',
		'partspal_settings_section'
	);

	// VIN setting

	register_setting('partspal-options', 'partspal_vin');

	add_settings_field(
		'partspal_vin_field',
		'VIN search',
		'partspal_vin_field_cb',
		'partspal-options',
		'partspal_settings_section'
	);

	// Theme setting

	register_setting('partspal-options', 'partspal_theme');

	add_settings_field(
		'partspal_theme_field',
		'Theme',
		'partspal_theme_field_cb',
		'partspal-options',
		'partspal_settings_section'
	);

	// Main selector setting

	register_setting('partspal-options', 'partspal_main_selector');

	add_settings_field(
		'partspal_main_selector_field',
		'Main selector',
		'partspal_main_selector_field_cb',
		'partspal-options',
		'partspal_settings_section'
	);

	// Navigation callback setting

	register_setting('partspal-options', 'partspal_navigation_callback', [
		'sanitize_callback' => 'partspal_sanitize_callback',
	]);

	add_settings_field(
		'partspal_navigation_callback_field',
		'Navigation callback',
		'partspal_navigation_callback_field_cb',
		'partspal-options',
		'partspal_settings_section'
	);

	// Hidden setting - has set up PartsPal or not

	register_setting('partspal-options', 'partspal_has_been_setup');
}

function partspal_sanitize_callback($data)
{
	// todo uncomment if some php function `is_invalid_code($data)` can be created to check if js`Function($data)` throws an error
	// if (is_invalid_code($data)) {
	//     $message = "Navigation callback must be a valid function. Please enter valid JavaScript code or clear the textbox.";
	//     $type = "error";
	//     add_settings_error(
	//         'partspal_navigation_callback',
	//         esc_attr('settings_updated'),
	//         $message,
	//         $type
	//     );
	// }
	return $data;
}

add_action('admin_init', 'partspal_settings_init');

/**
 * callback functions
 */

// section content callback
function partspal_settings_section_cb()
{
	echo '';
}

// field content callbacks

function partspal_store_alias_field_cb()
{
	// get the value of the setting we've registered with register_setting()
	$setting = get_option('partspal_store_alias', str_replace(array('http://', 'https://'), '', get_site_url()));// output the field
	?>
	<input type="text" name="partspal_store_alias" value="<?php echo isset($setting)
		? esc_attr($setting)
		: ''; ?>" style="width: 100%">
	<?php
}

function partspal_vin_field_cb()
{
	$setting = get_option('partspal_vin', PARTSPAL_DEFAULT_VIN_ENABLED); ?>
	<input type="checkbox" name="partspal_vin" value="1" <?php echo checked(1, $setting, false); ?>>
	<label for="partspal_vin">Enable VIN search</label>
	<?php
}

function partspal_theme_field_cb()
{
	$setting = get_option('partspal_theme'); ?>
	<select id="partspal_theme" name="partspal_theme">
		<option value="" <?php echo selected('', $setting, false); ?>>None</option>
		<option value="theme_ford" <?php echo selected('theme_ford', $setting, false); ?>>Ford</option>
	</select>
	<?php
}

function partspal_main_selector_field_cb()
{
	// get the value of the setting we've registered with register_setting()
	$setting = get_option('partspal_main_selector', PARTSPAL_DEFAULT_MAIN_SELECTOR);// output the field
	?>
	<input type="text" name="partspal_main_selector" value="<?php echo isset($setting)
		? esc_attr($setting)
		: ''; ?>">
	<p>The selector for the main content DOM. This will be replaced with content from PartsPal.</p>
	<?php
}

function partspal_navigation_callback_field_cb()
{
	// get the value of the setting we've registered with register_setting()
	$setting = get_option('partspal_navigation_callback', null);// output the field
	?>
	<p style="font-family: monospace">function(url) {</p>
  <textarea id="nav_callback_textarea" style="resize: none; margin: 0 4ch; width:calc(100% - 4.1ch); white-space: pre; font-family: monospace;" type="text" name="partspal_navigation_callback" onkeyup="textAreaAdjust(this)" onshow="textAreaAdjust(this)"><?php echo isset(
			$setting
		)
			? esc_attr($setting)
			: ''; ?></textarea>
  <p id="js_validity" style="margin-left: 4ch;"> </p>
  <p style="font-family: monospace">}</p>

	<p>A function to run when navigating to a PartsPal page. Leave empty if not required.</p>
	<script>
		function textAreaAdjust(element) {
			// Adjust height to fit content (including scrollbar, if required)
			element.style.height = '1px';
			let padding = 25;
			if (element.scrollWidth > element.clientWidth) {
				padding += 25;
			}
			element.style.height = (padding + element.scrollHeight) + 'px';

			// Check for syntactically valid JS code
			const js_validity_message = document.getElementById('js_validity');
			try {
				Function(element.value);
				js_validity_message.innerHTML = '';
			} catch (syntaxError) {
				js_validity_message.innerHTML = '<b>❌ Invalid JavaScript function: </b><span style="font-family: monospace">' + syntaxError + '</span>';
			}
		}
		textAreaAdjust(document.getElementById('nav_callback_textarea'));
	</script>
	<?php
}

// Add PartsPal submenu

function partspal_add_settings_submenu()
{
	add_submenu_page(
		'tools.php',
		'PartsPal',
		'PartsPal',
		'manage_options',
		'partspal',
		'partspal_gen_settings_page'
	);
}

function partspal_gen_settings_page()
{
	$store_url = get_site_url();
	$endpoint = '/wc-auth/v1/authorize';
	$params = [
		'app_name' => 'PartsPal',
		'scope' => 'read_write', // read, write, or read_write
		'user_id' => get_site_url(),
		'return_url' => admin_url('tools.php?page=partspal'),
		'callback_url' => rest_url('partspal/v1/register-key'),
	];
	$query_string = http_build_query($params);
	?>
	<div class="wrap">
		<h1>PartsPal</h1>
		<?php if (get_option('partspal_has_been_setup', PARTSPAL_DEFAULT_HAS_BEEN_SETUP)) { ?>
			<p><b>✅ Connected to WooCommerce</b></p>

			<p><a class="button button-primary"
						href="<?php echo PARTSPAL_API_DOMAIN . "/apps/wordpress?wordpress_key=" . get_option(
								'partspal_woocommerce_consumer_key'
							); ?>" target="_blank">
					Open PartsPal dashboard
				</a></p>
		<?php } else { ?>
			<p>
				<a class="button button-primary" href="<?php echo $store_url .
					$endpoint .
					'?' .
					$query_string; ?>">
					Connect PartsPal to WooCommerce
				</a></p>
		<?php } ?>

		<hr>

		<h2>Settings</h2>

		<form action="options.php" method="post">
			<?php
			// output security fields for the registered setting "partspal-options"
			settings_fields('partspal-options');
			// output setting sections and their fields
			// (sections are registered for "partspal-options", each field is registered to a specific section)
			do_settings_sections('partspal-options');
			// output save settings button
			submit_button('Save Changes'); ?>
		</form>
	</div>
	<?php
}

add_action('admin_menu', 'partspal_add_settings_submenu');

function partspal_process_woocommerce_return()
{
	if (
		is_admin() &&
		is_user_logged_in() &&
		current_user_can('edit_pages') &&
		get_current_screen()->id == 'tools_page_partspal' &&
		isset($_GET['success']) &&
		$_GET['success'] == 1
	) {
		update_option('partspal_has_been_setup', true);
	}
}

add_action('current_screen', 'partspal_process_woocommerce_return');

// Display notice to set up PartsPal

function partspal_display_admin_notice()
{
	if (
		get_current_screen()->id != 'tools_page_partspal' &&
		!get_option('partspal_has_been_setup', PARTSPAL_DEFAULT_HAS_BEEN_SETUP)
	) { ?>
		<div class="notice notice-success">
			<p>
				PartsPal installed successfully.
				<a href="<?php echo admin_url('tools.php?page=partspal'); ?>">Set up PartsPal</a>
			</p>
		</div>
	<?php }
}

add_action('admin_notices', 'partspal_display_admin_notice');

// Load script loader with script params

function load_script()
{
	$script_params = [
		'baseUrl' => PARTSPAL_BASE_URL,
		'externalPlatform' => 'wordpress',
		'storeAlias' => get_option('partspal_store_alias'),
		'disableVin' => get_option('partspal_vin', PARTSPAL_DEFAULT_VIN_ENABLED) != 1,
		'theme' => get_option('partspal_theme'),
		'mainSelector' => get_option('partspal_main_selector', PARTSPAL_DEFAULT_MAIN_SELECTOR),
		'navigationCallback' => get_option('partspal_navigation_callback'),
	];

	wp_enqueue_script('script-loader', plugins_url('script-loader-wordpress.js', __FILE__));
	wp_localize_script('script-loader', 'scriptParams', $script_params);
}

add_action('wp_enqueue_scripts', 'load_script');
