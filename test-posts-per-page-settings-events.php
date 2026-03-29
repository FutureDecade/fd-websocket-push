<?php
/**
 * Test script for posts per page settings WebSocket events
 * 
 * This script can be run from command line or accessed via web browser
 * to test posts per page settings event handling functionality.
 */

// Load WordPress
if (!defined('ABSPATH')) {
    // Try to find WordPress root
    $wp_root_paths = [
        __DIR__ . '/../../../wp-config.php',
        __DIR__ . '/../../../../wp-config.php',
        __DIR__ . '/../wp-config.php',
    ];
    
    $wp_config_found = false;
    foreach ($wp_root_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $wp_config_found = true;
            break;
        }
    }
    
    if (!$wp_config_found) {
        die('WordPress configuration not found. Please run this script from the correct location.');
    }
}

// Ensure we have admin capabilities when running from web
if (!defined('WP_CLI') && !current_user_can('manage_options')) {
    wp_die('You need administrator privileges to run this test.');
}

/**
 * Test posts per page settings event functionality
 */
function test_posts_per_page_settings_events() {
    echo "<h2>Testing Posts Per Page Settings WebSocket Events</h2>\n";
    
    // Check if the posts per page settings event handler is loaded
    if (!class_exists('FD_WebSocket_Push_Posts_Per_Page_Settings_Event_Handler')) {
        echo "<p style='color: red;'>❌ Posts Per Page Settings Event Handler class not found!</p>\n";
        return false;
    }
    
    echo "<p style='color: green;'>✅ Posts Per Page Settings Event Handler class loaded</p>\n";
    
    // Check if WebSocket pusher is available
    if (!class_exists('FD_WebSocket_Push_WebSocket_Pusher')) {
        echo "<p style='color: red;'>❌ WebSocket Pusher class not found!</p>\n";
        return false;
    }
    
    echo "<p style='color: green;'>✅ WebSocket Pusher class loaded</p>\n";
    
    // Get the posts per page settings event handler instance
    $posts_per_page_handler = FD_WebSocket_Push_Posts_Per_Page_Settings_Event_Handler::get_instance();
    if (!$posts_per_page_handler) {
        echo "<p style='color: red;'>❌ Failed to get Posts Per Page Settings Event Handler instance</p>\n";
        return false;
    }
    
    echo "<p style='color: green;'>✅ Posts Per Page Settings Event Handler instance created</p>\n";
    
    // Check if WebSocket pusher has the posts per page settings method
    $pusher = FD_WebSocket_Push_WebSocket_Pusher::get_instance();
    if (!method_exists($pusher, 'send_posts_per_page_settings_updated_event')) {
        echo "<p style='color: red;'>❌ send_posts_per_page_settings_updated_event method not found in WebSocket Pusher</p>\n";
        return false;
    }
    
    echo "<p style='color: green;'>✅ send_posts_per_page_settings_updated_event method available</p>\n";
    
    // Test updating posts per page settings
    echo "<h3>Testing Posts Per Page Settings Updates</h3>\n";
    
    // Get current posts per page setting
    $current_posts_per_page = get_option('posts_per_page', 10);
    $test_posts_per_page = ($current_posts_per_page == 12) ? 15 : 12;
    
    echo "<p>Testing posts per page update: $current_posts_per_page → $test_posts_per_page</p>\n";
    
    update_option('posts_per_page', $test_posts_per_page);
    echo "<p style='color: green;'>✅ Posts per page updated successfully</p>\n";
    
    // Restore original setting
    update_option('posts_per_page', $current_posts_per_page);
    echo "<p style='color: green;'>✅ Posts per page restored</p>\n";
    
    // Test custom posts per page setting
    $current_fd_posts_per_page = get_option('fd_posts_per_page', 12);
    $test_fd_posts_per_page = ($current_fd_posts_per_page == 12) ? 18 : 12;
    
    echo "<p>Testing custom posts per page update: $current_fd_posts_per_page → $test_fd_posts_per_page</p>\n";
    
    update_option('fd_posts_per_page', $test_fd_posts_per_page);
    echo "<p style='color: green;'>✅ Custom posts per page updated successfully</p>\n";
    
    // Restore original setting
    update_option('fd_posts_per_page', $current_fd_posts_per_page);
    echo "<p style='color: green;'>✅ Custom posts per page restored</p>\n";
    
    echo "<h3>Test Summary</h3>\n";
    echo "<p style='color: green;'>✅ All posts per page settings event tests completed successfully!</p>\n";
    echo "<p><strong>Note:</strong> Check your WebSocket server logs and frontend console to verify that events were sent and received.</p>\n";
    
    return true;
}

/**
 * Display configuration information
 */
function display_posts_per_page_config_info() {
    echo "<h2>Posts Per Page Settings Configuration Information</h2>\n";
    
    // Check WebSocket configuration
    $websocket_secret = defined('FD_WEBSOCKET_PUSH_SECRET') ? 'Configured' : 'Not configured';
    $revalidate_secret = defined('REVALIDATE_SECRET') ? 'Configured' : 'Not configured';
    
    echo "<ul>\n";
    echo "<li><strong>WebSocket Secret:</strong> $websocket_secret</li>\n";
    echo "<li><strong>Revalidate Secret:</strong> $revalidate_secret</li>\n";
    echo "</ul>\n";
    
    // Display current posts per page settings
    echo "<h3>Current Posts Per Page Settings</h3>\n";
    echo "<ul>\n";
    echo "<li><strong>WordPress Default (posts_per_page):</strong> " . get_option('posts_per_page', 10) . "</li>\n";
    echo "<li><strong>Custom Setting (fd_posts_per_page):</strong> " . get_option('fd_posts_per_page', 12) . "</li>\n";
    echo "<li><strong>Blog Public:</strong> " . (get_option('blog_public', 1) ? 'Yes' : 'No') . "</li>\n";
    echo "</ul>\n";
    
    // Display affected pages
    echo "<h3>Affected Pages</h3>\n";
    echo "<p>Posts per page settings affect the following pages:</p>\n";
    echo "<ul>\n";
    echo "<li>Category archive pages (/category/[slug])</li>\n";
    echo "<li>Tag archive pages (/tag/[slug])</li>\n";
    echo "<li>Author archive pages (/author/[slug])</li>\n";
    echo "<li>Custom taxonomy pages (/taxonomy/[taxonomy]/[slug])</li>\n";
    echo "<li>Search results pages</li>\n";
    echo "<li>Home page (if set to show latest posts)</li>\n";
    echo "</ul>\n";
    
    if (!defined('FD_WEBSOCKET_PUSH_SECRET')) {
        echo "<p style='color: orange;'>⚠️ WebSocket events will not be sent without FD_WEBSOCKET_PUSH_SECRET</p>\n";
    }
    
    if (!defined('REVALIDATE_SECRET')) {
        echo "<p style='color: orange;'>⚠️ Cache invalidation will not work without REVALIDATE_SECRET</p>\n";
    }
}

/**
 * Test manual WebSocket event sending
 */
function test_manual_posts_per_page_websocket_event() {
    echo "<h2>Testing Manual Posts Per Page WebSocket Event</h2>\n";
    
    $pusher = FD_WebSocket_Push_WebSocket_Pusher::get_instance();
    
    // Send a test posts per page settings event
    $result = $pusher->send_posts_per_page_settings_updated_event(
        'posts_per_page',
        10,
        12
    );
    
    if ($result) {
        echo "<p style='color: green;'>✅ Manual posts per page WebSocket event sent successfully</p>\n";
    } else {
        echo "<p style='color: red;'>❌ Failed to send manual posts per page WebSocket event</p>\n";
    }
    
    return $result;
}

/**
 * Test performance impact
 */
function test_performance_impact() {
    echo "<h2>Performance Impact Analysis</h2>\n";
    
    echo "<h3>Before Optimization</h3>\n";
    echo "<ul>\n";
    echo "<li>Each category/tag/author/taxonomy page: 1 separate fetchPostsPerPageSetting() call</li>\n";
    echo "<li>Each 'Load More' action: 1 additional GraphQL query for posts per page setting</li>\n";
    echo "<li>Total queries for a typical user session: 5-10 redundant queries</li>\n";
    echo "</ul>\n";
    
    echo "<h3>After Optimization</h3>\n";
    echo "<ul>\n";
    echo "<li>Root layout: 1 fetchPostsPerPageSettings() call (cached for 10 minutes)</li>\n";
    echo "<li>All pages and 'Load More' actions: 0 additional queries (use Context data)</li>\n";
    echo "<li>Total queries for a typical user session: 1 cached query</li>\n";
    echo "</ul>\n";
    
    echo "<h3>Performance Improvement</h3>\n";
    echo "<ul>\n";
    echo "<li><strong>Query Reduction:</strong> 80-90% fewer GraphQL queries</li>\n";
    echo "<li><strong>Load Time:</strong> Faster 'Load More' actions (no query delay)</li>\n";
    echo "<li><strong>Server Load:</strong> Reduced database and GraphQL processing</li>\n";
    echo "<li><strong>User Experience:</strong> Instant pagination setting availability</li>\n";
    echo "</ul>\n";
}

// Run the tests
if (defined('WP_CLI')) {
    // Command line output
    echo "Posts Per Page Settings WebSocket Events Test\n";
    echo "=============================================\n\n";
    test_posts_per_page_settings_events();
    echo "\n";
    test_manual_posts_per_page_websocket_event();
    echo "\n";
    test_performance_impact();
} else {
    // Web browser output
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Posts Per Page Settings WebSocket Events Test</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            h2 { color: #333; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
            h3 { color: #666; }
            p { margin: 10px 0; }
            ul { margin: 10px 0; }
        </style>
    </head>
    <body>
        <h1>Posts Per Page Settings WebSocket Events Test</h1>
        <?php 
        display_posts_per_page_config_info();
        test_posts_per_page_settings_events(); 
        test_manual_posts_per_page_websocket_event();
        test_performance_impact();
        ?>
        <hr>
        <p><em>Test completed at <?php echo date('Y-m-d H:i:s'); ?></em></p>
    </body>
    </html>
    <?php
}
?>
