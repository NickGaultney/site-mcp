<?php
defined('ABSPATH') || exit;

/**
 * A folder of site code with built-in version history: the active theme, or
 * the Site Features plugin. The folder is the work tree of a Git repository
 * kept outside the web root. Every change is syntax-checked, then the site is
 * loaded (front end, admin and REST API); if a page breaks, the folder goes
 * back to the last version and nothing is recorded. Otherwise the change
 * becomes a commit by the WordPress user who made it.
 */
class Site_MCP_Code
{
    const TEXT_EXTENSIONS = ['php', 'html', 'css', 'js', 'json', 'txt', 'md', 'svg'];
    /** Files that can only be copied in from a URL (source_url), never written as text. */
    const BINARY_EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'ico'];
    const MAX_BYTES = MB_IN_BYTES;
    const MAX_BINARY_BYTES = 5 * MB_IN_BYTES;
    const MAX_DIFF = 60000;

    /** theme or features: the tool group, and the prefix of its tools (theme_*, feature_*). */
    public $key;
    public $prefix;
    /** How messages name the folder: "the theme", "Site Features". */
    public $label;
    public $cap;
    /** Files that can't be deleted; protected ones can't be written either. */
    public $required = [];
    public $protected = [];
    /** For themes: the theme's folder name, and for an inactive one the token that previews it. */
    public $stylesheet;
    public $preview;
    public $first_message;
    private $notes = [];

    private static $instances = [];

    /**
     * A theme's code: the active theme by default, or any installed (or about
     * to be created) theme by folder name. Changes to an inactive theme are
     * checked through a preview of it, so visitors never see them.
     */
    public static function theme($stylesheet = null)
    {
        $stylesheet = (string) ($stylesheet ?: get_stylesheet());
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $stylesheet)) {
            throw new Site_MCP_Error('Theme names are lowercase letters, numbers, hyphens and underscores, like "acme-2026".');
        }
        if (!isset(self::$instances["theme:$stylesheet"])) {
            $code = new self();
            $code->key = 'theme';
            $code->prefix = 'theme';
            $code->cap = 'edit_themes';
            $code->stylesheet = $stylesheet;
            if ($stylesheet === get_stylesheet()) {
                $code->label = 'the theme';
            } else {
                $code->label = "the $stylesheet theme";
                $code->preview = Site_MCP_Preview::check_token($stylesheet);
            }
            $code->first_message = 'Theme as it was when version history started';
            self::$instances["theme:$stylesheet"] = $code;
        }
        return self::$instances["theme:$stylesheet"];
    }

    public function active()
    {
        return $this->stylesheet === get_stylesheet();
    }

    public static function features()
    {
        if (!isset(self::$instances['features'])) {
            $code = new Site_MCP_Features();
            $code->key = 'features';
            $code->prefix = 'feature';
            $code->label = 'Site Features';
            $code->cap = 'edit_plugins';
            $code->required = [Site_MCP_Features::LOADER];
            $code->protected = [Site_MCP_Features::LOADER];
            $code->first_message = 'Site Features as it was when version history started';
            self::$instances['features'] = $code;
        }
        return self::$instances['features'];
    }

    public static function all()
    {
        return [self::theme(), self::features()];
    }

    public static function maybe_upgrade()
    {
        // Tool groups added since the site's groups were saved start switched on (1.4 added features).
        $before = ['site', 'content', 'media', 'design', 'store', 'forms', 'abilities', 'theme'];
        $added = array_diff(array_keys(Site_MCP_Tools::GROUPS), get_option('site_mcp_known_groups', $before));
        if ($added) {
            $groups = get_option('site_mcp_groups');
            if (is_array($groups)) {
                update_option('site_mcp_groups', array_values(array_unique(array_merge($groups, $added))));
            }
            update_option('site_mcp_known_groups', array_keys(Site_MCP_Tools::GROUPS));
        }

        // 1.2 replaced the GitHub deploy and the "Theme code" mode with built-in history.
        if (get_option('site_mcp_theme_mode') === false && get_option('site_mcp_deploy_dir') === false) {
            return;
        }
        $groups = get_option('site_mcp_groups');
        if (is_array($groups) && !in_array('theme', $groups, true)) {
            $groups[] = 'theme';
            update_option('site_mcp_groups', $groups);
        }
        delete_option('site_mcp_theme_mode');
        delete_option('site_mcp_theme_direct_since');
        delete_option('site_mcp_deploy_dir');
    }

    public function root()
    {
        return untrailingslashit(get_theme_root($this->stylesheet)) . '/' . $this->stylesheet;
    }

    /** The history repository's name inside the history folder. */
    protected function repo_name()
    {
        return $this->stylesheet;
    }

    /** Files a change may not delete. A theme can't lose the files WordPress needs to use it. */
    protected function required_files()
    {
        if ($this->key !== 'theme') {
            return $this->required;
        }
        return array_values(array_filter(['style.css', 'theme.json', 'functions.php', 'templates/index.html', 'index.php'], function ($file) {
            return is_file($this->root() . '/' . $file);
        }));
    }

    /** Whether the folder exists yet. Site Features is created on its first change. */
    public function exists()
    {
        return is_dir($this->root());
    }

    /** Whether the folder can be written: the folder itself, or where it will be created. */
    protected function writable()
    {
        return wp_is_writable($this->exists() ? $this->root() : dirname($this->root()));
    }

    /**
     * Why editing this folder can't work on this server, or null if it can. It
     * needs file editing allowed, a writable folder, and git run through proc_open.
     */
    public function unavailable()
    {
        if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
            return 'File editing is turned off in wp-config.php (DISALLOW_FILE_EDIT).';
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return "This server doesn't let PHP run programs (proc_open is disabled), which version history needs.";
        }
        if (!$this->writable()) {
            return $this->key === 'theme' ? "The theme folder isn't writable by WordPress." : "The plugins folder isn't writable by WordPress.";
        }
        $cached = get_transient('site_mcp_git_check');
        if ($cached === false) {
            $proc = @proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
            $ok = false;
            if (is_resource($proc)) {
                $ok = str_starts_with(stream_get_contents($pipes[1]), 'git version');
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
            $cached = $ok ? 'ok' : 'missing';
            set_transient('site_mcp_git_check', $cached, $ok ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
        }
        return $cached === 'ok' ? null : 'Git is not installed on this server, and version history needs it.';
    }

    /**
     * The history repository. By default it sits beside the web root so it is
     * never served; where that isn't writable it falls back to a folder in
     * wp-content with an unguessable name and deny rules. SITE_MCP_HISTORY_DIR
     * in wp-config.php overrides both. (The folder is called theme-history
     * because it held only the theme's history before Site Features.)
     */
    public function git_dir()
    {
        if (defined('SITE_MCP_HISTORY_DIR')) {
            $base = untrailingslashit(SITE_MCP_HISTORY_DIR);
        } else {
            $base = dirname(untrailingslashit(ABSPATH)) . '/theme-history';
            if (!@is_dir($base) && !@wp_is_writable(dirname($base))) {
                $base = WP_CONTENT_DIR . '/site-mcp-history-' . substr(wp_hash('site-mcp-history'), 0, 12);
                if (!is_dir($base) && wp_mkdir_p($base)) {
                    file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
                    file_put_contents($base . '/index.php', "<?php\n// Silence is golden.\n");
                }
            }
        }
        return $base . '/' . $this->repo_name() . '.git';
    }

    /** Resolves a folder-relative path, refusing anything outside the folder or through a symlink. */
    public function path($relative, $must_exist = false)
    {
        $relative = $this->relative($relative);
        $root = $this->root();
        $full = $root . '/' . $relative;
        for ($check = $full; $check !== $root; $check = dirname($check)) {
            if (is_link($check)) {
                throw new Site_MCP_Error('Symlinks are not allowed.');
            }
        }
        if ($must_exist && !is_file($full)) {
            throw new Site_MCP_Error("File not found: $relative");
        }
        return $full;
    }

    /** Normalizes a folder-relative path, refusing traversal. */
    public function relative($relative)
    {
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        if ($relative === '' || preg_match('#(^|/)\.\.?(/|$)#', $relative) || str_contains($relative, "\0") || str_starts_with($relative, '.git')) {
            throw new Site_MCP_Error('Invalid path. Use a path inside ' . $this->label . ', like "' . ($this->key === 'theme' ? 'assets/css/theme.css' : 'features/crm/feature.php') . '".');
        }
        return $relative;
    }

    public static function is_text($path)
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true);
    }

    /** Throws if the content would not parse. */
    public static function validate($relative, $content)
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new Site_MCP_Error("$relative is larger than 1 MB.");
        }
        switch (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
            case 'php':
                try {
                    token_get_all($content, TOKEN_PARSE);
                } catch (ParseError $e) {
                    throw new Site_MCP_Error("PHP syntax error in $relative on line {$e->getLine()}: {$e->getMessage()}. Nothing was saved.");
                }
                break;
            case 'json':
                json_decode($content);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Site_MCP_Error("Invalid JSON in $relative: " . json_last_error_msg() . '. Nothing was saved.');
                }
                break;
        }
    }

    /** Downloads a file (a font, an image) to copy into the folder, refusing private addresses. */
    public static function fetch($url, $relative)
    {
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($ext, array_merge(self::TEXT_EXTENSIONS, self::BINARY_EXTENSIONS), true) || $ext === 'php') {
            throw new Site_MCP_Error("$relative: only " . implode(', ', array_merge(array_diff(self::TEXT_EXTENSIONS, ['php']), self::BINARY_EXTENSIONS)) . ' files can be copied from a URL.');
        }
        if (!wp_http_validate_url($url) || !str_starts_with($url, 'https://')) {
            throw new Site_MCP_Error("$relative: source_url must be a public https URL.");
        }
        $response = wp_safe_remote_get($url, ['timeout' => 30, 'limit_response_size' => self::MAX_BINARY_BYTES + 1]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new Site_MCP_Error("$relative: couldn't download $url (" . (is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response)) . '). Nothing was saved.');
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_BINARY_BYTES) {
            throw new Site_MCP_Error("$relative is larger than 5 MB. Put large images in the media library instead.");
        }
        return $body;
    }

    /* ---------- Git ---------- */

    /**
     * Runs git on the folder's history, as the current WordPress user (or as
     * the site, with $as_site). Returns stdout, or [exit code, stdout, stderr]
     * when $allow_fail is set.
     */
    public function git(array $args, $allow_fail = false, $as_site = false)
    {
        $user = $as_site ? new WP_User() : wp_get_current_user();
        $name = $user->exists() ? $user->display_name : get_bloginfo('name');
        $email = $user->exists() ? $user->user_email : 'wordpress@' . wp_parse_url(home_url(), PHP_URL_HOST);
        $env = [
            'TZ'                  => str_contains(wp_timezone_string(), '/') ? wp_timezone_string() : 'UTC',
            'GIT_DIR'             => $this->git_dir(),
            'GIT_WORK_TREE'       => $this->root(),
            'HOME'                => dirname($this->git_dir()),
            'PATH'                => '/usr/local/bin:/usr/bin:/bin',
            'LC_ALL'              => 'C',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL'   => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME'     => $name,
            'GIT_AUTHOR_EMAIL'    => $email,
            'GIT_COMMITTER_NAME'  => $name,
            'GIT_COMMITTER_EMAIL' => $email,
        ];
        $cmd = array_merge(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.quotePath=false', '-c', 'core.fileMode=false'], $args);
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root(), $env);
        if (!is_resource($proc)) {
            throw new Site_MCP_Error('Version history is unavailable: git could not be started on this server.');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($allow_fail) {
            return [$code, $out, $err];
        }
        if ($code !== 0) {
            throw new Site_MCP_Error("git {$args[0]} failed: " . trim($err ?: $out));
        }
        return $out;
    }

    /** Runs $fn while holding the history lock, so changes never interleave. */
    private function locked(callable $fn)
    {
        wp_mkdir_p(dirname($this->git_dir()));
        $handle = fopen($this->git_dir() . '.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->ensure();
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Gets the folder ready for a change. Site Features creates itself here. */
    protected function prepare()
    {
    }

    /**
     * Creates the history on first use, and records any files changed outside
     * Site MCP (by SFTP or a plugin) as their own version, so every change
     * stays undoable and later undos don't sweep them up.
     */
    private function ensure()
    {
        if (!is_dir($this->git_dir())) {
            $this->git(['init', '-q', '-b', 'main']);
            file_put_contents($this->git_dir() . '/info/exclude', "*.site-mcp-tmp\n");
        }
        $this->git(['add', '-A']);
        if (trim($this->git(['status', '--porcelain'])) === '') {
            return;
        }
        $first = $this->git(['rev-parse', '--verify', '-q', 'HEAD'], true)[0] !== 0;
        $this->git(['commit', '-q', '-m', $first ? $this->first_message : 'Changes made outside Site MCP'], false, true);
    }

    /** Resolves a version id (hash, or HEAD~n) to a full hash. */
    public function resolve($ref)
    {
        $ref = trim((string) $ref);
        if (!preg_match('/^([0-9a-f]{4,40}|HEAD(~\d{1,4})?)$/', $ref)) {
            throw new Site_MCP_Error("Pass a version id from {$this->prefix}_history, like \"3f9c2ab\".");
        }
        $this->require_history();
        return $this->locked(function () use ($ref) {
            [$code, $out] = $this->git(['rev-parse', '--verify', '-q', $ref . '^{commit}'], true);
            if ($code !== 0) {
                throw new Site_MCP_Error("Version $ref not found. List versions with {$this->prefix}_history.");
            }
            return trim($out);
        });
    }

    private function require_history()
    {
        if (!$this->exists()) {
            throw new Site_MCP_Error("{$this->label} has no versions yet.");
        }
    }

    /** Files staged against the last version: [path => added|modified|deleted]. */
    private function staged()
    {
        $files = [];
        $names = ['A' => 'added', 'D' => 'deleted'];
        foreach (array_filter(explode("\n", $this->git(['diff', '--cached', '--name-status', '--no-renames', 'HEAD']))) as $line) {
            [$status, $path] = explode("\t", $line, 2);
            $files[$path] = $names[$status[0]] ?? 'modified';
        }
        return $files;
    }

    /* ---------- Changes ---------- */

    /**
     * Makes one change to the folder as one version. $apply does the writes;
     * the changed files are then syntax-checked and the site is loaded. If
     * anything fails, the folder goes back to the last version, nothing is
     * recorded, and the error (with any PHP errors) is thrown. Returns null
     * when nothing changed.
     */
    public function change($message, callable $apply, array $extra_paths = [])
    {
        $this->prepare();
        return $this->locked(function () use ($message, $apply, $extra_paths) {
            $started = time();
            $files = [];
            $this->notes = [];
            $required = $this->required_files();
            try {
                $apply();
                $this->git(['add', '-A']);
                $files = $this->staged();
                if (!$files) {
                    return null;
                }
                foreach ($files as $relative => $status) {
                    if ($status === 'deleted' && in_array($relative, $required, true)) {
                        throw new Site_MCP_Error("$relative is required by {$this->label} and can't be deleted. Nothing was saved.");
                    }
                    if ($status !== 'deleted' && in_array($relative, $this->protected, true)) {
                        throw new Site_MCP_Error("$relative is managed by Site MCP and can't be changed. Nothing was saved.");
                    }
                    if ($status !== 'deleted' && self::is_text($relative)) {
                        self::validate($relative, file_get_contents($this->root() . '/' . $relative));
                    }
                }
                $this->refresh(array_keys($files));
                $paths = $this->check_paths($extra_paths);
                $broken = $this->health_check($paths);
            } catch (Throwable $e) {
                $this->rollback(array_keys($files));
                throw $e;
            }
            if ($broken) {
                $errors = self::error_log_tail($started);
                $this->rollback(array_keys($files));
                // Pages that also fail without the change weren't broken by it: they were
                // down already, or the host stops the site from loading its own pages.
                $caused = array_diff_key($broken, $this->health_check(array_keys($broken)));
                if ($caused) {
                    throw new Site_MCP_Error('The change broke ' . self::describe($caused) . ' and was rolled back automatically. ' . ($errors ? 'PHP errors: ' . implode(' | ', $errors) : 'No PHP errors were logged; check the change by hand.'));
                }
                try {
                    $apply();
                    $this->git(['add', '-A']);
                    $this->refresh(array_keys($files));
                } catch (Throwable $e) {
                    $this->rollback(array_keys($files));
                    throw $e;
                }
                $this->notes[] = 'Could not check ' . self::describe($broken) . (count($broken) === 1 ? ': it fails' : ': they fail') . ' to load with or without this change, so the change was kept. If every page fails, the server may be blocking the site from loading its own pages.';
            }
            $this->git(['commit', '-q', '-m', $message]);
            $warnings = array_values(array_unique($this->notes));
            return ['version' => trim($this->git(['rev-parse', '--short', 'HEAD'])), 'files' => $files, 'checked' => $paths] + ($warnings ? ['warnings' => $warnings] : []);
        });
    }

    /** Puts the folder back to the last version. */
    private function rollback(array $relatives)
    {
        $this->git(['add', '-A'], true);
        $this->git(['reset', '-q', '--hard', 'HEAD'], true);
        $this->refresh($relatives);
    }

    private static function describe(array $broken)
    {
        return implode(', ', array_map(function ($path, $code) {
            return "$path ($code)";
        }, array_keys($broken), $broken));
    }

    public function write($full, $content)
    {
        wp_mkdir_p(dirname($full));
        $tmp = $full . '.site-mcp-tmp';
        if (file_put_contents($tmp, $content) === false || !rename($tmp, $full)) {
            @unlink($tmp);
            throw new Site_MCP_Error('Could not write the file (check the folder permissions).');
        }
    }

    /** Makes PHP and WordPress see the changed files immediately. */
    public function refresh(array $relatives)
    {
        foreach ($relatives as $relative) {
            $full = $this->root() . '/' . $relative;
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($full, true);
            }
            clearstatcache(true, $full);
        }
        if ($this->key === 'theme') {
            wp_get_theme($this->stylesheet)->delete_pattern_cache();
            wp_clean_themes_cache();
        }
    }

    /**
     * Pages loaded after every change: home, the newest post, page and
     * product, the admin dashboard and the REST API index (which runs every
     * plugin's route registration).
     */
    public function check_paths(array $extra = [])
    {
        $paths = ['/'];
        foreach (['post', 'page', 'fluent-products', 'product'] as $type) {
            if (post_type_exists($type)) {
                $latest = get_posts(['post_type' => $type, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids']);
                if ($latest) {
                    $paths[] = wp_make_link_relative(get_permalink($latest[0]));
                }
            }
        }
        array_push($paths, '/wp-admin/', '/wp-json/');
        return array_values(array_unique(array_merge($paths, array_map('strval', $extra))));
    }

    private static function is_admin_path($path)
    {
        return (bool) preg_match('#^/?wp-admin(/|$)#', $path);
    }

    private static function url_for($path)
    {
        if (self::is_admin_path($path)) {
            return site_url('/' . ltrim($path, '/'));
        }
        if (preg_match('#^/?wp-json(/(.*))?$#', $path, $m)) {
            return rest_url($m[2] ?? '');
        }
        return home_url($path);
    }

    /**
     * Returns the pages that failed to load, as [path => HTTP status], empty if
     * all load. Admin pages are loaded signed in as the current user, with a
     * session that is ended straight afterwards.
     */
    public function health_check(array $paths)
    {
        $cookies = [];
        $session = null;
        $user_id = get_current_user_id();
        if ($user_id && array_filter($paths, [__CLASS__, 'is_admin_path'])) {
            $session = WP_Session_Tokens::get_instance($user_id);
            $expires = time() + 5 * MINUTE_IN_SECONDS;
            $token = $session->create($expires);
            foreach ([AUTH_COOKIE => 'auth', SECURE_AUTH_COOKIE => 'secure_auth', LOGGED_IN_COOKIE => 'logged_in'] as $name => $scheme) {
                $cookies[$name] = wp_generate_auth_cookie($user_id, $expires, $scheme, $token);
            }
        }
        $broken = [];
        try {
            foreach ($paths as $path) {
                $admin = self::is_admin_path($path);
                $url = add_query_arg('site_mcp_check', wp_rand(), self::url_for($path));
                if ($this->preview) {
                    $url = add_query_arg(Site_MCP_Preview::PARAM, $this->preview, $url);
                }
                $response = wp_remote_get($url, ['timeout' => 25, 'redirection' => $admin ? 0 : 3, 'cookies' => $admin ? $cookies : []]);
                $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
                $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
                if ($code === 0 || $code >= 500) {
                    $broken[$path] = $code;
                } elseif (stripos($body, 'There has been a critical error') !== false) {
                    $broken[$path] = 'critical error';
                } elseif ($admin && $code >= 300 && $code < 400 && str_contains((string) wp_remote_retrieve_header($response, 'location'), 'wp-login.php')) {
                    $this->notes[] = "Could not sign in to check $path, so the admin wasn't checked.";
                }
            }
        } finally {
            if ($session) {
                $session->destroy($token);
            }
        }
        return $broken;
    }

    private static function error_log_tail($since)
    {
        $log = ini_get('error_log');
        if (!$log || !is_readable($log)) {
            return [];
        }
        $lines = array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -30);
        // PHP log lines start with [16-Nov-2026 02:05:46 UTC].
        return array_values(array_unique(array_filter($lines, function ($line) use ($since) {
            return preg_match('/^\[([^\]]+)\]/', $line, $m) && strtotime($m[1]) >= $since - 2 && preg_match('/PHP (Fatal|Parse|Warning)|Uncaught/', $line);
        })));
    }

    /* ---------- History ---------- */

    /** Versions newest first: [{version, hash, message, who, email, time, when, first, files}]. */
    public function history($limit = 30, $offset = 0, $path = '')
    {
        if (!$this->exists()) {
            return [];
        }
        $args = ['log', '--no-renames', '--name-status', '--format=%x1e%H%x1f%h%x1f%an%x1f%ae%x1f%at%x1f%P%x1f%s', '-n', (string) (int) $limit, '--skip=' . (int) $offset];
        if ($path !== '') {
            array_push($args, '--', $this->relative($path));
        }
        $log = $this->locked(function () use ($args) {
            return $this->git($args);
        });
        $out = [];
        foreach (array_filter(explode("\x1e", $log)) as $record) {
            $lines = explode("\n", trim($record));
            [$hash, $short, $name, $email, $time, $parents, $subject] = explode("\x1f", array_shift($lines));
            $files = [];
            foreach (array_filter($lines) as $line) {
                [$status, $file] = explode("\t", $line, 2);
                $files[$file] = ['A' => 'added', 'D' => 'deleted'][$status[0]] ?? 'modified';
            }
            $out[] = ['version' => $short, 'hash' => $hash, 'message' => $subject, 'who' => $name, 'email' => $email, 'time' => (int) $time, 'when' => wp_date('Y-m-d H:i', (int) $time), 'first' => $parents === '', 'files' => $files];
        }
        return $out;
    }

    public function count()
    {
        if (!$this->exists()) {
            return 0;
        }
        return $this->locked(function () {
            return (int) trim($this->git(['rev-list', '--count', 'HEAD']));
        });
    }

    /** Undoes one version's changes as a new version, keeping everything changed since. */
    public function revert($ref)
    {
        $hash = $this->resolve($ref);
        $short = substr($hash, 0, 7);
        [$parents, $subject] = explode("\x1f", trim($this->git(['log', '-1', '--format=%P%x1f%s', $hash])), 2);
        if ($parents === '') {
            throw new Site_MCP_Error("$short is the starting version of the history and can't be undone. Use {$this->prefix}_restore to go back to a later version.");
        }
        $result = $this->change("Undo $short: $subject", function () use ($hash, $short) {
            if ($this->git(['revert', '--no-commit', $hash], true)[0] !== 0) {
                $this->git(['revert', '--abort'], true);
                throw new Site_MCP_Error("$short can't be undone automatically because later versions changed the same lines. Use {$this->prefix}_diff to see what it changed and edit the files by hand, or {$this->prefix}_restore to put files back to an earlier version. Nothing was changed.");
            }
        });
        if (!$result) {
            throw new Site_MCP_Error("$short's changes have already been undone.");
        }
        return $result + ['undid' => "$short: $subject"];
    }
}

/**
 * The Site Features plugin: this site's own features (custom post types, a
 * CRM, automations, integrations), kept apart from the theme so they survive
 * a redesign. Site MCP creates and activates it on its first change. Each
 * feature is a folder in features/, and the loader requires each one's
 * feature.php.
 */
class Site_MCP_Features extends Site_MCP_Code
{
    const SLUG = 'site-features';
    const LOADER = 'site-features.php';
    const BASENAME = 'site-features/site-features.php';

    public function root()
    {
        return WP_PLUGIN_DIR . '/' . self::SLUG;
    }

    protected function repo_name()
    {
        return self::SLUG;
    }

    protected function writable()
    {
        return $this->exists() ? wp_is_writable($this->root()) : wp_is_writable(WP_PLUGIN_DIR);
    }

    public function active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active(self::BASENAME);
    }

    /** Creates the plugin on first use. A plugin someone switched off stays off until they turn it back on. */
    protected function prepare()
    {
        $created = false;
        if (!$this->exists()) {
            if (!wp_mkdir_p($this->root() . '/features')) {
                throw new Site_MCP_Error("Couldn't create the Site Features plugin folder.");
            }
            $created = true;
            $this->first_message = 'Created Site Features';
        }
        if (!is_file($this->root() . '/' . self::LOADER)) {
            $this->write($this->root() . '/' . self::LOADER, self::loader());
        }
        if (!$this->active()) {
            // Before its first version the plugin is still being set up, so turning it on is part of that.
            if (!$created && is_dir($this->git_dir())) {
                throw new Site_MCP_Error('The Site Features plugin is turned off, so its changes can\'t be checked. Turn it on under Plugins (or Settings → Site MCP), then try again. Nothing was saved.');
            }
            wp_clean_plugins_cache(false); // The plugin list may have been cached before the plugin existed.
            $result = activate_plugin(self::BASENAME);
            if (is_wp_error($result)) {
                throw new Site_MCP_Error("Created the Site Features plugin but couldn't turn it on: " . $result->get_error_message());
            }
        }
    }

    public function refresh(array $relatives)
    {
        parent::refresh($relatives);
        // New post types and routes need fresh rewrite rules: the loader flushes them on the next page load.
        update_option('site_features_flush', 1);
    }

    /** Features with their header comment: [{slug, name, description, files}]. */
    public function catalog()
    {
        $out = [];
        foreach (glob($this->root() . '/features/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            $data = is_file("$dir/feature.php") ? get_file_data("$dir/feature.php", ['name' => 'Feature', 'description' => 'Description']) : ['name' => '', 'description' => ''];
            $files = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                $files += $file->isFile() ? 1 : 0;
            }
            $out[] = ['slug' => $slug, 'name' => $data['name'] ?: $slug, 'description' => $data['description'], 'files' => $files] + (is_file("$dir/feature.php") ? [] : ['warning' => 'No feature.php, so this feature is not loaded.']);
        }
        return $out;
    }

    private static function loader()
    {
        return <<<'PHP'
<?php
/**
 * Plugin Name: Site Features
 * Description: This site's own features, built with Claude through Site MCP. Each feature is a folder in features/. Site MCP keeps their version history; manage them from Settings → Site MCP.
 * Version: 1.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Update URI: false
 */

defined('ABSPATH') || exit;

define('SITE_FEATURES_DIR', __DIR__ . '/features/');
define('SITE_FEATURES_URL', plugin_dir_url(__FILE__) . 'features/');

foreach (glob(SITE_FEATURES_DIR . '*/feature.php') ?: [] as $site_features_file) {
    require_once $site_features_file;
}
unset($site_features_file);

// Set by Site MCP after each change, so new post types and routes work straight away.
add_action('init', function () {
    if (get_option('site_features_flush')) {
        delete_option('site_features_flush');
        flush_rewrite_rules(false);
    }
}, 999);

PHP;
    }
}
