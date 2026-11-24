<?php
defined('ABSPATH') || exit;

/**
 * Folders for the Media Library, replacing FileBird: a folder tree beside Media > Library (grid and list),
 * drag files or folders to move them, a folder filter in every media picker, uploads into the chosen
 * folder, a Folder field on each file, Folder and File size columns, and zip download of a folder.
 *
 * It keeps FileBird's own tables and rules, so a site's existing folders carry over with nothing migrated,
 * and FileBird can be switched back on at any time:
 *   wp_fbv                    id, name, parent (0 = top level), type (always 0), ord (order among siblings), created_by (0 = shared tree)
 *   wp_fbv_attachment_folder  folder_id, attachment_id: one folder per file; no row = Uncategorized
 * Folder ids in the UI: -1 All files, 0 Uncategorized, >0 a folder. Deleting a folder deletes its subfolders
 * too; the files inside are never deleted, they become Uncategorized.
 *
 * Stays off while FileBird is active (Site_MCP_Takeover switches FileBird off when that is safe), and while
 * a site's own Site Features copy of this feature is still installed.
 */
class Site_MCP_Media_Folders
{
    const OPTION = 'site_mcp_media_folders';
    const NS = 'site-mcp/v1';
    /** Shared folders only. FileBird's per-user mode keeps this off (see blocked()). */
    const SCOPE = 'created_by = 0 AND type = 0';

    public static function enabled()
    {
        return get_option(self::OPTION, '1') === '1';
    }

    /** Why the folders can't run on this site right now, or null. */
    public static function blocked()
    {
        if (defined('NJFB_VERSION')) {
            return 'FileBird is active.';
        }
        if (function_exists('bgmf_boot')) {
            return 'This site\'s Site Features has its own media folders feature (features/media-folders). Remove it to use the built-in one.';
        }
        if (get_option('njt_fbv_folder_per_user', '0') === '1') {
            return 'FileBird was set to give each user their own folders, which the built-in folders don\'t support.';
        }
        return null;
    }

    public static function active()
    {
        return self::enabled() && !self::blocked();
    }

    public static function boot()
    {
        if (!self::active()) {
            return;
        }
        self::install();

        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('media_library_infinite_scrolling', '__return_true'); // as FileBird did: scroll instead of "Load more"
        add_filter('ajax_query_attachments_args', [__CLASS__, 'ajax_query_args']);
        add_action('pre_get_posts', [__CLASS__, 'list_query']);
        add_filter('posts_clauses', [__CLASS__, 'posts_clauses'], 10, 2);
        add_action('add_attachment', [__CLASS__, 'on_upload']);
        add_action('delete_attachment', [__CLASS__, 'on_delete']);
        add_filter('wp_edited_image_metadata', [__CLASS__, 'on_edited_image'], 10, 3);
        add_filter('wp_prepare_attachment_for_js', [__CLASS__, 'prepare_js'], 10, 2);
        add_filter('attachment_fields_to_edit', [__CLASS__, 'fields_edit'], 10, 2);
        add_filter('attachment_fields_to_save', [__CLASS__, 'fields_save'], 10, 2);
        add_filter('manage_media_columns', [__CLASS__, 'columns']);
        add_filter('manage_upload_sortable_columns', [__CLASS__, 'sortable_columns']);
        add_action('manage_media_custom_column', [__CLASS__, 'column'], 10, 2);
        add_action('restrict_manage_posts', [__CLASS__, 'list_filter']);
        add_action('pre-upload-ui', [__CLASS__, 'upload_ui']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_enqueue_media', [__CLASS__, 'assets']);
        add_action('wp_ajax_smcpmf_download', [__CLASS__, 'download']);
    }

    /* ---------- Storage ---------- */

    private static function t()
    {
        global $wpdb;
        return (object) ['f' => $wpdb->prefix . 'fbv', 'r' => $wpdb->prefix . 'fbv_attachment_folder'];
    }

    /** Create the tables if missing (sites that had FileBird already have them) and stop FileBird's leftover cron jobs. */
    private static function install()
    {
        if (get_option('site_mcp_media_folders_db') === '1') {
            return;
        }
        global $wpdb;
        $t = self::t();
        $c = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t->f} (id int(11) unsigned NOT NULL AUTO_INCREMENT, name varchar(250) NOT NULL, parent int(11) NOT NULL DEFAULT 0, type int(2) NOT NULL DEFAULT 0, ord int(11) DEFAULT 0, created_by int(11) DEFAULT 0, PRIMARY KEY (id)) $c");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t->r} (folder_id int(11) unsigned NOT NULL, attachment_id bigint(20) unsigned NOT NULL, PRIMARY KEY (folder_id, attachment_id)) $c");
        wp_clear_scheduled_hook('filebird_every_12_hours_jobs');
        wp_clear_scheduled_hook('filebird_remove_zip_files');
        update_option('site_mcp_media_folders_db', '1', false);
    }

    /** Attachments the library lists. */
    private static function status_sql($alias = 'p')
    {
        return "$alias.post_type = 'attachment' AND $alias.post_status NOT IN ('trash', 'auto-draft')";
    }

    private static function scope($alias)
    {
        return "$alias." . str_replace(' AND ', " AND $alias.", self::SCOPE);
    }

    /** All folders as [id => row], cached per request. */
    private static function rows($fresh = false)
    {
        static $rows = null;
        if ($rows === null || $fresh) {
            global $wpdb;
            $t = self::t();
            $rows = [];
            foreach ($wpdb->get_results("SELECT id, name, parent, ord FROM {$t->f} WHERE " . self::SCOPE . ' ORDER BY ord+0, id') as $r) {
                $rows[(int) $r->id] = ['id' => (int) $r->id, 'name' => $r->name, 'parent' => (int) $r->parent, 'ord' => (int) $r->ord];
            }
        }
        return $rows;
    }

    private static function exists($id)
    {
        return isset(self::rows()[(int) $id]);
    }

    private static function children($parent)
    {
        return array_values(array_filter(self::rows(), function ($r) use ($parent) {
            return $r['parent'] === (int) $parent;
        }));
    }

    private static function descendants($id)
    {
        $out = [];
        $stack = [(int) $id];
        while ($stack) {
            foreach (self::children(array_pop($stack)) as $c) {
                if (!in_array($c['id'], $out, true)) {
                    $out[] = $c['id'];
                    $stack[] = $c['id'];
                }
            }
        }
        return $out;
    }

    /** Folders in tree order with their depth, for dropdowns. */
    private static function flat($parent = 0, $depth = 0)
    {
        $out = [];
        foreach (self::children($parent) as $c) {
            $out[] = $c + ['depth' => $depth];
            $out = array_merge($out, self::flat($c['id'], $depth + 1));
        }
        return $out;
    }

    /** Map attachment id => folder id, cached per request. */
    private static function map($fresh = false)
    {
        static $map = null;
        if ($map === null || $fresh) {
            global $wpdb;
            $t = self::t();
            $map = [];
            foreach ($wpdb->get_results("SELECT r.attachment_id a, r.folder_id f FROM {$t->r} r INNER JOIN {$t->f} f ON f.id = r.folder_id AND " . self::scope('f')) as $r) {
                $map[(int) $r->a] = (int) $r->f;
            }
        }
        return $map;
    }

    private static function folder_of($attachment_id)
    {
        return self::map()[(int) $attachment_id] ?? 0;
    }

    /** Tree for the UI: folders with file counts, plus the All and Uncategorized totals. */
    public static function tree()
    {
        global $wpdb;
        $t = self::t();
        $st = self::status_sql();
        $counts = [];
        foreach ($wpdb->get_results("SELECT r.folder_id f, COUNT(*) n FROM {$t->r} r INNER JOIN {$wpdb->posts} p ON p.ID = r.attachment_id WHERE $st GROUP BY r.folder_id") as $r) {
            $counts[(int) $r->f] = (int) $r->n;
        }
        $folders = [];
        foreach (self::rows(true) as $r) {
            $folders[] = $r + ['count' => $counts[$r['id']] ?? 0];
        }
        $all = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $st");
        $in = (int) $wpdb->get_var("SELECT COUNT(DISTINCT r.attachment_id) FROM {$t->r} r INNER JOIN {$t->f} f ON f.id = r.folder_id AND " . self::scope('f') . " INNER JOIN {$wpdb->posts} p ON p.ID = r.attachment_id WHERE $st");
        return ['folders' => $folders, 'all' => $all, 'uncategorized' => max(0, $all - $in)];
    }

    /* ---------- Folder operations ---------- */

    private static function clean_name($name)
    {
        $name = trim(sanitize_text_field((string) $name));
        return function_exists('mb_substr') ? mb_substr($name, 0, 200) : substr($name, 0, 200);
    }

    private static function name_taken($name, $parent, $except = 0)
    {
        foreach (self::children($parent) as $c) {
            if ($c['id'] !== (int) $except && strcasecmp($c['name'], $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** "Name", or "Name (1)", "Name (2)"... if that is taken under the same parent (FileBird's rule). */
    private static function unique_name($name, $parent, $except = 0)
    {
        $try = $name;
        for ($i = 1; self::name_taken($try, $parent, $except); $i++) {
            $try = $name . ' (' . $i . ')';
        }
        return $try;
    }

    private static function create($name, $parent)
    {
        global $wpdb;
        $t = self::t();
        $name = self::clean_name($name);
        $parent = (int) $parent;
        if ($name === '') {
            return new WP_Error('smcpmf_name', 'Please enter a folder name.', ['status' => 400]);
        }
        if ($parent && !self::exists($parent)) {
            return new WP_Error('smcpmf_missing', 'That folder no longer exists.', ['status' => 404]);
        }
        $siblings = self::children($parent);
        $ord = $siblings ? max(array_column($siblings, 'ord')) + 1 : 0;
        $wpdb->insert($t->f, ['name' => self::unique_name($name, $parent), 'parent' => $parent, 'type' => 0, 'ord' => $ord, 'created_by' => 0], ['%s', '%d', '%d', '%d', '%d']);
        self::rows(true);
        return (int) $wpdb->insert_id;
    }

    private static function rename($id, $name)
    {
        global $wpdb;
        $t = self::t();
        $name = self::clean_name($name);
        if ($name === '') {
            return new WP_Error('smcpmf_name', 'Please enter a folder name.', ['status' => 400]);
        }
        $row = self::rows()[$id];
        if (self::name_taken($name, $row['parent'], $id)) {
            return new WP_Error('smcpmf_exists', 'A folder with this name already exists here. Please choose another one.', ['status' => 409]);
        }
        $wpdb->update($t->f, ['name' => $name], ['id' => $id], ['%s'], ['%d']);
        self::rows(true);
        return true;
    }

    /** Put a folder under $parent at position $index among its new siblings. */
    private static function move($id, $parent, $index)
    {
        global $wpdb;
        $t = self::t();
        if ($parent === $id || in_array($parent, self::descendants($id), true)) {
            return new WP_Error('smcpmf_loop', 'A folder cannot go inside itself.', ['status' => 400]);
        }
        if ($parent && !self::exists($parent)) {
            return new WP_Error('smcpmf_missing', 'That folder no longer exists.', ['status' => 404]);
        }
        $ids = array_values(array_diff(array_column(self::children($parent), 'id'), [$id]));
        array_splice($ids, max(0, min((int) $index, count($ids))), 0, [$id]);
        $name = self::unique_name(self::rows()[$id]['name'], $parent, $id);
        foreach ($ids as $i => $sid) {
            $data = $sid === $id ? ['ord' => $i, 'parent' => $parent, 'name' => $name] : ['ord' => $i];
            $wpdb->update($t->f, $data, ['id' => $sid]);
        }
        self::rows(true);
        return true;
    }

    /** Delete a folder and its subfolders. Files inside are not deleted: they become Uncategorized. */
    private static function delete($id)
    {
        global $wpdb;
        $t = self::t();
        $ids = array_map('intval', array_merge([$id], self::descendants($id)));
        $in = implode(',', $ids);
        $wpdb->query("DELETE FROM {$t->r} WHERE folder_id IN ($in)");
        $wpdb->query("DELETE FROM {$t->f} WHERE id IN ($in)");
        self::rows(true);
        self::map(true);
        return $ids;
    }

    /** Put files into a folder (0 = Uncategorized). Each file is in one folder at most. */
    private static function assign($folder, $ids, $check = true)
    {
        global $wpdb;
        $t = self::t();
        $folder = (int) $folder;
        if ($folder > 0 && !self::exists($folder)) {
            return new WP_Error('smcpmf_missing', 'That folder no longer exists.', ['status' => 404]);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids), function ($id) use ($check) {
            return $id > 0 && get_post_type($id) === 'attachment' && (!$check || current_user_can('edit_post', $id));
        })));
        if (!$ids) {
            return new WP_Error('smcpmf_nothing', 'There is nothing to move.', ['status' => 400]);
        }
        $in = implode(',', $ids);
        // Drop current folder rows for these files (shared-tree folders, or rows pointing at folders that no longer exist).
        $wpdb->query("DELETE FROM {$t->r} WHERE attachment_id IN ($in) AND folder_id NOT IN (SELECT id FROM {$t->f} WHERE NOT (" . self::SCOPE . '))');
        if ($folder > 0) {
            $values = implode(',', array_map(function ($id) use ($wpdb, $folder) {
                return $wpdb->prepare('(%d,%d)', $folder, $id);
            }, $ids));
            $wpdb->query("INSERT IGNORE INTO {$t->r} (folder_id, attachment_id) VALUES $values");
        }
        self::map(true);
        return count($ids);
    }

    /* ---------- Which folder is open (remembered per user, like FileBird's "most recent folder") ---------- */

    private static function current_folder()
    {
        static $cur = null;
        if ($cur !== null) {
            return $cur;
        }
        $uid = get_current_user_id();
        if (isset($_GET['smcp_folder'])) {
            $cur = (int) $_GET['smcp_folder'];
            if ($cur > 0 && !self::exists($cur)) {
                $cur = -1;
            }
            update_user_meta($uid, 'smcpmf_folder', $cur);
            return $cur;
        }
        $saved = get_user_meta($uid, 'smcpmf_folder', true);
        foreach (['bgmf_folder', 'fbv_folder_startup'] as $older) { // carry over an earlier copy's or FileBird's last folder
            if ($saved === '') {
                $saved = get_user_meta($uid, $older, true);
            }
        }
        $cur = $saved === '' ? -1 : (int) $saved;
        if (($cur > 0 && !self::exists($cur)) || $cur < -1) {
            $cur = -1;
        }
        return $cur;
    }

    /* ---------- Queries ---------- */

    /** Grid view and media pickers send the folder as query[smcp_folder] (core strips unknown keys, so read the request). */
    public static function ajax_query_args($query)
    {
        if (isset($_REQUEST['query']['smcp_folder']) && $_REQUEST['query']['smcp_folder'] !== '') {
            $query['smcpmf_folder'] = (int) $_REQUEST['query']['smcp_folder'];
        }
        return $query;
    }

    /** List view: filter by the open folder; sort by file size when asked. */
    public static function list_query($q)
    {
        global $pagenow;
        if (!is_admin() || $pagenow !== 'upload.php' || !$q->is_main_query()) {
            return;
        }
        $q->set('smcpmf_folder', self::current_folder());
        if ($q->get('orderby') === 'smcpmf_size') {
            $q->set('meta_key', 'fb_filesize');
            $q->set('orderby', 'meta_value_num');
        }
    }

    public static function posts_clauses($clauses, $query)
    {
        $f = $query->get('smcpmf_folder');
        if ($f === '' || $f === null || (int) $f < 0) {
            return $clauses;
        }
        global $wpdb;
        $t = self::t();
        if ((int) $f === 0) {
            $clauses['where'] .= " AND {$wpdb->posts}.ID NOT IN (SELECT r.attachment_id FROM {$t->r} r INNER JOIN {$t->f} f ON f.id = r.folder_id AND " . self::scope('f') . ')';
        } else {
            $clauses['where'] .= $wpdb->prepare(" AND {$wpdb->posts}.ID IN (SELECT attachment_id FROM {$t->r} WHERE folder_id = %d)", (int) $f);
        }
        return $clauses;
    }

    /* ---------- Attachments ---------- */

    /** New upload: put it in the folder the uploader sent, and record its size (for the File size column). */
    public static function on_upload($post_id)
    {
        $file = get_attached_file($post_id);
        if ($file && file_exists($file)) {
            update_post_meta($post_id, 'fb_filesize', (int) filesize($file));
        }
        if (!isset($_REQUEST['smcp_folder']) || !current_user_can('upload_files')) {
            return;
        }
        $f = (int) $_REQUEST['smcp_folder'];
        if ($f > 0 && self::exists($f)) {
            self::assign($f, [$post_id], false);
        }
    }

    public static function on_delete($post_id)
    {
        global $wpdb;
        $wpdb->delete(self::t()->r, ['attachment_id' => (int) $post_id], ['%d']);
    }

    /** An image saved as a copy in the image editor goes into the original's folder. */
    public static function on_edited_image($meta, $new_id, $old_id)
    {
        $f = self::folder_of($old_id);
        if ($f > 0) {
            self::assign($f, [$new_id], false);
        }
        return $meta;
    }

    public static function prepare_js($response, $attachment)
    {
        $response['smcpFolder'] = self::folder_of($attachment->ID);
        return $response;
    }

    private static function options($selected, $first = 'Uncategorized')
    {
        $html = '<option value="0"' . selected($selected, 0, false) . '>' . esc_html($first) . '</option>';
        foreach (self::flat() as $f) {
            $html .= sprintf('<option value="%d"%s>%s%s</option>', $f['id'], selected($selected, $f['id'], false), str_repeat('&nbsp;&nbsp;&nbsp;', $f['depth']), esc_html($f['name']));
        }
        return $html;
    }

    /** "Folder" field in the attachment details (grid pop-up, media pickers and the Edit Media screen). */
    public static function fields_edit($fields, $post)
    {
        if (!current_user_can('upload_files')) {
            return $fields;
        }
        $fields['smcp_folder'] = [
            'label' => 'Folder',
            'input' => 'html',
            'html'  => sprintf('<select name="attachments[%1$d][smcp_folder]" id="attachments-%1$d-smcp_folder" class="smcpmf-field"%3$s>%2$s</select>', $post->ID, self::options(self::folder_of($post->ID)), current_user_can('edit_post', $post->ID) ? '' : ' disabled'),
        ];
        return $fields;
    }

    public static function fields_save($post, $attachment)
    {
        if (isset($attachment['smcp_folder']) && current_user_can('edit_post', $post['ID']) && (int) $attachment['smcp_folder'] !== self::folder_of($post['ID'])) {
            self::assign((int) $attachment['smcp_folder'], [$post['ID']]);
        }
        return $post;
    }

    /* ---------- List view: filter, columns ---------- */

    public static function columns($cols)
    {
        $cols['smcpmf_folder'] = 'Folder';
        $cols['smcpmf_size'] = 'File size';
        return $cols;
    }

    public static function sortable_columns($cols)
    {
        $cols['smcpmf_size'] = 'smcpmf_size';
        return $cols;
    }

    public static function column($col, $id)
    {
        if ($col === 'smcpmf_folder') {
            $f = self::folder_of($id);
            $rows = self::rows();
            if ($f && isset($rows[$f])) {
                printf('<a href="%s" data-smcpmf-folder="%d">%s</a>', esc_url(add_query_arg(['mode' => 'list', 'smcp_folder' => $f], admin_url('upload.php'))), $f, esc_html($rows[$f]['name']));
            } else {
                printf('<a href="%s" data-smcpmf-folder="0">Uncategorized</a>', esc_url(add_query_arg(['mode' => 'list', 'smcp_folder' => 0], admin_url('upload.php'))));
            }
        } elseif ($col === 'smcpmf_size') {
            $size = (int) get_post_meta($id, 'fb_filesize', true);
            if (!$size) {
                $file = get_attached_file($id);
                if ($file && file_exists($file)) {
                    $size = (int) filesize($file);
                    update_post_meta($id, 'fb_filesize', $size);
                }
            }
            echo $size ? esc_html(size_format($size, 1)) : '&mdash;';
        }
    }

    public static function list_filter()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'upload') {
            return;
        }
        $cur = self::current_folder();
        echo '<label for="smcpmf-list-filter" class="screen-reader-text">Filter by folder</label><select name="smcp_folder" id="smcpmf-list-filter">';
        echo '<option value="-1"' . selected($cur, -1, false) . '>All folders</option>' . self::options($cur);
        echo '</select>';
    }

    /** Folder picker above the uploader (Media > Add New, and the upload panel of the media pickers). */
    public static function upload_ui()
    {
        if (!current_user_can('upload_files')) {
            return;
        }
        echo '<p class="smcpmf-upload"><label>Upload into folder: <select class="smcpmf-upload-folder">' . self::options(0) . '</select></label></p>';
    }

    /* ---------- Scripts ---------- */

    public static function enqueue($hook)
    {
        if ($hook === 'upload.php' || $hook === 'media-new.php') {
            self::assets();
        }
    }

    public static function assets()
    {
        static $done = false;
        if ($done || !is_user_logged_in() || !current_user_can('upload_files')) {
            return;
        }
        $done = true;
        $url = plugins_url('assets/', SITE_MCP_FILE);
        $dir = dirname(SITE_MCP_FILE) . '/assets/';
        $deps = ['jquery'];
        foreach (['media-views', 'wp-plupload', 'plupload-handlers'] as $h) {
            if (wp_script_is($h, 'enqueued')) {
                $deps[] = $h;
            }
        }
        if (wp_script_is('wp-a11y', 'registered')) {
            $deps[] = 'wp-a11y';
        }
        wp_enqueue_style('smcpmf', $url . 'media-folders.css', [], filemtime($dir . 'media-folders.css'));
        wp_enqueue_script('smcpmf', $url . 'media-folders.js', $deps, filemtime($dir . 'media-folders.js'), true);
        $screen = function_exists('get_current_screen') && get_current_screen() ? get_current_screen()->id : '';
        wp_localize_script('smcpmf', 'smcpmfData', [
            'rest'    => esc_url_raw(rest_url(self::NS . '/media-folders/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'ajax'    => admin_url('admin-ajax.php'),
            'zip'     => class_exists('ZipArchive') ? wp_create_nonce('smcpmf_download') : '',
            'screen'  => $screen,
            'current' => $screen === 'upload' ? self::current_folder() : -1,
            'tree'    => self::tree(),
        ]);
    }

    /* ---------- REST API (signed-in users who can upload files, as with FileBird) ---------- */

    public static function routes()
    {
        $can = function () {
            return current_user_can('upload_files');
        };
        register_rest_route(self::NS, '/media-folders/tree', ['methods' => 'GET', 'permission_callback' => $can, 'callback' => function () {
            return rest_ensure_response(self::tree());
        }]);
        register_rest_route(self::NS, '/media-folders/folders', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => function (WP_REST_Request $r) {
            $id = self::create($r->get_param('name'), (int) $r->get_param('parent'));
            return is_wp_error($id) ? $id : rest_ensure_response(['id' => $id, 'tree' => self::tree()]);
        }]);
        register_rest_route(self::NS, '/media-folders/folders/(?P<id>\d+)', [
            ['methods' => 'POST', 'permission_callback' => $can, 'callback' => function (WP_REST_Request $r) {
                $id = (int) $r['id'];
                if (!self::exists($id)) {
                    return new WP_Error('smcpmf_missing', 'That folder no longer exists.', ['status' => 404]);
                }
                if ($r->has_param('name')) {
                    $res = self::rename($id, $r->get_param('name'));
                    if (is_wp_error($res)) {
                        return $res;
                    }
                }
                if ($r->has_param('parent')) {
                    $res = self::move($id, (int) $r->get_param('parent'), (int) $r->get_param('index'));
                    if (is_wp_error($res)) {
                        return $res;
                    }
                }
                return rest_ensure_response(['tree' => self::tree()]);
            }],
            ['methods' => 'DELETE', 'permission_callback' => $can, 'callback' => function (WP_REST_Request $r) {
                $id = (int) $r['id'];
                if (!self::exists($id)) {
                    return new WP_Error('smcpmf_missing', 'That folder no longer exists.', ['status' => 404]);
                }
                return rest_ensure_response(['deleted' => self::delete($id), 'tree' => self::tree()]);
            }],
        ]);
        register_rest_route(self::NS, '/media-folders/assign', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => function (WP_REST_Request $r) {
            $n = self::assign((int) $r->get_param('folder'), (array) $r->get_param('ids'));
            return is_wp_error($n) ? $n : rest_ensure_response(['moved' => $n, 'tree' => self::tree()]);
        }]);
        register_rest_route(self::NS, '/media-folders/state', ['methods' => 'POST', 'permission_callback' => $can, 'callback' => function (WP_REST_Request $r) {
            $f = (int) $r->get_param('folder');
            update_user_meta(get_current_user_id(), 'smcpmf_folder', ($f > 0 && !self::exists($f)) ? -1 : max(-1, $f));
            return rest_ensure_response(['ok' => true]);
        }]);
    }

    /* ---------- Download a folder (and its subfolders) as a zip of the original files ---------- */

    public static function download()
    {
        check_admin_referer('smcpmf_download');
        if (!current_user_can('upload_files')) {
            wp_die('Sorry, you are not allowed to do that.', 403);
        }
        if (!class_exists('ZipArchive')) {
            wp_die('Zip downloads are not available on this server.');
        }
        $id = isset($_GET['folder']) ? (int) $_GET['folder'] : 0;
        if (!self::exists($id)) {
            wp_die('That folder no longer exists.');
        }
        @set_time_limit(300);
        $rows = self::rows();
        $map = self::map();
        $path = function ($fid) use ($rows, $id) {
            $parts = [];
            for ($f = $fid; $f && $f !== $id && isset($rows[$f]); $f = $rows[$f]['parent']) {
                array_unshift($parts, sanitize_file_name($rows[$f]['name']));
            }
            return $parts ? implode('/', $parts) . '/' : '';
        };
        $folders = array_merge([$id], self::descendants($id));
        $tmp = wp_tempnam('smcpmf-folder.zip');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            wp_die('Could not create the zip file.');
        }
        $used = [];
        foreach ($map as $att => $fid) {
            if (!in_array($fid, $folders, true) || !current_user_can('read_post', $att)) {
                continue;
            }
            $file = get_attached_file($att);
            if (!$file || !file_exists($file)) {
                continue;
            }
            $name = $path($fid) . wp_basename($file);
            for ($i = 1; isset($used[$name]); $i++) {
                $name = $path($fid) . pathinfo($file, PATHINFO_FILENAME) . "-$i." . pathinfo($file, PATHINFO_EXTENSION);
            }
            $used[$name] = true;
            $zip->addFile($file, $name);
        }
        if (!$used) {
            $zip->addFromString('empty.txt', 'This folder has no files.');
        }
        $zip->close();
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($rows[$id]['name']) . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
