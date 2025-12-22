<?php
/**
 * Plugin Name: Article Image Generator
 * Description: Generate article images from any title/keyword using your own backend. Includes download + save to Media Library + editor featured image generator.
 * Version: 1.5.0
 * Author: Article Image Generator
 */

if (!defined('ABSPATH')) exit;

class Article_Image_Generator {

  /**
   * ✅ FIXED BACKEND (NOT EDITABLE IN SETTINGS)
   */
  const FIXED_BACKEND_BASE  = 'https://graphicfolks.com/wsconfigarun';
  const FIXED_ENDPOINT_PATH = '/generate-flux-image';

  public function __construct() {
    // ✅ Settings page (info only)
    add_action('admin_menu', [$this, 'add_info_page']);

    // ✅ Shortcode
    add_shortcode('article_image_generator', [$this, 'shortcode']);

    // ✅ Assets
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets_admin']);

    // ✅ Ajax
    add_action('wp_ajax_aig_generate', [$this, 'ajax_generate']);
    add_action('wp_ajax_nopriv_aig_generate', [$this, 'ajax_generate']);

    add_action('wp_ajax_aig_save_media', [$this, 'ajax_save_media']);
    add_action('wp_ajax_aig_generate_set_featured', [$this, 'ajax_generate_set_featured']);
  }

  // ----------------- SETTINGS PAGE (INFO ONLY) -----------------
  public function add_info_page() {
    add_options_page(
      'Article Image Generator',
      'Article Image Generator',
      'manage_options',
      'aig-settings',
      [$this, 'render_info_page']
    );
  }

public function render_info_page() {
  if (!current_user_can('manage_options')) return;
  ?>
    <div class="wrap">
      <h1>Article Image Generator</h1>

      <p>This plugin helps you generate AI-powered images directly from your post titles.</p>

      <ul style="list-style:disc;padding-left:20px;">
        <li>Generate images from title or keyword</li>
        <li>Save images to Media Library</li>
        <li>Automatically set Featured Image in Post Editor</li>
      </ul>

      <hr />

      <h2>✅ Shortcode</h2>
      <p>Use this shortcode on any page or post:</p>
      <p>
        <code style="font-size:14px;">[article_image_generator]</code>
      </p>

      <h2>✅ How to use (Frontend)</h2>
      <ol style="padding-left:20px;">
        <li>Create or edit a page</li>
        <li>Paste the shortcode <code>[article_image_generator]</code></li>
        <li>Publish the page</li>
        <li>Enter a title or keyword and click <b>Generate Image</b></li>
      </ol>

      <h2>✅ How to use (Post Editor)</h2>
      <ol style="padding-left:20px;">
        <li>Edit any post or page</li>
        <li>In the right sidebar, find <b>Image Generate</b></li>
        <li>Click <b>Generate Featured Image</b></li>
        <li>The image will be generated and set automatically</li>
      </ol>

      <p style="margin-top:20px;color:#666;">
        No configuration is required. Everything works automatically.
      </p>

      <hr />

      <h2>🔗 Built with resources from</h2>
      <ul style="list-style:disc;padding-left:20px;">
        <li>
          <a href="https://www.freebiesmockup.com" target="_blank" rel="noopener noreferrer">
            https://www.freebiesmockup.com
          </a>
        </li>
        <li>
          <a href="https://graphicfolks.com" target="_blank" rel="noopener noreferrer">
            https://graphicfolks.com
          </a>
        </li>
        <li>
          <a href="https://webfeenix.com" target="_blank" rel="noopener noreferrer">
            https://webfeenix.com
          </a>
        </li>
      </ul>
    </div>
  <?php
}


  private function endpoint_url() {
    $base = rtrim(self::FIXED_BACKEND_BASE, '/');
    $path = self::FIXED_ENDPOINT_PATH;
    if ($path === '') $path = '/generate-flux-image';
    if ($path[0] !== '/') $path = '/' . $path;
    return $base . $path;
  }

  // ----------------- ASSETS -----------------
  public function enqueue_assets() {
    wp_register_style('aig-css', plugins_url('assets/style.css', __FILE__), [], '1.5.0');
    wp_register_script('aig-js', plugins_url('assets/script.js', __FILE__), [], '1.5.0', true);

    wp_enqueue_style('aig-css');
    wp_enqueue_script('aig-js');

    wp_localize_script('aig-js', 'AIG', [
      'ajax'  => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('aig_nonce'),
      'canSave' => is_user_logged_in() && current_user_can('upload_files'),
    ]);
  }

  public function enqueue_assets_admin($hook) {
    // Gutenberg editor screens only
    if ($hook === 'post.php' || $hook === 'post-new.php') {
      wp_enqueue_style('aig-css', plugins_url('assets/style.css', __FILE__), [], '1.5.0');

      wp_enqueue_script(
        'aig-editor',
        plugins_url('assets/editor.js', __FILE__),
        ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data'],
        '1.5.0',
        true
      );

      wp_localize_script('aig-editor', 'AIG_EDITOR', [
        'ajax'  => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aig_nonce'),
      ]);
    }
  }

  // ---------------- SHORTCODE UI ----------------
  public function shortcode($atts) {
    ob_start(); ?>
      <div class="aig-box" data-endpoint-ok="1">
        <input type="text" class="aig-input" placeholder="Enter article title or keyword" />
        <button class="aig-btn" type="button">Generate Image</button>

        <div class="aig-alert aig-alert-error" style="display:none;"></div>

        <div class="aig-result" style="display:none;">
          <img class="aig-preview" alt="Generated image preview" />

          <div class="aig-actions">
            <a class="aig-download" href="#" target="_blank" rel="noreferrer">Download</a>
            <button class="aig-save" type="button" style="display:none;">Save to Media Library</button>
          </div>

          <div class="aig-saved" style="display:none;"></div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  // ---------------- AJAX: GENERATE ONLY ----------------
  public function ajax_generate() {
    check_ajax_referer('aig_nonce', 'nonce');

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    if (!$title) wp_send_json_error('Missing title');

    $endpoint = $this->endpoint_url();

    $res = wp_remote_post($endpoint, [
      'headers' => ['Content-Type' => 'application/json'],
      'body' => wp_json_encode(['title' => $title]),
      'timeout' => 180
    ]);

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message());

    $code = wp_remote_retrieve_response_code($res);
    $bodyRaw = wp_remote_retrieve_body($res);
    $body = json_decode($bodyRaw, true);

    if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['url'])) {
      $msg = is_array($body) && !empty($body['error']) ? $body['error'] : 'Invalid backend response';
      wp_send_json_error($msg);
    }

    wp_send_json_success([
      'url' => esc_url_raw($body['url']),
      'prompt' => isset($body['prompt']) ? (string)$body['prompt'] : '',
    ]);
  }

  // ---------------- AJAX: SAVE TO MEDIA (NO FEATURED) ----------------
  public function ajax_save_media() {
    check_ajax_referer('aig_nonce', 'nonce');

    if (!is_user_logged_in() || !current_user_can('upload_files')) {
      wp_send_json_error('Not allowed');
    }

    $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

    if (!$image_url) wp_send_json_error('Missing image_url');

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($image_url, 180);
    if (is_wp_error($tmp)) wp_send_json_error($tmp->get_error_message());

    $safeTitle = $title ? sanitize_title($title) : 'article-image';
    $filename = $safeTitle . '-' . time() . '.jpg';

    $file_array = [
      'name' => $filename,
      'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, 0);

    if (is_wp_error($attachment_id)) {
      @unlink($tmp);
      wp_send_json_error($attachment_id->get_error_message());
    }

    $url = wp_get_attachment_url($attachment_id);

    wp_send_json_success([
      'attachment_id' => $attachment_id,
      'url' => $url,
    ]);
  }

  // ---------------- AJAX: GENERATE + SAVE + SET FEATURED ----------------
  public function ajax_generate_set_featured() {
    check_ajax_referer('aig_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $title   = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

    if (!$post_id || !get_post($post_id)) wp_send_json_error('Invalid post_id');

    if (!current_user_can('edit_post', $post_id) || !current_user_can('upload_files')) {
      wp_send_json_error('Not allowed');
    }

    if (!$title) $title = (string) get_the_title($post_id);
    if (!$title) wp_send_json_error('Post title is empty');

    $endpoint = $this->endpoint_url();

    $res = wp_remote_post($endpoint, [
      'headers' => ['Content-Type' => 'application/json'],
      'body' => wp_json_encode(['title' => $title]),
      'timeout' => 180
    ]);

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message());

    $code = wp_remote_retrieve_response_code($res);
    $bodyRaw = wp_remote_retrieve_body($res);
    $body = json_decode($bodyRaw, true);

    if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['url'])) {
      $msg = is_array($body) && !empty($body['error']) ? $body['error'] : 'Invalid backend response';
      wp_send_json_error($msg);
    }

    $image_url = esc_url_raw($body['url']);

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($image_url, 180);
    if (is_wp_error($tmp)) wp_send_json_error($tmp->get_error_message());

    $safeTitle = sanitize_title($title ?: 'featured-image');
    $filename = $safeTitle . '-' . time() . '.jpg';

    $file_array = [
      'name' => $filename,
      'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id);

    if (is_wp_error($attachment_id)) {
      @unlink($tmp);
      wp_send_json_error($attachment_id->get_error_message());
    }

    set_post_thumbnail($post_id, $attachment_id);
    $attachment_url = wp_get_attachment_url($attachment_id);

    wp_send_json_success([
      'post_id' => $post_id,
      'attachment_id' => $attachment_id,
      'url' => $attachment_url,
      'generated_url' => $image_url,
    ]);
  }
}

new Article_Image_Generator();
