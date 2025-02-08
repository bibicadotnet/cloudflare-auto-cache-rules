<?
/**
 * Plugin Name: Cloudflare Auto Cache Rules
 * Description: Tự động cấu hình Cache Rules trên Cloudflare cho WordPress
 * Version: 1.0
 * Author: bibica
 * Author URI: https://bibica.net
 * Plugin URI: https://bibica.net/cloudflare-auto-cache-rules
 * Text Domain: cloudflare-auto-cache-rules
 * License: GPL-3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

if (!defined('ABSPATH')) {
    exit;
}

// Thêm menu vào Tools
add_action('admin_menu', 'accr_add_admin_menu');
function accr_add_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Cloudflare Auto Cache Rules Setting',
        'Cloudflare Auto Cache Rules',
        'manage_options',
        'cloudflare-auto-cache-rules',
        'accr_admin_page'
    );
}

// Hiển thị trang cấu hình
function accr_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Xử lý lưu cấu hình
    if (isset($_POST['accr_save_settings'])) {
        check_admin_referer('accr_save_settings');
        
        $api_token = sanitize_text_field($_POST['accr_cloudflare_api_token']);
        $email = sanitize_email($_POST['accr_cloudflare_email']);
        $zone_id = sanitize_text_field($_POST['accr_cloudflare_zone_id']);

        // Kiểm tra thông tin API và Zone ID
        $validation_result = accr_validate_cloudflare_credentials($api_token, $email, $zone_id);
        if ($validation_result === true) {
            update_option('accr_cloudflare_api_token', $api_token);
            update_option('accr_cloudflare_email', $email);
            update_option('accr_cloudflare_zone_id', $zone_id);
            echo '<div class="notice notice-success"><p>Cấu hình đã được lưu và xác thực thành công!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html($validation_result) . '</p></div>';
        }
    }

    // Xử lý tạo Cache Rule thủ công
    if (isset($_POST['accr_create_cache_rule'])) {
        check_admin_referer('accr_create_cache_rule');
        
        // Kiểm tra và tắt Page Rule có Cache Level: Cache Everything
        $api_token = get_option('accr_cloudflare_api_token');
        $email = get_option('accr_cloudflare_email');
        $zone_id = get_option('accr_cloudflare_zone_id');

        if (empty($api_token) || empty($email) || empty($zone_id)) {
            echo '<div class="notice notice-error"><p>Thiếu thông tin cấu hình. Vui lòng kiểm tra lại.</p></div>';
            return;
        }

        $page_rule_disabled = accr_disable_cache_everything_page_rule($api_token, $email, $zone_id);
        if ($page_rule_disabled === true) {
            echo '<div class="notice notice-warning"><p>Phát hiện Page Rule có Cache Level: Cache Everything. Đã tắt tính năng này.</p></div>';
        } elseif ($page_rule_disabled === false) {
            echo '<div class="notice notice-error"><p>Lỗi khi tắt Page Rule có Cache Level: Cache Everything.</p></div>';
            return;
        }

        // Tiếp tục tạo Cache Rule mới
        $result = accr_create_cache_rule();
        if ($result === true) {
            echo '<div class="notice notice-success"><p>Cache Rule đã được tạo thành công!</p></div>';
        } elseif ($result === 'exists') {
            echo '<div class="notice notice-warning"><p>Cache Rule đã tồn tại.</p></div>';
        } else {
            $error_log_path = ini_get('error_log');
            $errors = array_filter(
                array_map('trim', file_exists($error_log_path) ? file($error_log_path) : []),
                function($line) {
                    return strpos($line, 'ACCR:') !== false;
                }
            );
            $last_errors = array_slice($errors, -5);
            
            echo '<div class="notice notice-error"><p>Lỗi khi tạo Cache Rule. Chi tiết lỗi:</p>';
            echo '<pre>' . esc_html(implode("\n", $last_errors)) . '</pre></div>';
        }
    }

    // Xử lý xóa Cache Rule
    if (isset($_POST['accr_delete_cache_rule'])) {
        check_admin_referer('accr_delete_cache_rule');
        if (accr_delete_cache_rule()) {
            echo '<div class="notice notice-success"><p>Cache Rule đã được xóa thành công!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Lỗi khi xóa Cache Rule.</p></div>';
        }
    }

    // Xử lý reset settings
    if (isset($_POST['accr_reset_settings'])) {
        check_admin_referer('accr_reset_settings');
        if (accr_reset_settings()) {
            echo '<div class="notice notice-success"><p>Đã khôi phục về cài đặt mặc định thành công!</p></div>';
            // Reload trang để hiển thị form trống
            echo '<script>window.location.reload();</script>';
            return;
        } else {
            echo '<div class="notice notice-error"><p>Lỗi khi khôi phục cài đặt mặc định.</p></div>';
        }
    }

    // Lấy giá trị đã lưu
    $api_token = get_option('accr_cloudflare_api_token', '');
    $email = get_option('accr_cloudflare_email', '');
    $zone_id = get_option('accr_cloudflare_zone_id', '');

    // Hiển thị URL của rule nếu có
    $rule_url = get_option('accr_cloudflare_rule_url', '');
    if ($rule_url) {
        echo '<div class="notice notice-info"><p>URL trực tiếp đến Cache Rule: <a href="' . esc_url($rule_url) . '" target="_blank">' . esc_html($rule_url) . '</a></p></div>';
    }

    // Hiển thị form cấu hình
    ?>
    <div class="wrap">
        <h1>Cloudflare Auto Cache Rules</h1>
        <form method="post" action="">
            <?php wp_nonce_field('accr_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="accr_cloudflare_email">Email Cloudflare</label></th>
                    <td>
                        <input type="email" name="accr_cloudflare_email" value="<?php echo esc_attr($email); ?>" class="regular-text" required>
                        <p class="description">Email đăng nhập Cloudflare của bạn</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="accr_cloudflare_api_token">API Token</label></th>
                    <td>
                        <input type="password" name="accr_cloudflare_api_token" value="<?php echo esc_attr($api_token); ?>" class="regular-text" required>
                        <p class="description">API Token với quyền chỉnh sửa Cache Rules</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="accr_cloudflare_zone_id">Zone ID</label></th>
                    <td>
                        <input type="text" name="accr_cloudflare_zone_id" value="<?php echo esc_attr($zone_id); ?>" class="regular-text" required>
                        <p class="description">ID của zone (domain) trên Cloudflare</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Lưu cấu hình', 'primary', 'accr_save_settings'); ?>
        </form>

        <hr>

        <form method="post" action="">
            <?php wp_nonce_field('accr_create_cache_rule'); ?>
            <?php submit_button('Tạo Cache Rule', 'secondary', 'accr_create_cache_rule'); ?>
        </form>

        <form method="post" action="">
            <?php wp_nonce_field('accr_delete_cache_rule'); ?>
            <?php submit_button('Xóa Cache Rule', 'delete', 'accr_delete_cache_rule'); ?>
        </form>

        <hr>

        <h3>Reset về mặc định</h3>
        <p>Nhấn nút dưới đây để khôi phục về cài đặt mặc định.</p>
        <form method="post" action="">
            <?php wp_nonce_field('accr_reset_settings'); ?>
            <?php submit_button('Reset về mặc định', 'delete', 'accr_reset_settings', false, ['onclick' => 'return confirm("Bạn có chắc chắn muốn khôi phục về cài đặt mặc định? Tất cả cấu hình và Cache Rule sẽ bị xóa.");']); ?>
        </form>
    </div>
    <?php
}

// Kiểm tra thông tin API và Zone ID
function accr_validate_cloudflare_credentials($api_token, $email, $zone_id) {
    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}";

    $response = wp_remote_get($url, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('ACCR: Lỗi xác thực API - ' . $response->get_error_message());
        return 'Lỗi xác thực API: ' . $response->get_error_message();
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!($body['success'] ?? false)) {
        return 'Thông tin cấu hình không hợp lệ. Vui lòng kiểm tra lại.';
    }

    // Lấy domain từ API và so sánh với domain hiện tại
    $api_domain = $body['result']['name'] ?? '';
    $current_domain = parse_url(home_url(), PHP_URL_HOST);

    if ($api_domain !== $current_domain) {
        return 'Zone ID không khớp với domain hiện tại. Vui lòng kiểm tra lại.';
    }

    return true;
}

// Lấy Account ID từ API dựa trên Zone ID
function accr_get_account_id($api_token, $email, $zone_id) {
    // Đầu tiên lấy thông tin của zone để có account_id
    $zone_url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}";
    
    $response = wp_remote_get($zone_url, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('ACCR: Lỗi khi lấy thông tin zone - ' . $response->get_error_message());
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!($body['success'] ?? false)) {
        error_log('ACCR: Không thể lấy thông tin zone - ' . json_encode($body['errors'] ?? []));
        return null;
    }

    // Lấy account_id từ thông tin zone
    $account_id = $body['result']['account']['id'] ?? null;
    
    if (!$account_id) {
        error_log('ACCR: Không tìm thấy Account ID trong thông tin zone');
        return null;
    }

    error_log('ACCR: Đã lấy được Account ID: ' . $account_id);
    return $account_id;
}

// Kiểm tra và tắt Page Rule có Cache Level: Cache Everything
function accr_disable_cache_everything_page_rule($api_token, $email, $zone_id) {
    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/pagerules";

    $response = wp_remote_get($url, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('ACCR: Lỗi khi lấy danh sách Page Rules - ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!($body['success'] ?? false)) {
        error_log('ACCR: Không thể lấy danh sách Page Rules');
        return false;
    }

    // Duyệt qua các Page Rules
    foreach ($body['result'] as $page_rule) {
        $actions = $page_rule['actions'] ?? [];
        foreach ($actions as $action) {
            if ($action['id'] === 'cache_level' && $action['value'] === 'cache_everything') {
                // Tắt Page Rule này bằng cách đặt status thành "disabled"
                $page_rule_id = $page_rule['id'];
                $disable_url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/pagerules/{$page_rule_id}";

                $disable_data = [
                    'status' => 'disabled',
                ];

                $disable_response = wp_remote_request($disable_url, [
                    'method' => 'PATCH',
                    'headers' => [
                        'X-Auth-Email' => $email,
                        'X-Auth-Key' => $api_token,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($disable_data),
                ]);

                if (is_wp_error($disable_response)) {
                    error_log('ACCR: Lỗi khi tắt Page Rule - ' . $disable_response->get_error_message());
                    return false;
                }

                $disable_body = json_decode(wp_remote_retrieve_body($disable_response), true);
                if (!($disable_body['success'] ?? false)) {
                    error_log('ACCR: Không thể tắt Page Rule');
                    return false;
                }

                error_log('ACCR: Đã tắt Page Rule có Cache Level: Cache Everything');
                return true;
            }
        }
    }

    error_log('ACCR: Không tìm thấy Page Rule có Cache Level: Cache Everything');
    return true;
}

// Tạo Cache Rule
function accr_create_cache_rule() {
    $api_token = get_option('accr_cloudflare_api_token');
    $email = get_option('accr_cloudflare_email');
    $zone_id = get_option('accr_cloudflare_zone_id');

    if (empty($api_token) || empty($email) || empty($zone_id)) {
        error_log('ACCR: Thiếu thông tin cấu hình');
        return false;
    }

    // Kiểm tra và tắt Page Rule có Cache Level: Cache Everything
    if (!accr_disable_cache_everything_page_rule($api_token, $email, $zone_id)) {
        error_log('ACCR: Không thể tắt Page Rule có Cache Level: Cache Everything');
        return false;
    }

    // Tiếp tục tạo Cache Rule mới
    error_log('ACCR: Bắt đầu tạo cache rule');
    error_log('ACCR: Zone ID: ' . $zone_id);

    // Lấy danh sách rulesets hiện có
    $list_url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets?phase=http_request_cache_settings";
    error_log('ACCR: URL lấy danh sách: ' . $list_url);

    $list_response = wp_remote_get($list_url, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json'
        ]
    ]);

    if (is_wp_error($list_response)) {
        error_log('ACCR: Lỗi khi lấy danh sách ruleset - ' . $list_response->get_error_message());
        return false;
    }

    $list_body = json_decode(wp_remote_retrieve_body($list_response), true);
    error_log('ACCR: Phản hồi lấy danh sách ruleset: ' . json_encode($list_body));

    if (!($list_body['success'] ?? false)) {
        error_log('ACCR: Không thể lấy danh sách rulesets');
        return false;
    }

    // Tìm ruleset phù hợp
    $ruleset_id = null;
    foreach ($list_body['result'] as $ruleset) {
        if ($ruleset['phase'] === 'http_request_cache_settings') {
            $ruleset_id = $ruleset['id'];
            break;
        }
    }

    // Nếu không tìm thấy ruleset, tạo mới
    if (!$ruleset_id) {
        error_log('ACCR: Không tìm thấy ruleset phù hợp, đang tạo ruleset mới');

        $create_ruleset_url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets";
        $create_ruleset_data = [
            'name' => 'Custom Cache Ruleset',
            'description' => 'Ruleset for managing cache settings',
            'kind' => 'zone',
            'phase' => 'http_request_cache_settings',
        ];

        $create_response = wp_remote_post($create_ruleset_url, [
            'headers' => [
                'X-Auth-Email' => $email,
                'X-Auth-Key' => $api_token,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($create_ruleset_data),
            'timeout' => 30
        ]);

        if (is_wp_error($create_response)) {
            error_log('ACCR: Lỗi khi tạo ruleset - ' . $create_response->get_error_message());
            return false;
        }

        $create_body = json_decode(wp_remote_retrieve_body($create_response), true);
        error_log('ACCR: Phản hồi tạo ruleset: ' . json_encode($create_body));

        if (!($create_body['success'] ?? false)) {
            $errors = json_encode($create_body['errors'] ?? 'Lỗi không xác định');
            error_log('ACCR: Lỗi API khi tạo ruleset: ' . $errors);
            return false;
        }

        $ruleset_id = $create_body['result']['id'] ?? null;
        if (!$ruleset_id) {
            error_log('ACCR: Không thể lấy ID của ruleset mới');
            return false;
        }

        error_log('ACCR: Đã tạo ruleset mới với ID: ' . $ruleset_id);
    } else {
        error_log('ACCR: Đã tìm thấy ruleset ID: ' . $ruleset_id);
    }

    // Lấy danh sách rules hiện có trong ruleset
    $ruleset_url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets/{$ruleset_id}";
    $ruleset_response = wp_remote_get($ruleset_url, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json'
        ]
    ]);

    if (is_wp_error($ruleset_response)) {
        error_log('ACCR: Lỗi khi lấy danh sách rules - ' . $ruleset_response->get_error_message());
        return false;
    }

    $ruleset_body = json_decode(wp_remote_retrieve_body($ruleset_response), true);
    error_log('ACCR: Phản hồi lấy danh sách rules: ' . json_encode($ruleset_body));

    if (!($ruleset_body['success'] ?? false)) {
        error_log('ACCR: Không thể lấy danh sách rules');
        return false;
    }

    // Lấy danh sách rules hiện tại
    $existing_rules = $ruleset_body['result']['rules'] ?? [];

    // Kiểm tra xem rule đã tồn tại chưa
    $rule_description = 'Cache tất cả trừ các trang WordPress động';
    $rule_exists = false;
    $rule_id = null;

    foreach ($existing_rules as $rule) {
        if ($rule['description'] === $rule_description) {
            $rule_exists = true;
            $rule_id = $rule['id'];
            break;
        }
    }

    if ($rule_exists) {
        error_log('ACCR: Rule đã tồn tại');
        $cloudflare_dashboard_url = "https://dash.cloudflare.com/{$zone_id}/" . parse_url(home_url(), PHP_URL_HOST) . "/caching/cache-rules/{$rule_id}";
        update_option('accr_cloudflare_rule_url', $cloudflare_dashboard_url);
        return 'exists';
    }

    // Các pattern loại trừ cho WordPress
$exclude_patterns = [
    'starts_with(http.request.uri.path, "/wp-admin")',
    'starts_with(http.request.uri.path, "/wp-login")',
    'starts_with(http.request.uri.path, "/wp-json/")',
    'starts_with(http.request.uri.path, "/wc-api/")',
    'starts_with(http.request.uri.path, "/edd-api/")',
    'starts_with(http.request.uri.path, "/mepr/")',
    'http.request.uri.path contains "/register/"',
    'http.request.uri.path contains "/dashboard/"',
    'http.request.uri.path contains "/members-area/"',
    'http.request.uri.path contains "/wishlist-member/"',
    'http.request.uri.path contains "phs_downloads-mbr"',
    'http.request.uri.path contains "/checkout/"',
    'http.request.uri.path contains ".xsl"',
    'http.request.uri.path contains ".xml"',
    'http.request.uri.path contains ".php"',
    'starts_with(http.request.uri.query, "s=")',
    'starts_with(http.request.uri.query, "p=")',
    'http.request.uri.query contains "nocache"',
    'http.request.uri.query contains "nowprocket"',
    'http.cookie contains "wordpress_logged_in_"',
    'http.cookie contains "comment_"',
    'http.cookie contains "woocommerce_"',
    'http.cookie contains "wordpressuser_"',
    'http.cookie contains "wordpresspass_"',
    'http.cookie contains "wordpress_sec_"',
    'http.cookie contains "yith_wcwl_products"',
    'http.cookie contains "edd_items_in_cart"',
    'http.cookie contains "it_exchange_session_"',
    'http.cookie contains "comment_author"',
    'http.cookie contains "dshack_level"',
    'http.cookie contains "auth_"',
    'http.cookie contains "noaffiliate_"',
    'http.cookie contains "mp_session"',
    'http.cookie contains "xf_"',
    'http.cookie contains "mp_globalcart_"',
    'http.cookie contains "wp-resetpass-"',
    'http.cookie contains "upsell_customer"',
    'http.cookie contains "wlmapi"',
    'http.cookie contains "wishlist_reg"'
];

    // Kết hợp các điều kiện loại trừ
    $exclusion_expression = implode(' and not ', $exclude_patterns);
    
    // Lấy domain hiện tại
    $current_domain = parse_url(home_url(), PHP_URL_HOST);
    
    // Tạo biểu thức rule
    $rule_expression = "(http.host eq \"{$current_domain}\" and not {$exclusion_expression})";
    
    // Tạo rule mới
    $new_rule = [
        'expression' => $rule_expression,
        'description' => $rule_description,
        'action' => 'set_cache_settings',
        'action_parameters' => [
            'cache' => true,
            'edge_ttl' => [
                'mode' => 'override_origin',
                'default' => 31536000 // 1 năm
            ],
            'cache_key' => [
                'cache_deception_armor' => true
            ]
        ]
    ];

    // Thêm rule mới vào danh sách rules hiện có
    $existing_rules[] = $new_rule;

    // Cấu hình Cache Rule
    $data = [
        'rules' => $existing_rules
    ];

    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets/{$ruleset_id}";
    
    error_log('ACCR: Đang cập nhật ruleset');
    error_log('ACCR: URL cập nhật: ' . $url);
    error_log('ACCR: Data cập nhật: ' . json_encode($data));

    $response = wp_remote_request($url, [
        'method' => 'PUT',
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('ACCR: Lỗi khi cập nhật ruleset - ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    error_log('ACCR: Phản hồi cập nhật ruleset: ' . json_encode($body));

    if (!($body['success'] ?? false)) {
        $errors = json_encode($body['errors'] ?? 'Lỗi không xác định');
        error_log('ACCR: Lỗi API khi cập nhật ruleset: ' . $errors);
        return false;
    }

// Sau khi tạo rule thành công, lưu URL vào database
$rule_id = $body['result']['rules'][count($body['result']['rules']) - 1]['id'] ?? null;
if ($rule_id) {
    // Lấy Account ID
	$account_id = accr_get_account_id($api_token, $email, $zone_id);
    if (!$account_id) {
        error_log('ACCR: Không thể lấy Account ID');
        return false;
    }

    // Tạo URL với Account ID
    $current_domain = parse_url(home_url(), PHP_URL_HOST);
    $cloudflare_dashboard_url = "https://dash.cloudflare.com/{$account_id}/{$current_domain}/caching/cache-rules/{$rule_id}";
    update_option('accr_cloudflare_rule_url', $cloudflare_dashboard_url);
    error_log('ACCR: URL trực tiếp đến rule: ' . $cloudflare_dashboard_url);
} else {
    error_log('ACCR: Không thể lấy rule_id từ phản hồi API');
}
    return true;
}

// Xóa Cache Rule
function accr_delete_cache_rule() {
    $api_token = get_option('accr_cloudflare_api_token');
    $email = get_option('accr_cloudflare_email');
    $zone_id = get_option('accr_cloudflare_zone_id');

    if (empty($api_token) || empty($email) || empty($zone_id)) {
        error_log('ACCR: Thiếu thông tin cấu hình');
        return false;
    }

    error_log('ACCR: Bắt đầu xóa cache rule');
    error_log('ACCR: Zone ID: ' . $zone_id);

    // Lấy danh sách rulesets hiện có
    $list_url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets?phase=http_request_cache_settings";
    error_log('ACCR: URL lấy danh sách: ' . $list_url);

    $list_response = wp_remote_get($list_url, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json'
        ]
    ]);

    if (is_wp_error($list_response)) {
        error_log('ACCR: Lỗi khi lấy danh sách ruleset - ' . $list_response->get_error_message());
        return false;
    }

    $list_body = json_decode(wp_remote_retrieve_body($list_response), true);
    error_log('ACCR: Phản hồi lấy danh sách ruleset: ' . json_encode($list_body));

    if (!($list_body['success'] ?? false)) {
        error_log('ACCR: Không thể lấy danh sách rulesets');
        return false;
    }

    // Tìm ruleset phù hợp
    $ruleset_id = null;
    foreach ($list_body['result'] as $ruleset) {
        if ($ruleset['phase'] === 'http_request_cache_settings') {
            $ruleset_id = $ruleset['id'];
            break;
        }
    }

    if (!$ruleset_id) {
        error_log('ACCR: Không tìm thấy ruleset phù hợp');
        return false;
    }

    error_log('ACCR: Đã tìm thấy ruleset ID: ' . $ruleset_id);

    // Lấy danh sách rules hiện có trong ruleset
    $ruleset_url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets/{$ruleset_id}";
    $ruleset_response = wp_remote_get($ruleset_url, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json'
        ]
    ]);

    if (is_wp_error($ruleset_response)) {
        error_log('ACCR: Lỗi khi lấy danh sách rules - ' . $ruleset_response->get_error_message());
        return false;
    }

    $ruleset_body = json_decode(wp_remote_retrieve_body($ruleset_response), true);
    error_log('ACCR: Phản hồi lấy danh sách rules: ' . json_encode($ruleset_body));

    if (!($ruleset_body['success'] ?? false)) {
        error_log('ACCR: Không thể lấy danh sách rules');
        return false;
    }

    // Lấy danh sách rules hiện tại
    $existing_rules = $ruleset_body['result']['rules'] ?? [];

    // Tìm và xóa rule được tạo bởi plugin
    $rule_description = 'Cache tất cả trừ các trang WordPress động';
    $updated_rules = array_filter($existing_rules, function($rule) use ($rule_description) {
        return $rule['description'] !== $rule_description;
    });

    // Nếu không có rule nào bị xóa, trả về false
    if (count($updated_rules) === count($existing_rules)) {
        error_log('ACCR: Không tìm thấy rule để xóa');
        return false;
    }

    // Cập nhật ruleset với danh sách rules mới
    $data = [
        'rules' => array_values($updated_rules) // Đảm bảo mảng được đánh chỉ mục lại
    ];

    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/rulesets/{$ruleset_id}";
    
    error_log('ACCR: Đang cập nhật ruleset');
    error_log('ACCR: URL cập nhật: ' . $url);
    error_log('ACCR: Data cập nhật: ' . json_encode($data));

    $response = wp_remote_request($url, [
        'method' => 'PUT',
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('ACCR: Lỗi khi cập nhật ruleset - ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    error_log('ACCR: Phản hồi cập nhật ruleset: ' . json_encode($body));

    if (!($body['success'] ?? false)) {
        $errors = json_encode($body['errors'] ?? 'Lỗi không xác định');
        error_log('ACCR: Lỗi API khi cập nhật ruleset: ' . $errors);
        return false;
    }

    // Xóa URL đã lưu
    delete_option('accr_cloudflare_rule_url');

    error_log('ACCR: Xóa cache rule thành công');
    return true;
}

// Thêm xử lý reset settings
function accr_reset_settings() {
    // Xóa cache rule nếu có
    accr_delete_cache_rule();
    
    // Xóa các option đã lưu
    delete_option('accr_cloudflare_api_token');
    delete_option('accr_cloudflare_email');
    delete_option('accr_cloudflare_zone_id');
    delete_option('accr_cloudflare_rule_url');
    
    return true;
}

// Kích hoạt plugin
register_activation_hook(__FILE__, 'accr_activate_plugin');
function accr_activate_plugin() {
    // Không tự động tạo cache rule khi kích hoạt
    // vì cần thông tin API hợp lệ trước
}