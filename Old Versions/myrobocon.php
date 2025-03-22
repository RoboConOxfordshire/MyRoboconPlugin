<?php
/*
Plugin Name: MyRobocon
Plugin URI: https://example.com/
Description: Teams Portal Plugin for Robocon Oxfordshire
Version: v3.3.2
Author: Don Wong
Text Domain: myrobocon
*/

if (!defined('ABSPATH')) exit;

class MyRobocon {
    public function __construct() {
        add_action('init', [$this, 'register_custom_post_types']);
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_data']);
        add_action('template_redirect', [$this, 'redirect_non_logged_users']);
        add_shortcode('myrobocon_portal', [$this, 'portal_shortcode']);
        add_filter('the_content', [$this, 'modify_resource_content']);
        add_action('admin_init', [$this, 'handle_team_import']);
        add_filter('comments_open', [$this, 'disable_comments'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    public function enqueue_styles() {
        wp_enqueue_style('myrobocon-style', plugins_url('style.css', __FILE__));
    }

    public function register_custom_post_types() {
        register_post_type('team', [
            'labels' => ['name' => 'Teams', 'singular_name' => 'Team'],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
            'menu_icon' => 'dashicons-groups'
        ]);

        register_post_type('team_resource', [
            'labels' => ['name' => 'Team Resources', 'singular_name' => 'Resource'],
            'public' => true,
            'supports' => ['title', 'editor'],
            'has_archive' => false,
            'rewrite' => ['slug' => 'team-resources'],
            'show_in_menu' => false
        ]);
    }

    public function add_admin_menus() {
        add_menu_page('Teams', 'Teams', 'manage_options', 'teams', [$this, 'teams_admin_page'], 'dashicons-groups');
        add_submenu_page('teams', 'Import Teams', 'Import Teams', 'manage_options', 'team-import', [$this, 'import_teams_page']);
        add_menu_page('Team Resources', 'Team Resources', 'manage_options', 'team-resources', function() {
            wp_redirect(admin_url('edit.php?post_type=team_resource'));
            exit;
        }, 'dashicons-media-document');
    }

    public function add_meta_boxes() {
        add_meta_box('team_details', 'Team Details', [$this, 'team_details_meta_box'], 'team');
        add_meta_box('resource_teams', 'Assigned Teams', [$this, 'resource_teams_meta_box'], 'team_resource');
        add_meta_box('user_team', 'User Team Assignment', [$this, 'user_team_meta_box'], 'user', 'side');
    }

    public function team_details_meta_box($post) {
        wp_nonce_field('save_team_details', 'team_details_nonce');
        $fields = ['school', 'contact_email', 'contact_person', 'team_id', 'wireless_password'];
        foreach ($fields as $field) {
            $value = get_post_meta($post->ID, $field, true);
            echo '<div class="myrobocon-field">';
            echo '<label>'.ucwords(str_replace('_', ' ', $field)).'</label>';
            echo '<input type="text" name="'.$field.'" value="'.esc_attr($value).'" style="width:100%">';
            echo '</div>';
        }
    }

    public function resource_teams_meta_box($post) {
        $teams = get_posts(['post_type' => 'team', 'numberposts' => -1]);
        $assigned = get_post_meta($post->ID, 'assigned_teams', true) ?: [];
        foreach ($teams as $team) {
            $checked = in_array($team->ID, $assigned) ? 'checked' : '';
            echo '<label><input type="checkbox" name="assigned_teams[]" value="'.$team->ID.'" '.$checked.'> '.$team->post_title.'</label><br>';
        }
    }

    public function user_team_meta_box($user) {
        $teams = get_posts(['post_type' => 'team', 'numberposts' => -1]);
        $user_team = get_user_meta($user->ID, 'user_team', true);
        echo '<select name="user_team" style="width:100%">';
        echo '<option value="">Select Team</option>';
        foreach ($teams as $team) {
            $selected = $user_team == $team->ID ? 'selected' : '';
            echo '<option value="'.$team->ID.'" '.$selected.'>'.$team->post_title.'</option>';
        }
        echo '</select>';
    }

    public function save_meta_data($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        
        if (get_post_type($post_id) === 'team' && isset($_POST['team_details_nonce'])) {
            if (!wp_verify_nonce($_POST['team_details_nonce'], 'save_team_details')) return;
            $fields = ['school', 'contact_email', 'contact_person', 'team_id', 'wireless_password'];
            foreach ($fields as $field) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
        
        if (get_post_type($post_id) === 'team_resource' && isset($_POST['assigned_teams'])) {
            update_post_meta($post_id, 'assigned_teams', array_map('intval', $_POST['assigned_teams']));
        }
    }

    public function redirect_non_logged_users() {
        if (is_page('teams-portal') && !is_user_logged_in()) {
            wp_redirect(wp_login_url(get_permalink()));
            exit;
        }
    }

    public function portal_shortcode() {
        if (!is_user_logged_in()) return '';
        
        $user_id = get_current_user_id();
        $team_id = get_user_meta($user_id, 'user_team', true);
        $team = get_post($team_id);
        $resources = get_posts([
            'post_type' => 'team_resource',
            'meta_query' => [[
                'key' => 'assigned_teams',
                'value' => $team_id,
                'compare' => 'LIKE'
            ]]
        ]);

        ob_start();
        echo '<div class="myrobocon-portal">';
        echo '<div class="team-info">';
        echo '<h2>'.esc_html($team->post_title).'</h2>';
        $fields = ['school', 'contact_email', 'contact_person', 'team_id', 'wireless_password'];
        foreach ($fields as $field) {
            $value = get_post_meta($team_id, $field, true);
            echo '<p><strong>'.ucwords(str_replace('_', ' ', $field)).':</strong> '.esc_html($value).'</p>';
        }
        echo '</div>';
        
        echo '<div class="team-resources">';
        echo '<h3>Team Resources</h3>';
        foreach ($resources as $resource) {
            echo '<div class="resource-item">';
            echo '<a href="'.get_permalink($resource->ID).'">'.esc_html($resource->post_title).'</a>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
        
        return ob_get_clean();
    }

    public function modify_resource_content($content) {
        if (is_singular('team_resource')) {
            remove_filter('the_content', [$this, 'modify_resource_content']);
            $content = wpautop($content);
            $content = '<div class="team-resource-content">'.$content.'</div>';
        }
        return $content;
    }

    public function disable_comments($open, $post_id) {
        if (get_post_type($post_id) === 'team_resource') return false;
        return $open;
    }

    public function import_teams_page() {
        echo '<div class="wrap"><h1>Import Teams</h1>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('team_import', 'team_import_nonce');
        echo '<input type="file" name="team_csv">';
        echo '<input type="submit" class="button button-primary" value="Import">';
        echo '</form></div>';
    }

    public function handle_team_import() {
        if (!isset($_POST['team_import_nonce']) || !wp_verify_nonce($_POST['team_import_nonce'], 'team_import')) return;
        
        if ($_FILES['team_csv']['error'] === UPLOAD_ERR_OK) {
            $csv = array_map('str_getcsv', file($_FILES['team_csv']['tmp_name']));
            $headers = array_shift($csv);
            
            foreach ($csv as $row) {
                $team_data = array_combine($headers, $row);
                $team_id = wp_insert_post([
                    'post_title' => $team_data['team_name'],
                    'post_type' => 'team',
                    'post_status' => 'publish'
                ]);
                
                foreach ($team_data as $key => $value) {
                    if ($key !== 'team_name') {
                        update_post_meta($team_id, $key, sanitize_text_field($value));
                    }
                }
            }
            
            echo '<div class="notice notice-success"><p>Teams imported successfully!</p></div>';
        }
    }
}

new MyRobocon();

// Create default pages on plugin activation
register_activation_hook(__FILE__, function() {
    if (!get_page_by_path('teams-portal')) {
        wp_insert_post([
            'post_title' => 'Teams Portal',
            'post_name' => 'teams-portal',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[myrobocon_portal]'
        ]);
    }
});