window.$ = jQuery;
$(document).ready(function() {
    const likesRegex = /(\d+) users* liked this post/;
    window.likePost = function(event, postId) {
        event.preventDefault();
        $.post(`/wp-json/likes/v1/posts/${postId}`).then(
            function(data) {
                const selector = `#post-${postId}-likes`;
                const numLikes = data.num_likes;
                $(selector).text(`${numLikes} ${pluralize('user', numLikes)} liked this post`);
            },
            function() {
                console.log('failed to like!');
            },
        );
    }

    window.pluralize = function(string, number) {
        if (number === 1) {
            return string;
        }
        return `${string}s`;
    }
});
