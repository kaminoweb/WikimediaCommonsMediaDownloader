<?php
/*
Plugin Name: Wikimedia Commons Media Downloader
Description: Search and download Wikimedia Commons images directly to your WordPress media library.
Version: 1.0
Author: Your Name
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WikimediaCommonsMediaDownloader {
    private $api_url;

    public function __construct() {
        // Define constants
        define('WCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('WCM_PLUGIN_URL', plugin_dir_url(__FILE__));

        // Initialize plugin
        $this->api_url = 'https://commons.wikimedia.org/w/api.php';

        // Hook into WordPress
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_wcm_search_commons', array($this, 'search_commons'));
        add_action('wp_ajax_wcm_download_images', array($this, 'download_images'));
    }

    // Add menu and settings page
    public function add_menu_page() {
        // Add main plugin page under Media
        add_media_page(
            'Wikimedia Commons Downloader',
            'Wikimedia Downloader',
            'manage_options',
            'wikimedia-downloader',
            array($this, 'render_plugin_page')
        );
    }

    // Enqueue scripts and styles
    public function enqueue_scripts($hook) {
        // Load scripts only on our plugin page
        if ($hook !== 'media_page_wikimedia-downloader') {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style('wcm-styles', WCM_PLUGIN_URL . 'css/styles.css', array(), '1.0');

        // Enqueue Dashicons
        wp_enqueue_style('dashicons');

        // Enqueue JavaScript
        wp_enqueue_script('wcm-scripts', WCM_PLUGIN_URL . 'js/scripts.js', array('jquery'), '1.0', true);

        // Localize script with AJAX URL and nonce
        wp_localize_script('wcm-scripts', 'wcm_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wcm_nonce'),
        ));
    }

    // Render the main plugin page
    public function render_plugin_page() {
        ?>
        <div class="wrap">
            <h1>Wikimedia Commons Media Downloader</h1>
            <form id="wcm-search-form" class="wcm-search-form">
                <input type="text" id="wcm-search-query" placeholder="Search for images..." required />
                
                <!-- Orientation Selection -->
                <select id="wcm-orientation" name="orientation">
                    <option value="">Any Orientation</option>
                    <option value="horizontal">Horizontal</option>
                    <option value="vertical">Vertical</option>
                </select>
                
                <!-- Picture Size Inputs -->
                <input type="number" id="wcm-min-width" name="min_width" placeholder="Min Width (px)" min="0" />
                <input type="number" id="wcm-min-height" name="min_height" placeholder="Min Height (px)" min="0" />
                
                <button type="submit" class="button button-primary"><span class="dashicons dashicons-search"></span> Search</button>
            </form>
            <div id="wcm-results"></div>
            <button id="wcm-download-selected" class="button button-success"><span class="dashicons dashicons-download"></span> Download Selected</button>
        </div>
        <?php
    }

    // Handle Wikimedia Commons API search
    public function search_commons() {
        check_ajax_referer('wcm_nonce', 'nonce');

        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = 20;
        $orientation = isset($_POST['orientation']) ? sanitize_text_field($_POST['orientation']) : '';
        $min_width = isset($_POST['min_width']) ? intval($_POST['min_width']) : '';
        $min_height = isset($_POST['min_height']) ? intval($_POST['min_height']) : '';

        if (empty($query)) {
            wp_send_json_error('Empty search query.');
        }

        // Build the API URL with parameters
        $params = array(
            'action'    => 'query',
            'format'    => 'json',
            'prop'      => 'imageinfo',
            'iiprop'    => 'url|size',
            'generator' => 'search',
            'gsrsearch' => $query,
            'gsrlimit'  => $per_page,
            'gsroffset' => ($page - 1) * $per_page,
            'gsrnamespace' => 6, // Namespace 6 is for files
        );

        // Orientation and size filtering will be handled client-side after fetching
        $url = add_query_arg($params, $this->api_url);

        // Make the API request
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            wp_send_json_error($data['error']['info']);
        }

        // Extract images
        $images = array();
        if (isset($data['query']['pages'])) {
            foreach ($data['query']['pages'] as $page) {
                if (isset($page['imageinfo'][0])) {
                    $image_info = $page['imageinfo'][0];
                    $width = isset($image_info['width']) ? intval($image_info['width']) : 0;
                    $height = isset($image_info['height']) ? intval($image_info['height']) : 0;
                    $url = isset($image_info['url']) ? esc_url_raw($image_info['url']) : '';
                    $title = isset($page['title']) ? sanitize_text_field($page['title']) : '';

                    // Determine orientation
                    $image_orientation = '';
                    if ($width > $height) {
                        $image_orientation = 'horizontal';
                    } elseif ($height > $width) {
                        $image_orientation = 'vertical';
                    } else {
                        $image_orientation = 'square';
                    }

                    $images[] = array(
                        'id'        => $page['pageid'],
                        'title'     => $title,
                        'url'       => $url,
                        'width'     => $width,
                        'height'    => $height,
                        'orientation' => $image_orientation,
                    );
                }
            }
        }

        // Apply client-side filters for orientation and size
        if (!empty($orientation)) {
            $images = array_filter($images, function($img) use ($orientation) {
                return $img['orientation'] === $orientation;
            });
        }

        if (!empty($min_width)) {
            $images = array_filter($images, function($img) use ($min_width) {
                return $img['width'] >= $min_width;
            });
        }

        if (!empty($min_height)) {
            $images = array_filter($images, function($img) use ($min_height) {
                return $img['height'] >= $min_height;
            });
        }

        // Re-index the array
        $images = array_values($images);

        // Calculate total hits if possible (Wikimedia Commons API may not provide total hits)
        $totalHits = count($images); // This is not accurate; Wikimedia API doesn't provide total hits

        wp_send_json_success(array(
            'hits'      => $images,
            'totalHits' => $totalHits,
        ));
    }

    // Handle image downloads
    public function download_images() {
        check_ajax_referer('wcm_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized user.');
        }

        $images = isset($_POST['images']) ? $_POST['images'] : array();
        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';

        if (empty($images) || !is_array($images)) {
            wp_send_json_error('No images selected.');
        }

        if (empty($query)) {
            wp_send_json_error('Search query not provided.');
        }

        // Sanitize the search query to use in filenames
        $sanitized_query = sanitize_title($query); // Converts to lowercase, removes special chars, replaces spaces with hyphens

        $downloaded = 0;
        $failed = 0;

        foreach ($images as $image) {
            $url = esc_url_raw($image['url']);
            $id = isset($image['id']) ? sanitize_text_field($image['id']) : uniqid();
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            $extension = strtolower($extension) ? strtolower($extension) : 'jpg'; // Default to jpg if extension is missing

            // Generate a shorter, meaningful filename
            $filename = "{$sanitized_query}_{$id}.{$extension}";

            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['path'] . '/' . $filename;

            // Avoid filename collisions
            if (file_exists($file_path)) {
                $filename = "{$sanitized_query}_{$id}_" . uniqid() . ".{$extension}";
                $file_path = $upload_dir['path'] . '/' . $filename;
            }

            // Download image
            $image_response = wp_remote_get($url);

            if (is_wp_error($image_response)) {
                $failed++;
                continue;
            }

            $image_data = wp_remote_retrieve_body($image_response);

            if ($image_data) {
                // Save the image to the uploads directory
                $saved = file_put_contents($file_path, $image_data);

                if ($saved === false) {
                    $failed++;
                    continue;
                }

                // Check the file type
                $wp_filetype = wp_check_filetype($filename, null);

                // Prepare attachment data
                $attachment = array(
                    'post_mime_type' => $wp_filetype['type'],
                    'post_title'     => sanitize_file_name($filename),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                );

                // Insert the attachment
                $attach_id = wp_insert_attachment($attachment, $file_path);

                if (is_wp_error($attach_id)) {
                    $failed++;
                    continue;
                }

                // Generate metadata and update attachment
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
                wp_update_attachment_metadata($attach_id, $attach_data);

                $downloaded++;
            } else {
                $failed++;
            }
        }

        if ($downloaded > 0 && $failed === 0) {
            wp_send_json_success("Successfully downloaded {$downloaded} image(s).");
        } elseif ($downloaded > 0 && $failed > 0) {
            wp_send_json_success("Successfully downloaded {$downloaded} image(s). {$failed} image(s) failed to download.");
        } else {
            wp_send_json_error('Failed to download images.');
        }
    }
}

// Initialize the plugin
new WikimediaCommonsMediaDownloader();

