<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists('WP_Post')) {
    #[\AllowDynamicProperties]
    class WP_Post {
        public function __construct(array $properties = []) {
            foreach ($properties as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false) {
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string);
        $string = strip_tags((string) $string);
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }
        return trim($string);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return strip_tags((string) $str);
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($str) {
        return strtolower(str_replace(' ', '-', strip_tags((string) $str)));
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words($text, $num_words = 55, $more = null) {
        $words = preg_split('/\s+/', trim((string) $text));
        $words = array_filter($words, static fn($word) => $word !== '');
        $more = $more ?? '...';

        if (count($words) <= $num_words) {
            return implode(' ', $words);
        }

        return implode(' ', array_slice($words, 0, $num_words)) . $more;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args) {
        if (isset($GLOBALS['mock_wp_remote_post_callback'])) {
            return $GLOBALS['mock_wp_remote_post_callback']($url, $args);
        }
        return ['response' => ['code' => 200], 'body' => '{}'];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return $response['response']['code'] ?? 200;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return $response['body'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response) {
        return $response['headers'] ?? [];
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '') {
        return 'http://localhost' . (string) $path;
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'http://localhost';
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '') {
        $values = $GLOBALS['mock_bloginfo'] ?? [
            'name' => 'Test Site',
            'description' => 'Test site description',
        ];

        return $values[$show] ?? '';
    }
}

if (!function_exists('get_post')) {
    function get_post($post) {
        $id = is_object($post) ? ($post->ID ?? null) : $post;
        return $GLOBALS['mock_posts'][$id] ?? null;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post) {
        $id = is_object($post) ? ($post->ID ?? 0) : (int) $post;
        return 'http://localhost/post/' . $id;
    }
}

if (!function_exists('get_the_date')) {
    function get_the_date($format = '', $post = null) {
        return $post->post_date ?? '2026-01-01T00:00:00+00:00';
    }
}

if (!function_exists('get_the_modified_date')) {
    function get_the_modified_date($format = '', $post = null) {
        return $post->post_modified ?? '2026-01-02T00:00:00+00:00';
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata($user_id) {
        return $GLOBALS['mock_users'][$user_id] ?? null;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        $value = $GLOBALS['mock_post_meta'][$post_id][$key] ?? ($single ? '' : []);
        if ($single) {
            return $value;
        }
        return is_array($value) ? $value : [$value];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $GLOBALS['mock_post_meta'][$post_id][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key) {
        unset($GLOBALS['mock_post_meta'][$post_id][$key]);
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return $gmt ? '2026-01-03 00:00:00' : '2026-01-03 03:00:00';
    }
}

if (!function_exists('has_post_thumbnail')) {
    function has_post_thumbnail($post) {
        $id = is_object($post) ? ($post->ID ?? 0) : (int) $post;
        return !empty($GLOBALS['mock_thumbnails'][$id]);
    }
}

if (!function_exists('get_the_post_thumbnail_url')) {
    function get_the_post_thumbnail_url($post, $size = 'full') {
        $id = is_object($post) ? ($post->ID ?? 0) : (int) $post;
        return $GLOBALS['mock_thumbnails'][$id] ?? '';
    }
}

if (!function_exists('get_site_icon_url')) {
    function get_site_icon_url() {
        return $GLOBALS['mock_site_icon_url'] ?? '';
    }
}

$GLOBALS['mock_options'] = [];
$GLOBALS['mock_posts'] = [];
$GLOBALS['mock_post_meta'] = [];
$GLOBALS['mock_users'] = [];
$GLOBALS['mock_bloginfo'] = [
    'name' => 'Test Site',
    'description' => 'Test site description',
];
$GLOBALS['mock_thumbnails'] = [];

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['mock_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        $GLOBALS['mock_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        unset($GLOBALS['mock_options'][$option]);
        return true;
    }
}

$GLOBALS['mock_schedules'] = [];

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = [], $group = '') {
        $GLOBALS['mock_schedules'][] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
        ];
        return rand(1, 99999);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        if (isset($GLOBALS['mock_actions'][$hook])) {
            $GLOBALS['mock_actions'][$hook][] = $args;
        }
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (is_object($args)) {
            $parsed_args = get_object_vars($args);
        } elseif (is_array($args)) {
            $parsed_args = $args;
        } else {
            parse_str($args, $parsed_args);
        }
        return array_merge($defaults, $parsed_args);
    }
}

if (!function_exists('vibe_ai_log')) {
    function vibe_ai_log($level, $message, $context = []) {
    }
}

$GLOBALS['wpdb'] = null;
