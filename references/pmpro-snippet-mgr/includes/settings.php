<?php
// Add a custom settings page under Memberships
function pmpro_snippet_mgr_menu() {
    add_submenu_page(
        'pmpro-membershiplevels', // Parent slug
        'Snippet Manager',        // Page title
        'Snippet Manager',        // Menu title
        'manage_options',         // Capability
        'pmpro-snippet-mgr',      // Menu slug
        'pmpro_snippet_mgr_settings_page' // Function to display the settings page
    );
}
add_action('admin_menu', 'pmpro_snippet_mgr_menu');

// Register settings
function pmpro_snippet_mgr_register_settings() {
    register_setting('pmpro-snippet-mgr-settings-group', 'pmpro_disable_redirect');
    register_setting('pmpro-snippet-mgr-settings-group', 'pmpro_default_membership_level');
    register_setting('pmpro-snippet-mgr-settings-group', 'pmpro_set_posts_to_draft');
    register_setting('pmpro-snippet-mgr-settings-group', 'pmpro_redirect_rules');
}
add_action('admin_init', 'pmpro_snippet_mgr_register_settings');

// Display settings page
function pmpro_snippet_mgr_settings_page() {
    ?>
    <div class="wrap">
        <h1>Snippet Manager</h1>
        <form method="post" action="options.php">
            <?php settings_fields('pmpro-snippet-mgr-settings-group'); ?>
            <?php do_settings_sections('pmpro-snippet-mgr-settings-group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Disable PMPro redirect to levels page</th>
                    <td>
                        <input type="checkbox" name="pmpro_disable_redirect" value="1" <?php checked(1, get_option('pmpro_disable_redirect'), true); ?> />
                        <p class="description">Redirect to Levels page for Registration is enabled by default</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Default Membership Level</th>
                    <td>
                        <input type="number" name="pmpro_default_membership_level" value="<?php echo esc_attr(get_option('pmpro_default_membership_level')); ?>" />
                        <p class="description">Enter the Level ID</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Set Posts to Draft on Membership Cancel</th>
                    <td>
                        <input type="checkbox" name="pmpro_set_posts_to_draft" value="1" <?php checked(1, get_option('pmpro_set_posts_to_draft'), true); ?> />
                        <p class="description">Set Member Author’s Posts to Draft When Membership is Cancelled.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Custom Redirect Rules</th>
                    <td>
                        <div id="redirect-rules-container">
                            <?php
                            $redirect_rules = get_option('pmpro_redirect_rules', []);
                            if (!empty($redirect_rules)) {
                                foreach ($redirect_rules as $index => $rule) {
                                    ?>
                                    <div class="redirect-rule">
                                        <label>Redirect <?php echo $index + 1; ?></label>
                                        <label>Level IDs (comma-separated)</label>
                                        <input type="text" name="pmpro_redirect_rules[<?php echo $index; ?>][level_ids]" value="<?php echo esc_attr($rule['level_ids']); ?>" />
                                        <label>Restricted URLs (one per line)</label>
                                        <textarea name="pmpro_redirect_rules[<?php echo $index; ?>][restricted_urls]"><?php echo esc_textarea($rule['restricted_urls']); ?></textarea>
                                        <p class="description">Use relative paths such as /your-page</p>
                                        <label>Redirect URL</label>
                                        <input type="text" name="pmpro_redirect_rules[<?php echo $index; ?>][redirect_url]" value="<?php echo esc_attr($rule['redirect_url']); ?>" />
                                        <p class="description">Use relative paths such as /your-page</p>
                                        <button type="button" class="remove-rule-button">Remove</button>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                        </div>
                        <button type="button" id="add-rule-button">Add Redirect Rule</button>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <style>
    .redirect-rule {
        border: 1px solid #ddd;
        padding: 10px;
        margin-bottom: 10px;
    }
    .redirect-rule label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }
    .redirect-rule input, .redirect-rule textarea {
        width: 100%;
        margin-bottom: 10px;
    }
    .redirect-rule .description { margin-bottom: 17px; }
    .remove-rule-button {
        display: block;
        margin-top: 10px;
        background-color: #dc3545;
        color: white;
        border: none;
        padding: 5px 10px;
        cursor: pointer;
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('add-rule-button').addEventListener('click', function() {
            var container = document.getElementById('redirect-rules-container');
            var index = container.children.length;
            var newRule = document.createElement('div');
            newRule.classList.add('redirect-rule');
            newRule.innerHTML = `
                <label>Redirect ${index + 1}</label>
                <label>Level IDs (comma-separated)</label>
                <input type="text" name="pmpro_redirect_rules[${index}][level_ids]" />
                <label>Restricted URLs (one per line)</label>
                <textarea name="pmpro_redirect_rules[${index}][restricted_urls]"></textarea>
                <p class="description">Use relative paths such as \`/your-page\`</p>
                <label>Redirect URL</label>
                <input type="text" name="pmpro_redirect_rules[${index}][redirect_url]" />
                <p class="description">Use relative paths such as \`/your-page\`</p>
                <button type="button" class="remove-rule-button">Remove</button>
            `;
            container.appendChild(newRule);
        });

        document.getElementById('redirect-rules-container').addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-rule-button')) {
                e.target.parentElement.remove();
            }
        });
    });
    </script>
    <?php
}
?>
