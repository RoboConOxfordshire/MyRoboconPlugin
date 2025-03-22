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
        user_id bigint(20) unsigned DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Team resources table
    $resources_table = $wpdb->prefix . 'myrobocon_resources';
    $sql_resources = "CREATE TABLE $resources_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        resource_name varchar(255) NOT NULL,
        resource_description text NOT NULL,
        assigned_teams text NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_teams);
    dbDelta($sql_resources);
}

// Add admin menus
add_action('admin_menu', 'myrobocon_admin_menus');

function myrobocon_admin_menus() {
    // Teams menu
    add_menu_page(
        'Teams',
        'Teams',
        'manage_options',
        'myrobocon_teams',
        'myrobocon_teams_page',
        'dashicons-groups',
        6
    );

    // Team Resources menu
    add_submenu_page(
        'myrobocon_teams',
        'Team Resources',
        'Team Resources',
        'manage_options',
        'myrobocon_resources',
        'myrobocon_resources_page'
    );
}

// Teams page
function myrobocon_teams_page() {
    global $wpdb;
    $teams_table = $wpdb->prefix . 'myrobocon_teams';

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
                        'wireless_password' => $data[3],
                        'user_id' => $data[4]
                    ));
                }
                fclose($handle);
                echo '<div class="notice notice-success"><p>Teams imported successfully!</p></div>';
            }
        } elseif (isset($_POST['reset_teams'])) {
            // Reset teams table
            $wpdb->query("TRUNCATE TABLE $teams_table");
            echo '<div class="notice notice-success"><p>Teams table reset successfully!</p></div>';
        } elseif (isset($_POST['edit_team'])) {
            // Edit team
            $team_id = intval($_POST['team_id']);
            $wpdb->update($teams_table, array(
                'school' => sanitize_text_field($_POST['school']),
                'contact_email' => sanitize_email($_POST['contact_email']),
                'robocon_brain_id' => sanitize_text_field($_POST['robocon_brain_id']),
                'wireless_password' => sanitize_text_field($_POST['wireless_password']),
                'user_id' => intval($_POST['user_id'])
            ), array('id' => $team_id));
            echo '<div class="notice notice-success"><p>Team updated successfully!</p></div>';
        } elseif (isset($_POST['assign_user'])) {
            // Assign user to team
            $team_id = intval($_POST['team_id']);
            $user_id = intval($_POST['user_id']);
            $wpdb->update($teams_table, array('user_id' => $user_id), array('id' => $team_id));
            echo '<div class="notice notice-success"><p>User assigned successfully!</p></div>';
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
            <thead><tr><th>School</th><th>Contact Email</th><th>Robocon Brain ID</th><th>Wireless Password</th><th>Assigned User</th><th>Actions</th></tr></thead>
            <tbody>';
    foreach ($teams as $team) {
        $user = $team->user_id ? get_user_by('id', $team->user_id) : null;
        echo '<tr>
                <td>' . esc_html($team->school) . '</td>
                <td>' . esc_html($team->contact_email) . '</td>
                <td>' . esc_html($team->robocon_brain_id) . '</td>
                <td>' . esc_html($team->wireless_password) . '</td>
                <td>' . ($user ? esc_html($user->user_login) : 'None') . '</td>
                <td>
                    <a href="#" onclick="showEditForm(' . $team->id . ')">Edit</a> | 
                    <a href="#" onclick="showAssignForm(' . $team->id . ')">Assign User</a>
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

    // Assign user form (hidden by default)
    echo '<div id="assignUserForm" style="display:none;">
            <h2>Assign User to Team</h2>
            <form method="post">
                <input type="hidden" name="team_id" id="assignTeamId">
                <label>User: <select name="user_id" required>';
    $users = get_users();
    foreach ($users as $user) {
        echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->user_login) . '</option>';
    }
    echo '</select></label><br>
          <input type="submit" name="assign_user" value="Assign User" class="button button-primary">
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

// Team Resources page
function myrobocon_resources_page() {
    global $wpdb;
    $resources_table = $wpdb->prefix . 'myrobocon_resources';
    $teams_table = $wpdb->prefix . 'myrobocon_teams';

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['create_resource'])) {
            // Create new resource
            $wpdb->insert($resources_table, array(
                'resource_name' => sanitize_text_field($_POST['resource_name']),
                'resource_description' => sanitize_textarea_field($_POST['resource_description']),
                'assigned_teams' => serialize($_POST['assigned_teams'])
            ));
            echo '<div class="notice notice-success"><p>Resource created successfully!</p></div>';
        } elseif (isset($_POST['edit_resource'])) {
            // Edit resource
            $resource_id = intval($_POST['resource_id']);
            $wpdb->update($resources_table, array(
                'resource_name' => sanitize_text_field($_POST['resource_name']),
                'resource_description' => sanitize_textarea_field($_POST['resource_description']),
                'assigned_teams' => serialize($_POST['assigned_teams'])
            ), array('id' => $resource_id));
            echo '<div class="notice notice-success"><p>Resource updated successfully!</p></div>';
        } elseif (isset($_POST['delete_resource'])) {
            // Delete resource
            $resource_id = intval($_POST['resource_id']);
            $wpdb->delete($resources_table, array('id' => $resource_id));
            echo '<div class="notice notice-success"><p>Resource deleted successfully!</p></div>';
        }
    }

    // Display resources table
    $resources = $wpdb->get_results("SELECT * FROM $resources_table");
    echo '<div class="wrap"><h1>Team Resources</h1>';
    echo '<form method="post">
            <input type="text" name="resource_name" placeholder="Resource Name" required>
            <textarea name="resource_description" placeholder="Resource Description" required></textarea>
            <select name="assigned_teams[]" multiple required>';
    $teams = $wpdb->get_results("SELECT id, school FROM $teams_table");
    foreach ($teams as $team) {
        echo '<option value="' . esc_attr($team->id) . '">' . esc_html($team->school) . '</option>';
    }
    echo '</select>
          <input type="submit" name="create_resource" value="Create Resource" class="button button-primary">
          </form>';
    echo '<table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Resource Name</th><th>Assigned Teams</th><th>Actions</th></tr></thead>
            <tbody>';
    foreach ($resources as $resource) {
        $assigned_teams = unserialize($resource->assigned_teams);
        $team_names = array();
        foreach ($teams as $team) {
            if (in_array($team->id, $assigned_teams)) {
                $team_names[] = $team->school;
            }
        }
        echo '<tr>
                <td>' . esc_html($resource->resource_name) . '</td>
                <td>' . implode(', ', $team_names) . '</td>
                <td>
                    <a href="#" onclick="showEditResourceForm(' . $resource->id . ', \'' . esc_js($resource->resource_name) . '\', \'' . esc_js($resource->resource_description) . '\', ' . esc_js(json_encode($assigned_teams)) . ')">Edit</a> | 
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="resource_id" value="' . $resource->id . '">
                        <input type="submit" name="delete_resource" value="Delete" class="button button-secondary" onclick="return confirm(\'Are you sure you want to delete this resource?\');">
                    </form>
                </td>
              </tr>';
    }
    echo '</tbody></table></div>';

    // Edit resource form (hidden by default)
    echo '<div id="editResourceForm" style="display:none;">
            <h2>Edit Resource</h2>
            <form method="post">
                <input type="hidden" name="resource_id" id="editResourceId">
                <label>Resource Name: <input type="text" name="resource_name" id="editResourceName" required></label><br>
                <label>Resource Description: <textarea name="resource_description" id="editResourceDescription" required></textarea></label><br>
                <label>Assigned Teams: <select name="assigned_teams[]" id="editAssignedTeams" multiple required>';
    foreach ($teams as $team) {
        echo '<option value="' . esc_attr($team->id) . '">' . esc_html($team->school) . '</option>';
    }
    echo '</select></label><br>
          <input type="submit" name="edit_resource" value="Save Changes" class="button button-primary">
          </form>
          </div>';

    // JavaScript to show/hide edit form
    echo '<script>
            function showEditResourceForm(resourceId, resourceName, resourceDescription, assignedTeams) {
                document.getElementById("editResourceId").value = resourceId;
                document.getElementById("editResourceName").value = resourceName;
                document.getElementById("editResourceDescription").value = resourceDescription;
                var select = document.getElementById("editAssignedTeams");
                for (var i = 0; i < select.options.length; i++) {
                    select.options[i].selected = assignedTeams.includes(parseInt(select.options[i].value));
                }
                document.getElementById("editResourceForm").style.display = "block";
            }
          </script>';
}

// Front-end shortcode
add_shortcode('myrobocon_portal', 'myrobocon_portal_shortcode');

function myrobocon_portal_shortcode() {
    if (!is_user_logged_in()) {
        auth_redirect();
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $teams_table = $wpdb->prefix . 'myrobocon_teams';
    $team = $wpdb->get_row("SELECT * FROM $teams_table WHERE user_id = $user_id");

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

    $resources_table = $wpdb->prefix . 'myrobocon_resources';
    $resources = $wpdb->get_results("SELECT * FROM $resources_table");
    foreach ($resources as $resource) {
        $assigned_teams = unserialize($resource->assigned_teams);
        if (in_array($team->id, $assigned_teams)) {
            $output .= '<div><h3>' . esc_html($resource->resource_name) . '</h3><p>' . esc_html($resource->resource_description) . '</p></div>';
        }
    }

    $output .= '</div>';
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