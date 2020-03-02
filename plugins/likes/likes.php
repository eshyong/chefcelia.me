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

register_activation_hook(__FILE__, "activate_likes_plugin");
register_uninstall_hook(__FILE__, "uninstall_likes_plugin");

add_filter("the_content", "add_like_button");
add_action("rest_api_init", function() {
    register_rest_route("likes/v1/", "/posts/(?P<post_id>\d+)", array(
        "methods" => "POST",
        "callback" => "handle_like_for_post",
    ));
});

add_action("wp_enqueue_scripts", function() {
    wp_enqueue_script("likes_js", plugins_url("js/likes.js", __FILE__), array("jquery"));
    wp_enqueue_style("likes_css", plugins_url("css/likes.css", __FILE__), array());
});

function activate_likes_plugin() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . "likes";

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id SERIAL PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL,
        INDEX post_id_user_id_idx (post_id, user_id),
        CONSTRAINT post_id_fk
            FOREIGN KEY (post_id)
            REFERENCES wp_posts (id)
    ) $charset_collate";

    require_once(ABSPATH . "wp-admin/includes/upgrade.php");
    dbDelta($sql);
}

function uninstall_likes_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . "likes";
    $sql = "DROP TABLE IF EXISTS $table_name";
    $wpdb->query($sql);
}

function add_like_button($content) {
    if (is_admin()) {
        return $content;
    }
    $post_id = get_post()->ID;
    $like_button = "<form>" .
                   "<input type='submit' value='LIKE' class='like-button' onclick='likePost(event, $post_id);'></input>" .
                   "</form><br>";
    $num_likes = get_likes_for_post($post_id);

    $like_count = "<p id='post-$post_id-likes' class='like-count'>";
    if ($num_likes === 1) {
        $like_count .= "1 user liked this post";
    } else if ($num_likes > 1  || $num_likes === 0) {
        $like_count .= "$num_likes users liked this post";
    }
    $like_count .= "</p>";

    $like_element = "<div>" . $like_button . $like_count . "</div>";
    return $content . $like_element;
}

function get_likes_for_post($post_id, $user_id = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . "likes";
    $statement = "SELECT COUNT(*) from $table_name WHERE post_id = %d";
    $args = array($post_id);
    if ($user_id !== null) {
        $statement .= " AND user_id = %s";
        $args[] = $user_id;
    }

    $query = $wpdb->prepare($statement, $args);
    return (int)$wpdb->get_var($query);
}

function try_like_post($post_id, $user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . "likes";
    $num_rows = $wpdb->insert(
        $table_name,
        array("user_id" => $user_id, "post_id" => $post_id),
        array("%s", "%d"),
    );
    if ($num_rows === 0) {
        error_log("Unable to like post: $wpdb->last_error");
    }
    return $num_rows;
}

function handle_like_for_post($request) {
    $post_id = $request->get_url_params()["post_id"];
    $user_id = $_COOKIE["user_id"];

    if (!isset($user_id)) {
        $user_id = set_user_id_cookie();
        if ($user_id === null) {
            return new WP_Error(
                "server_error",
                "Unable to set cookie on user",
                array("status" => 500),
            );
        }
    }

    $liked = get_likes_for_post($post_id, $user_id) === 1;
    if (!$liked) {
        if (!try_like_post($post_id, $user_id)) {
            return wp_send_json(array("error" => "Server encountered an error"), 500);
        }
    }
    $num_likes = get_likes_for_post($post_id);
    wp_send_json(array("num_likes" => $num_likes), 200);
}

function set_user_id_cookie() {
    $uuid = wp_generate_uuid4();
    $ten_years_in_seconds = 60 * 60 * 24 * 365 * 10;
    if (ENVIRONMENT === "production") {
        $secure = true;
    } else {
        $secure = false;
    }

    $cookie_set = setcookie(
        "user_id",
        $uuid,
        array(
            "expires" => time() + $ten_years_in_seconds,
            "path" => "/",
            "domain" => DOMAIN,
            "secure" => $secure,
            "httponly" => true,
        )
    );

    if (!$cookie_set) {
        error_log("Unable to set cookie: " . $result);
        return null;
    }

    return $uuid;
}
