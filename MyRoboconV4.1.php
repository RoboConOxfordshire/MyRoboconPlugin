<?php
/*
Plugin Name: MyRobocon
Plugin URI: https://example.com/myrobocon
Description: A plugin to manage teams participating in Robocon Oxfordshire and assign resources to teams.
Version: 3.5.2
Author: Don Wong
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Create database tables on plugin activation
register_activation_hook(__FILE__, 'myrobocon_create_tables');

function myrobocon_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Teams table
    $teams_table = $wpdb->prefix . 'myrobocon_teams';
    $sql_teams = "CREATE TABLE $teams_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        school varchar(255) NOT NULL,
        contact_email varchar(255) NOT NULL,
        robocon_brain_id varchar(100) NOT NULL,
        wireless_password varchar(100) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Team Users table (to support multiple users per team)
    $team_users_table = $wpdb->prefix . 'myrobocon_team_users';
    $sql_team_users = "CREATE TABLE $team_users_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        team_id mediumint(9) NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        PRIMARY KEY (id),
        FOREIGN KEY (team_id) REFERENCES $teams_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_teams);
    dbDelta($sql_team_users);
}

// Register Team Resources as a custom post type
add_action('init', 'myrobocon_register_resource_post_type');

function myrobocon_register_resource_post_type() {
    register_post_type('team_resource',
        array(
            'labels' => array(
                'name' => __('Team Resources'),
                'singular_name' => __('Team Resource')
            ),
            'public' => true,
            'has_archive' => true,
            'supports' => array('title', 'editor', 'custom-fields'),
            'show_in_menu' => 'myrobocon', // Show under the main MyRobocon menu
        )
    );
}

// Add meta box for assigning teams to resources
add_action('add_meta_boxes', 'myrobocon_add_resource_meta_box');

function myrobocon_add_resource_meta_box() {
    add_meta_box(
        'myrobocon_assigned_teams',
        'Assigned Teams',
        'myrobocon_render_assigned_teams_meta_box',
        'team_resource',
        'side',
        'default'
    );
}

function myrobocon_render_assigned_teams_meta_box($post) {
    global $wpdb;
    $teams_table = $wpdb->prefix . 'myrobocon_teams';
    $teams = $wpdb->get_results("SELECT * FROM $teams_table");

    // Get currently assigned teams
    $assigned_teams = get_post_meta($post->ID, 'assigned_teams', true);
    if (!is_array($assigned_teams)) {
        $assigned_teams = array();
    }

    // Render team checkboxes
    foreach ($teams as $team) {
        $checked = in_array($team->id, $assigned_teams) ? 'checked' : '';
        echo '<label>
                <input type="checkbox" name="assigned_teams[]" value="' . esc_attr($team->id) . '" ' . $checked . '>
                ' . esc_html($team->school) . '
              </label><br>';
    }
}

// Save assigned teams when the resource is saved
add_action('save_post', 'myrobocon_save_assigned_teams');

function myrobocon_save_assigned_teams($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (get_post_type($post_id) !== 'team_resource') return;

    // Save assigned teams
    if (isset($_POST['assigned_teams'])) {
        $assigned_teams = array_map('intval', $_POST['assigned_teams']);
        update_post_meta($post_id, 'assigned_teams', $assigned_teams);
    } else {
        delete_post_meta($post_id, 'assigned_teams');
    }
}

// Add admin menus
add_action('admin_menu', 'myrobocon_admin_menus');

function myrobocon_admin_menus() {
    // Main MyRobocon menu
    add_menu_page(
        'MyRobocon',
        'MyRobocon',
        'manage_options',
        'myrobocon',
        'myrobocon_teams_page',
        'dashicons-groups',
        6
    );

    // Teams submenu
    add_submenu_page(
        'myrobocon',
        'Teams',
        'Teams',
        'manage_options',
        'myrobocon_teams',
        'myrobocon_teams_page'
    );

    // Team Resources submenu (now handled by the custom post type)
    add_submenu_page(
        'myrobocon',
        'Team Resources',
        'Team Resources',
        'manage_options',
        'edit.php?post_type=team_resource',
        null
    );
}

// Teams page
function myrobocon_teams_page() {
    global $wpdb;
    $teams_table = $wpdb->prefix . 'myrobocon_teams';
    $team_users_table = $wpdb->prefix . 'myrobocon_team_users';

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['import_teams'])) {
            // Handle CSV import
            if ($_FILES['csv_file']['error'] === 0) {
                $file = $_FILES['csv_file']['tmp_name'];
                $handle = fopen($file, 'r');
                while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    $wpdb->insert($teams_table, array(
                        'school' => $data[0],
                        'contact_email' => $data[1],
                        'robocon_brain_id' => $data[2],
                        'wireless_password' => $data[3]
                    ));
                }
                fclose($handle);
                echo '<div class="notice notice-success"><p>Teams imported successfully!</p></div>';
            }
        } elseif (isset($_POST['reset_teams'])) {
            // Reset teams table
            $wpdb->query("TRUNCATE TABLE $teams_table");
            $wpdb->query("TRUNCATE TABLE $team_users_table");
            echo '<div class="notice notice-success"><p>Teams table reset successfully!</p></div>';
        } elseif (isset($_POST['edit_team'])) {
            // Edit team
            $team_id = intval($_POST['team_id']);
            $wpdb->update($teams_table, array(
                'school' => sanitize_text_field($_POST['school']),
                'contact_email' => sanitize_email($_POST['contact_email']),
                'robocon_brain_id' => sanitize_text_field($_POST['robocon_brain_id']),
                'wireless_password' => sanitize_text_field($_POST['wireless_password'])
            ), array('id' => $team_id));
            echo '<div class="notice notice-success"><p>Team updated successfully!</p></div>';
        } elseif (isset($_POST['assign_users'])) {
            // Assign users to team
            $team_id = intval($_POST['team_id']);
            $user_ids = array_map('intval', $_POST['user_ids']);
            $wpdb->delete($team_users_table, array('team_id' => $team_id)); // Remove existing assignments
            foreach ($user_ids as $user_id) {
                $wpdb->insert($team_users_table, array(
                    'team_id' => $team_id,
                    'user_id' => $user_id
                ));
            }
            echo '<div class="notice notice-success"><p>Users assigned successfully!</p></div>';
        }
    }

    // Display teams table
    $teams = $wpdb->get_results("SELECT * FROM $teams_table");
    echo '<div class="wrap"><h1>Teams</h1>';
    echo '<form method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv">
            <input type="submit" name="import_teams" value="Import Teams" class="button button-primary">
            <input type="submit" name="reset_teams" value="Reset Teams" class="button button-secondary">
          </form>';
    echo '<table class="wp-list-table widefat fixed striped">
            <thead><tr><th>School</th><th>Contact Email</th><th>Robocon Brain ID</th><th>Wireless Password</th><th>Assigned Users</th><th>Actions</th></tr></thead>
            <tbody>';
    foreach ($teams as $team) {
        $assigned_users = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.user_login FROM $team_users_table tu
             JOIN {$wpdb->users} u ON tu.user_id = u.ID
             WHERE tu.team_id = %d",
            $team->id
        ));
        $user_logins = array();
        foreach ($assigned_users as $user) {
            $user_logins[] = $user->user_login;
        }
        echo '<tr>
                <td>' . esc_html($team->school) . '</td>
                <td>' . esc_html($team->contact_email) . '</td>
                <td>' . esc_html($team->robocon_brain_id) . '</td>
                <td>' . esc_html($team->wireless_password) . '</td>
                <td>' . implode(', ', $user_logins) . '</td>
                <td>
                    <a href="#" onclick="showEditForm(' . $team->id . ')">Edit</a> | 
                    <a href="#" onclick="showAssignForm(' . $team->id . ')">Assign Users</a>
                </td>
              </tr>';
    }
    echo '</tbody></table></div>';

    // Edit team form (hidden by default)
    echo '<div id="editTeamForm" style="display:none;">
            <h2>Edit Team</h2>
            <form method="post">
                <input type="hidden" name="team_id" id="editTeamId">
                <label>School: <input type="text" name="school" required></label><br>
                <label>Contact Email: <input type="email" name="contact_email" required></label><br>
                <label>Robocon Brain ID: <input type="text" name="robocon_brain_id" required></label><br>
                <label>Wireless Password: <input type="text" name="wireless_password" required></label><br>
                <input type="submit" name="edit_team" value="Save Changes" class="button button-primary">
            </form>
          </div>';

    // Assign users form (hidden by default)
    echo '<div id="assignUserForm" style="display:none;">
            <h2>Assign Users to Team</h2>
            <form method="post">
                <input type="hidden" name="team_id" id="assignTeamId">
                <label>Users: <select name="user_ids[]" multiple required>';
    $users = get_users();
    foreach ($users as $user) {
        echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->user_login) . '</option>';
    }
    echo '</select></label><br>
          <input type="submit" name="assign_users" value="Assign Users" class="button button-primary">
          </form>
          </div>';

    // JavaScript to show/hide forms
    echo '<script>
            function showEditForm(teamId) {
                document.getElementById("editTeamId").value = teamId;
                document.getElementById("editTeamForm").style.display = "block";
            }
            function showAssignForm(teamId) {
                document.getElementById("assignTeamId").value = teamId;
                document.getElementById("assignUserForm").style.display = "block";
            }
          </script>';
}

// Front-end shortcode (show popup for non-logged-in users)
add_shortcode('myrobocon_portal', 'myrobocon_portal_shortcode');

function myrobocon_portal_shortcode() {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        // Show a popup message prompting the user to sign in
        return '
            <div id="myrobocon-login-popup" style="display: block; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border: 1px solid #ddd; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); z-index: 1000;">
                <p>You must be logged in to access this page.</p>
                <a href="' . wp_login_url(get_permalink()) . '" style="display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none;">Sign In</a>
            </div>
            <style>
                #myrobocon-login-popup {
                    text-align: center;
                }
            </style>
        ';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $teams_table = $wpdb->prefix . 'myrobocon_teams';
    $team_users_table = $wpdb->prefix . 'myrobocon_team_users';

    // Get the team assigned to the current user
    $team = $wpdb->get_row($wpdb->prepare(
        "SELECT t.* FROM $teams_table t
         JOIN $team_users_table tu ON t.id = tu.team_id
         WHERE tu.user_id = %d",
        $user_id
    ));

    if (!$team) {
        return '<p>No team assigned to your account.</p>';
    }

    $output = '<div class="myrobocon-portal">
                <h1>Team Information</h1>
                <p><strong>School:</strong> ' . esc_html($team->school) . '</p>
                <p><strong>Contact Email:</strong> ' . esc_html($team->contact_email) . '</p>
                <p><strong>Robocon Brain ID:</strong> ' . esc_html($team->robocon_brain_id) . '</p>
                <p><strong>Wireless Password:</strong> ' . esc_html($team->wireless_password) . '</p>
                <h2>Assigned Resources</h2>';

    // Get resources assigned to the team
    $resources = get_posts(array(
        'post_type' => 'team_resource',
        'meta_key' => 'assigned_teams',
        'meta_value' => $team->id,
        'meta_compare' => 'LIKE'
    ));

    foreach ($resources as $resource) {
        $output .= '<div class="resource-item">
                      <div class="resource-title" onclick="toggleResource(' . $resource->ID . ')">
                        <strong>' . esc_html($resource->post_title) . '</strong>
                      </div>
                      <div class="resource-content" id="resourceContent' . $resource->ID . '" style="display:none;">
                        <div>' . wpautop(wp_kses_post($resource->post_content)) . '</div>
                      </div>
                    </div>';
    }

    $output .= '</div>';

    // JavaScript for expandable resources
    $output .= '<script>
                  function toggleResource(resourceId) {
                      var content = document.getElementById("resourceContent" + resourceId);
                      if (content.style.display === "none") {
                          content.style.display = "block";
                      } else {
                          content.style.display = "none";
                      }
                  }
                </script>';

    // Add some basic CSS for the front-end
    $output .= '<style>
                  .resource-item {
                      margin-bottom: 10px;
                      border: 1px solid #ddd;
                      padding: 10px;
                      cursor: pointer;
                  }
                  .resource-title {
                      font-size: 1.2em;
                      font-weight: bold;
                  }
                  .resource-content {
                      margin-top: 10px;
                  }
                </style>';

    return $output;
}

// Flush rewrite rules on plugin activation
register_activation_hook(__FILE__, 'myrobocon_flush_rewrite_rules');

function myrobocon_flush_rewrite_rules() {
    flush_rewrite_rules();
}

// Create /myrobocon page on plugin activation
register_activation_hook(__FILE__, 'myrobocon_create_page');

function myrobocon_create_page() {
    if (!get_page_by_path('myrobocon')) {
        wp_insert_post(array(
            'post_title' => 'MyRobocon',
            'post_content' => '[myrobocon_portal]',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'page',
            'post_name' => 'myrobocon'
        ));
    }
}