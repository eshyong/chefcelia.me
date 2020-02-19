<?php
/**
 * Plugin Name: Likes
 */

define("ENVIRONMENT", getenv("ENVIRONMENT"));

if (ENVIRONMENT === "production") {
    define("DOMAIN", "www.chefcelia.me");
} else {
    define("DOMAIN", "localhost");
}

register_activation_hook(__FILE__, 'activate_likes_plugin');
register_deactivation_hook(__FILE__, 'deactivate_likes_plugin');

add_action("the_content", "add_like_button");
add_action('rest_api_init', function() {
    register_rest_route('likes/v1/', '/posts/(?P<post_id>\d+)', array(
        'methods' => 'POST',
        'callback' => 'handle_like_for_post',
    ));
});

function activate_likes_plugin() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'likes';

    $sql = "CREATE TABLE $table_name (
        id SERIAL PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL,
        INDEX post_id_user_id_idx (post_id, user_id),
        CONSTRAINT post_id_fk
            FOREIGN KEY (post_id)
            REFERENCES wp_posts (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function deactivate_likes_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'likes';
    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);
}

function add_like_button($content) {
    global $wpdb;
    $post_id = get_post()->ID;
    $like_button = "<form action='/wp-json/likes/v1/posts/$post_id' method='POST'>" .
                   "<input type='submit' value='LIKE' class='like-button'></input>" .
                   "</form>";
    $table_name = $wpdb->prefix . "likes";
    $num_likes = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) from $table_name WHERE post_id = %d",
            $post_id,
        )
    );

    $like_count = "<p class='like-count'>";
    if ($num_likes == 1) {
        $like_count .= "1 user liked this post";
    } else if ($num_likes > 1  || $num_likes == 0) {
        $like_count .= "$num_likes users liked this post";
    }
    $like_count .= "</p>";

    echo $content . $like_button . "<br>" . $like_count;
}

function handle_like_for_post($request) {
    global $wpdb;
    $post_id = $request->get_url_params()['post_id'];
    $user_id = $_COOKIE['user_id'];
    if (!isset($user_id)) {
        $user_id = set_user_id_cookie();
    }

    $table_name = $wpdb->prefix . 'likes';
    $like = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE post_id = %d AND user_id = %s",
            $post_id,
            $user_id,
        )
    );

    if (!$like) {
        $result = $wpdb->insert(
            $table_name,
            array('user_id' => $user_id, 'post_id' => $post_id),
            array('%s', '%d'),
        );

        if (!$result) {
            return new WP_Error(
                "server_error",
                "Unable to like post: " . $wpdb->last_error,
                array("status" => 500),
            );
        }
    }
}

function set_user_id_cookie() {
    $uuid = wp_generate_uuid4();
    $ten_years_in_seconds = 60 * 60 * 24 * 365 * 10;
    if (ENVIRONMENT === 'production') {
        $secure = true;
    } else {
        $secure = false;
    }

    setcookie(
        'user_id',
        $uuid,
        array(
            'expires' => time() + $ten_years_in_seconds,
            'path' => '/',
            'domain' => DOMAIN,
            'secure' => $secure,
            'httponly' => true,
        )
    );

    return $uuid;
}
