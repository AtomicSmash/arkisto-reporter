<?php
/**
 * Plugin Name: Arkisto Plugin Manager
 * Description: Reports back installed plugin information to Arkisto for tracking and updating plugins.
 * Version: 1.0.0
 * Author: Atomic Smash
 * Text Domain: arkisto-reporter
 */
if (! defined('ABSPATH')) {
    exit;
}

define('ARKISTO_REPORTER_VERSION', '1.0.0');
define('ARKISTO_REPORTER_OPTION', 'arkisto_reporter_settings');
define('ARKISTO_REPORTER_DEFAULT_API_BASE_URL', 'https://arkisto.atomicsmash.co.uk');

/**
 * Register settings page.
 */
add_action('admin_menu', function () {
    add_options_page(
        'Arkisto Reporter',
        'Arkisto Reporter',
        'manage_options',
        'arkisto-reporter',
        'arkisto_reporter_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('arkisto_reporter', ARKISTO_REPORTER_OPTION, [
        'type' => 'array',
        'sanitize_callback' => 'arkisto_reporter_sanitize_settings',
        'default' => [
            'api_base_url' => ARKISTO_REPORTER_DEFAULT_API_BASE_URL,
            'registration_token' => '',
            'site_token' => '',
            'site_name' => get_bloginfo('name'),
        ],
    ]);
});

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function arkisto_reporter_sanitize_settings($input)
{
    $current = get_option(ARKISTO_REPORTER_OPTION, []);

    $api_base_url = isset($input['api_base_url'])
        ? esc_url_raw(untrailingslashit($input['api_base_url']))
        : ($current['api_base_url'] ?? ARKISTO_REPORTER_DEFAULT_API_BASE_URL);

    if ($api_base_url === '') {
        $api_base_url = ARKISTO_REPORTER_DEFAULT_API_BASE_URL;
    }

    return [
        'api_base_url' => $api_base_url,
        'registration_token' => isset($input['registration_token']) ? sanitize_text_field($input['registration_token']) : ($current['registration_token'] ?? ''),
        'site_token' => isset($input['site_token']) ? sanitize_text_field($input['site_token']) : ($current['site_token'] ?? ''),
        'site_name' => isset($input['site_name']) ? sanitize_text_field($input['site_name']) : ($current['site_name'] ?? get_bloginfo('name')),
    ];
}

/**
 * @return array<string, mixed>
 */
function arkisto_reporter_get_settings()
{
    $settings = get_option(ARKISTO_REPORTER_OPTION, []);

    if (! is_array($settings)) {
        $settings = [];
    }

    if (empty($settings['api_base_url'])) {
        $settings['api_base_url'] = ARKISTO_REPORTER_DEFAULT_API_BASE_URL;
    }

    if (empty($settings['site_name'])) {
        $settings['site_name'] = get_bloginfo('name');
    }

    return $settings;
}

function arkisto_reporter_render_settings_page()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $settings = arkisto_reporter_get_settings();
    ?>
    <div class="wrap">
        <h1>Arkisto Reporter</h1>
        <form method="post" action="options.php">
            <?php settings_fields('arkisto_reporter'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="arkisto_api_base_url">API base URL</label></th>
                    <td><input class="regular-text" type="url" id="arkisto_api_base_url" name="<?php echo esc_attr(ARKISTO_REPORTER_OPTION); ?>[api_base_url]" value="<?php echo esc_attr($settings['api_base_url']); ?>" placeholder="<?php echo esc_attr(ARKISTO_REPORTER_DEFAULT_API_BASE_URL); ?>" />
                    <p class="description">Defaults to <?php echo esc_html(ARKISTO_REPORTER_DEFAULT_API_BASE_URL); ?>. Override only if pointing at a different Arkisto instance.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="arkisto_registration_token">Registration token</label></th>
                    <td><input class="regular-text" type="text" id="arkisto_registration_token" name="<?php echo esc_attr(ARKISTO_REPORTER_OPTION); ?>[registration_token]" value="<?php echo esc_attr($settings['registration_token'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="arkisto_site_token">Site token</label></th>
                    <td><input class="regular-text" type="text" id="arkisto_site_token" name="<?php echo esc_attr(ARKISTO_REPORTER_OPTION); ?>[site_token]" value="<?php echo esc_attr($settings['site_token'] ?? ''); ?>" />
                    <p class="description">Filled automatically after registration. Paste manually if you already have one.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="arkisto_site_name">Site name</label></th>
                    <td><input class="regular-text" type="text" id="arkisto_site_name" name="<?php echo esc_attr(ARKISTO_REPORTER_OPTION); ?>[site_name]" value="<?php echo esc_attr($settings['site_name'] ?? get_bloginfo('name')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button('Save settings'); ?>
        </form>
        <form method="post">
            <?php wp_nonce_field('arkisto_reporter_actions'); ?>
            <p>
                <button class="button" name="arkisto_reporter_action" value="register">Register site</button>
                <button class="button button-primary" name="arkisto_reporter_action" value="heartbeat">Send heartbeat now</button>
            </p>
        </form>
    </div>
    <?php
}

add_action('admin_init', function () {
    if (! isset($_POST['arkisto_reporter_action']) || ! current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('arkisto_reporter_actions');

    $action = sanitize_text_field(wp_unslash($_POST['arkisto_reporter_action']));

    if ($action === 'register') {
        $result = arkisto_reporter_register_site();
    } else {
        $result = arkisto_reporter_send_heartbeat();
    }

    add_settings_error(
        'arkisto_reporter',
        'arkisto_reporter_notice',
        is_wp_error($result) ? $result->get_error_message() : 'Arkisto request completed successfully.',
        is_wp_error($result) ? 'error' : 'success'
    );
});

add_action('admin_notices', function () {
    settings_errors('arkisto_reporter');
});

/**
 * Schedule daily heartbeat.
 */
add_action('init', function () {
    if (! wp_next_scheduled('arkisto_reporter_daily_heartbeat')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'arkisto_reporter_daily_heartbeat');
    }
});

add_action('arkisto_reporter_daily_heartbeat', 'arkisto_reporter_send_heartbeat');

register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('arkisto_reporter_daily_heartbeat');

    if ($timestamp) {
        wp_unschedule_event($timestamp, 'arkisto_reporter_daily_heartbeat');
    }
});

/**
 * @return true|WP_Error
 */
function arkisto_reporter_register_site()
{
    $settings = arkisto_reporter_get_settings();
    $api = untrailingslashit($settings['api_base_url'] ?? '');
    $token = $settings['registration_token'] ?? '';

    if ($token === '') {
        return new WP_Error('arkisto_missing', 'Registration token is required.');
    }

    $response = wp_remote_post($api.'/sites/register', [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body' => wp_json_encode([
            'registration_token' => $token,
            'name' => $settings['site_name'] ?? get_bloginfo('name'),
            'url' => home_url('/'),
        ]),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || empty($body['token'])) {
        return new WP_Error('arkisto_register_failed', 'Registration failed: '.wp_remote_retrieve_body($response));
    }

    $settings['site_token'] = $body['token'];
    update_option(ARKISTO_REPORTER_OPTION, $settings);

    return true;
}

/**
 * @return true|WP_Error
 */
function arkisto_reporter_send_heartbeat()
{
    $settings = arkisto_reporter_get_settings();
    $api = untrailingslashit($settings['api_base_url'] ?? '');
    $token = $settings['site_token'] ?? '';

    if ($token === '') {
        return new WP_Error('arkisto_missing', 'Site token is required. Register the site first.');
    }

    if (! function_exists('get_plugins')) {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
    }

    $all = get_plugins();
    $active = (array) get_option('active_plugins', []);
    $plugins = [];

    foreach ($all as $file => $data) {
        $slug = dirname($file);

        if ($slug === '.' || $slug === '') {
            $slug = basename($file, '.php');
        }

        $plugins[] = [
            'slug' => $slug,
            'name' => $data['Name'] ?? $slug,
            'version' => $data['Version'] ?? null,
            'active' => in_array($file, $active, true),
        ];
    }

    $response = wp_remote_post($api.'/sites/heartbeat', [
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ],
        'body' => wp_json_encode([
            'name' => $settings['site_name'] ?? get_bloginfo('name'),
            'url' => home_url('/'),
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'plugins' => $plugins,
        ]),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code < 200 || $code >= 300) {
        return new WP_Error('arkisto_heartbeat_failed', 'Heartbeat failed: '.wp_remote_retrieve_body($response));
    }

    return true;
}
