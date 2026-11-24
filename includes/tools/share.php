<?php
defined('ABSPATH') || exit;

/**
 * The two directions of shared standards: suggest_standard sends an improvement
 * from this site to Site MCP's maintainer; guide serves the standards that came
 * back as guides in includes/guides/.
 */
class Site_MCP_Guides
{
    /**
     * Every guide: includes/guides/<topic>.md, starting with front matter
     *   ---
     *   title: WooCommerce page styles
     *   plugins: woocommerce            (optional: applies when any of these is active)
     *   ---
     */
    public static function all()
    {
        static $all = null;
        if ($all !== null) {
            return $all;
        }
        $all = [];
        foreach (glob(SITE_MCP_DIR . 'guides/*.md') ?: [] as $file) {
            $topic = basename($file, '.md');
            if ($topic === 'README') {
                continue;
            }
            $text = (string) file_get_contents($file);
            $meta = [];
            if (preg_match('/\A---\n(.*?)\n---\n/s', $text, $m)) {
                foreach (explode("\n", $m[1]) as $line) {
                    if (strpos($line, ':') !== false) {
                        [$k, $v] = array_map('trim', explode(':', $line, 2));
                        $meta[$k] = $v;
                    }
                }
                $text = substr($text, strlen($m[0]));
            }
            $plugins = array_filter(array_map('trim', explode(',', $meta['plugins'] ?? '')));
            $all[$topic] = ['topic' => $topic, 'title' => $meta['title'] ?? $topic, 'plugins' => $plugins, 'text' => ltrim($text)];
        }
        return $all;
    }

    /** Whether a guide fits this site: it names no plugins, or one of them is active. */
    public static function applies(array $guide)
    {
        if (!$guide['plugins']) {
            return true;
        }
        foreach ((array) get_option('active_plugins', []) as $file) {
            if (in_array(dirname($file), $guide['plugins'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function applicable()
    {
        return array_filter(self::all(), [__CLASS__, 'applies']);
    }
}

add_action('site_mcp_register_tools', function () {
    if (Site_MCP_Guides::all()) {
        Site_MCP_Tools::add('guide', [
            'group'       => 'site',
            'readonly'    => true,
            'description' => 'Site MCP\'s standard guides: proven ways to do common jobs on WordPress sites, such as styling a plugin\'s pages or configuring a caching plugin, collected from many sites. Without a topic, lists the guides and which fit this site. Read the matching guide before working in its area and follow it unless the owner wants otherwise.',
            'properties'  => [
                'topic' => ['type' => 'string', 'enum' => array_keys(Site_MCP_Guides::all())],
            ],
            'run'         => function ($args) {
                $all = Site_MCP_Guides::all();
                if (empty($args['topic'])) {
                    return ['guides' => array_values(array_map(function ($g) {
                        return ['topic' => $g['topic'], 'title' => $g['title'], 'fits_this_site' => Site_MCP_Guides::applies($g)] + ($g['plugins'] ? ['for_plugins' => $g['plugins']] : []);
                    }, $all))];
                }
                if (!isset($all[$args['topic']])) {
                    throw new Site_MCP_Error('No guide called ' . $args['topic'] . '. Topics: ' . implode(', ', array_keys($all)));
                }
                $g = $all[$args['topic']];
                return ['topic' => $g['topic'], 'title' => $g['title'], 'fits_this_site' => Site_MCP_Guides::applies($g), 'guide' => $g['text']];
            },
        ]);
    }

    if (!Site_MCP_Share::enabled()) {
        return;
    }
    Site_MCP_Tools::add('suggest_standard', [
        'group'       => 'site',
        'description' => 'Send an improvement you made on this site to Site MCP\'s maintainer, who reviews suggestions from all sites and makes the best part of Site MCP, so every site gets them. Use it once something reusable works and is checked: styling for a plugin\'s own pages (WooCommerce cart, checkout, account), a tuned plugin configuration (LiteSpeed Cache, an SEO, forms or security plugin), a fix for a plugin conflict or bug, or a performance, accessibility or security improvement. Not for things only this site needs. Write it for someone applying it to a different site: what it does, why, when it applies, and how. Attach the files by path rather than pasting them. Never include passwords, API keys, licence keys or customer data. The site\'s name and domain are replaced with placeholders before sending.',
        'properties'  => [
            'title'        => ['type' => 'string', 'description' => 'Short name, e.g. "WooCommerce cart and checkout styles for classic themes".'],
            'topic'        => ['type' => 'string', 'description' => 'What it is about, as a lowercase slug: the plugin\'s folder name (woocommerce, litespeed-cache, fluentform), or theme, performance, accessibility, security, seo, rebuild, other.'],
            'summary'      => ['type' => 'string', 'description' => 'What it does and why it is better than the default: the problem it solved, what changed, measured results if any.'],
            'how_to_apply' => ['type' => 'string', 'description' => 'Steps for another site: when it applies (plugin versions, classic or block theme), which files or settings to add or change, and what to adapt (colours, fonts) to that site.'],
            'tested'       => ['type' => 'string', 'description' => 'How you checked it works: pages loaded, widths, scores before and after, edge cases.'],
            'files'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Files to attach, read from this site: "theme:assets/css/woocommerce.css" (the active theme) or "feature:features/wc-tweaks/feature.php" (Site Features).'],
            'snippets'     => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['name', 'content']], 'description' => 'Code or configuration that is not in a theme or feature file, such as .htaccess rules or wp-config.php constants, each with a name.'],
            'settings'     => ['type' => 'object', 'description' => 'Plugin settings to reproduce, as option names and values, e.g. {"litespeed.conf.cache-browser": true}. Leave out anything secret.'],
        ],
        'required'    => ['title', 'topic', 'summary', 'how_to_apply'],
        'run'         => function ($args) {
            $files = [];
            $total = 0;
            foreach ((array) ($args['files'] ?? []) as $ref) {
                [$where, $relative] = array_pad(explode(':', (string) $ref, 2), 2, '');
                $code = $where === 'theme' ? Site_MCP_Code::theme() : ($where === 'feature' ? Site_MCP_Code::features() : null);
                if (!$code) {
                    throw new Site_MCP_Error("Attach files as theme:<path> or feature:<path>, not \"$ref\".");
                }
                if (!current_user_can($code->cap)) {
                    throw new Site_MCP_Error("Your account can't read {$code->label} files.");
                }
                $full = $code->path($relative, true);
                if (!Site_MCP_Code::is_text($full)) {
                    throw new Site_MCP_Error("$ref is not a text file. Describe it in how_to_apply instead.");
                }
                $content = (string) file_get_contents($full);
                $total += strlen($content);
                $files[] = ['path' => ($where === 'theme' ? 'theme/' : 'site-features/') . $code->relative($relative), 'content' => $content];
            }
            foreach ((array) ($args['snippets'] ?? []) as $snippet) {
                $content = (string) ($snippet['content'] ?? '');
                $total += strlen($content);
                $files[] = ['path' => 'snippet/' . sanitize_file_name((string) ($snippet['name'] ?? 'snippet')), 'content' => $content];
            }
            if ($total > 900 * KB_IN_BYTES) {
                throw new Site_MCP_Error('The files come to ' . size_format($total) . '; the limit is 900 KB. Attach only the files that carry the improvement.');
            }
            $id = Site_MCP_Share::send([
                'title'        => (string) $args['title'],
                'topic'        => strtolower(trim((string) $args['topic'])),
                'summary'      => (string) $args['summary'],
                'how_to_apply' => (string) $args['how_to_apply'],
                'tested'       => (string) ($args['tested'] ?? ''),
                'files'        => $files,
                'settings'     => !empty($args['settings']) ? (array) $args['settings'] : null,
            ]);
            return ['id' => $id, 'message' => 'Sent to Site MCP\'s maintainer for review' . ($files ? ', with ' . count($files) . ' file' . (count($files) === 1 ? '' : 's') : '') . '. Tell the owner what you shared.'];
        },
    ]);
});
