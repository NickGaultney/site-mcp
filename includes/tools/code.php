<?php
defined('ABSPATH') || exit;

/**
 * File, history and undo tools for each code folder: theme_* for the active
 * theme and feature_* for the Site Features plugin. See Site_MCP_Code.
 */
function site_mcp_code_tools(Site_MCP_Code $code)
{
    if ($code->unavailable()) {
        return;
    }
    $p = $code->prefix;
    $theme = $code->key === 'theme';
    $what = $theme ? "the active theme's files (" . wp_get_theme()->get_stylesheet() . ')' : 'the Site Features plugin\'s files';
    $example = $theme ? '"functions.php" or "assets/css/theme.css"' : '"features/crm/feature.php"';
    $message = ['type' => 'string', 'description' => 'One line for the version history saying what changed and why, e.g. "' . ($theme ? 'Make the header sticky on phones' : 'Add a follow-up email two days after an order') . '".'];
    $add = function ($name, array $def) use ($code, $theme) {
        if ($theme) {
            $def['properties'] = ($def['properties'] ?? []) + ['theme' => ['type' => 'string', 'description' => 'Which theme, by folder name. Defaults to the active theme; name another one (such as a new theme being built) to work on it without visitors seeing anything.']];
        }
        Site_MCP_Tools::add($name, $def + ['group' => $code->key, 'cap' => $code->cap]);
    };
    // Theme tools work on the active theme unless the call names another one.
    $pick = function ($args) use ($code) {
        if ($code->key !== 'theme' || empty($args['theme'])) {
            return $code;
        }
        $picked = Site_MCP_Code::theme($args['theme']);
        if (!$picked->exists()) {
            throw new Site_MCP_Error("There is no theme named {$args['theme']}. theme_create makes a new one.");
        }
        return $picked;
    };

    $add("{$p}_files_list", [
        'readonly'    => true,
        'description' => "List $what with sizes and modified times.",
        'properties'  => ['dir' => ['type' => 'string', 'description' => 'Optional subfolder, e.g. "' . ($theme ? 'patterns' : 'features/crm') . '".']],
        'run'         => function ($args) use ($code, $pick) {
            $code = $pick($args);
            if (!$code->exists()) {
                return [];
            }
            $root = $code->root();
            $start = !empty($args['dir']) ? $code->path($args['dir']) : $root;
            if (!is_dir($start)) {
                throw new Site_MCP_Error('Folder not found.');
            }
            $files = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $files[] = ['path' => substr($file->getPathname(), strlen($root) + 1), 'bytes' => $file->getSize(), 'modified' => wp_date('Y-m-d H:i', $file->getMTime())];
                }
            }
            usort($files, function ($a, $b) {
                return strcmp($a['path'], $b['path']);
            });
            return $files;
        },
    ]);

    $add("{$p}_file_read", [
        'readonly'    => true,
        'description' => 'Read a ' . ($theme ? 'theme' : 'Site Features') . ' file (text files only), as it is now or as it was in an earlier version.',
        'properties'  => [
            'path'    => ['type' => 'string', 'description' => "e.g. $example."],
            'version' => ['type' => 'string', 'description' => "Optional version id from {$p}_history, to read the file as it was then."],
        ],
        'required'    => ['path'],
        'run'         => function ($args) use ($code, $p, $pick) {
            $code = $pick($args);
            if (!empty($args['version'])) {
                $relative = $code->relative($args['path']);
                $hash = $code->resolve($args['version']);
                [$status, $out] = $code->git(['show', "$hash:$relative"], true);
                if ($status !== 0) {
                    throw new Site_MCP_Error("$relative didn't exist in that version.");
                }
                return ['path' => $relative, 'version' => substr($hash, 0, 7)] + (Site_MCP_Code::is_text($relative) ? ['content' => $out] : ['bytes' => strlen($out), 'binary' => true]);
            }
            $full = $code->path($args['path'], true);
            if (!Site_MCP_Code::is_text($full)) {
                return ['path' => $args['path'], 'bytes' => filesize($full), 'binary' => true];
            }
            return ['path' => $args['path'], 'content' => file_get_contents($full)];
        },
    ]);

    $add("{$p}_file_write", [
        'description' => ($theme
            ? 'Create or change theme files (php, html, css, js, json, svg, txt, md).'
            : 'Create or change Site Features files (php, html, css, js, json, svg, txt, md). The plugin is created and turned on the first time this is called. Each feature is a folder, features/<slug>/, and its feature.php is loaded on every request; put its other files in the same folder.')
            . ' Change one file with path + content or edits, or several with files: they are checked and saved together as one version, so related changes (a new PHP include and the line that loads it) go live together. PHP and JSON are syntax-checked; then the home page, the newest post, page and product, the admin dashboard and the REST API are loaded. If anything fails, the change is rolled back automatically and the errors are returned. Each successful save is a new version in ' . $p . '_history.',
        'properties'  => [
            'path'        => ['type' => 'string'],
            'content'     => ['type' => 'string', 'description' => 'Full new file content.'],
            'edits'       => Site_MCP_Tools::edits_schema(),
            'files'       => [
                'type'        => 'array',
                'description' => 'Several files changed together: each {path, content} or {path, edits}.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'edits' => Site_MCP_Tools::edits_schema(), 'source_url' => ['type' => 'string']],
                    'required'   => ['path'],
                ],
            ],
            'source_url'  => ['type' => 'string', 'description' => 'Instead of content: copy a file from a public https URL, such as a font (woff2) from Google Fonts or a logo. The only way to add ' . implode(', ', Site_MCP_Code::BINARY_EXTENSIONS) . ' files; up to 5 MB.'],
            'message'     => $message,
            'check_paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Extra pages to load after saving, e.g. ' . ($theme ? '["/shop/"]' : '["/wp-admin/admin.php?page=crm", "/wp-json/crm/v1/contacts", "/events/"]') . '. Admin pages are loaded signed in as you.'],
        ],
        'run'         => function ($args) use ($code, $theme, $pick) {
            $code = $pick($args);
            $changes = !empty($args['files']) ? (array) $args['files'] : [['path' => $args['path'] ?? ''] + array_intersect_key($args, ['content' => 1, 'edits' => 1, 'source_url' => 1])];
            $writes = [];
            foreach ($changes as $change) {
                $relative = $code->relative($change['path'] ?? '');
                $full = $code->path($relative);
                if (!empty($change['source_url'])) {
                    $writes[$relative] = Site_MCP_Code::fetch((string) $change['source_url'], $relative);
                    if (Site_MCP_Code::is_text($relative)) {
                        Site_MCP_Code::validate($relative, $writes[$relative]);
                    }
                    continue;
                }
                if (!Site_MCP_Code::is_text($full)) {
                    throw new Site_MCP_Error('Only text files can be written: ' . implode(', ', Site_MCP_Code::TEXT_EXTENSIONS) . '. Copy fonts and images in with source_url, or put images in the media library.');
                }
                if (in_array($relative, $code->protected, true)) {
                    throw new Site_MCP_Error("$relative is managed by Site MCP and can't be changed. Put features in features/<slug>/feature.php. Nothing was saved.");
                }
                $current = $writes[$relative] ?? (is_file($full) ? file_get_contents($full) : null);
                if (isset($change['content'])) {
                    $content = (string) $change['content'];
                } elseif (!empty($change['edits'])) {
                    if ($current === null) {
                        throw new Site_MCP_Error("$relative doesn't exist; pass content to create it.");
                    }
                    $content = $current;
                } else {
                    throw new Site_MCP_Error("Pass content or edits for $relative.");
                }
                if (!empty($change['edits'])) {
                    try {
                        $content = Site_MCP_Tools::apply_edits($content, $change['edits']);
                    } catch (Site_MCP_Error $e) {
                        throw new Site_MCP_Error("$relative: " . $e->getMessage());
                    }
                }
                Site_MCP_Code::validate($relative, $content);
                $writes[$relative] = $content;
            }
            $summary = trim((string) ($args['message'] ?? '')) ?: 'Update ' . implode(', ', array_keys($writes));
            $result = $code->change($summary, function () use ($code, $writes) {
                foreach ($writes as $relative => $content) {
                    $code->write($code->path($relative), $content);
                }
            }, (array) ($args['check_paths'] ?? []));
            if (!$result) {
                return ['message' => 'No change: the files already have this content.'];
            }
            $out = ['message' => "Saved as version {$result['version']}; the site still loads"] + $result;
            foreach ($writes as $relative => $content) {
                if (!Site_MCP_Code::is_text($relative)) {
                    continue;
                }
                if (!$theme) {
                    if (preg_match('#^features/[^/]+/[^/]+\.php$#', $relative) && !is_file($code->root() . '/' . dirname($relative) . '/feature.php')) {
                        $out['warnings'][] = dirname($relative) . ' has no feature.php, so nothing in it is loaded yet.';
                    } elseif (!str_starts_with($relative, 'features/') && str_ends_with($relative, '.php')) {
                        $out['warnings'][] = "$relative is outside features/, so it is only loaded if a feature requires it.";
                    }
                    continue;
                }
                $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                if (in_array($ext, ['html', 'php'], true) && ($warnings = Site_MCP_Tools::block_warnings($content))) {
                    $out['block_warnings'][$relative] = $warnings;
                }
                if (preg_match('#^(templates|parts)/([^/]+)\.html$#', $relative, $m)) {
                    $override = get_block_template(get_stylesheet() . '//' . $m[2], $m[1] === 'parts' ? 'wp_template_part' : 'wp_template');
                    if ($override && $override->source === 'custom') {
                        $out['warnings'][] = "$relative has a database override, so visitors still see the override. Call template_reset to use the file.";
                    }
                }
            }
            return $out;
        },
    ]);

    $add("{$p}_file_delete", [
        'destructive' => true,
        'description' => 'Delete ' . ($theme ? 'theme' : 'Site Features') . " files, saved as one version (undo with {$p}_revert). Rolled back automatically if the site breaks." . ($theme ? '' : ' Deleting all of a feature\'s files removes the feature; its database tables and options stay.'),
        'properties'  => [
            'path'    => ['type' => 'string'],
            'paths'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Several files to delete together.'],
            'message' => $message,
        ],
        'run'         => function ($args) use ($code, $pick) {
            $code = $pick($args);
            $relatives = array_map([$code, 'relative'], !empty($args['paths']) ? (array) $args['paths'] : [$args['path'] ?? '']);
            foreach ($relatives as $relative) {
                $code->path($relative, true);
            }
            $summary = trim((string) ($args['message'] ?? '')) ?: 'Delete ' . implode(', ', $relatives);
            $result = $code->change($summary, function () use ($code, $relatives) {
                foreach ($relatives as $relative) {
                    unlink($code->path($relative, true));
                    // Leave no empty folders behind, so a removed feature disappears from feature_list.
                    for ($dir = dirname($code->path($relative)); $dir !== $code->root() && @rmdir($dir); $dir = dirname($dir)) {
                    }
                }
            });
            return ['message' => "Deleted as version {$result['version']}; the site still loads"] + $result;
        },
    ]);

    $add("{$p}_history", [
        'readonly'    => true,
        'description' => 'Version history of ' . $code->label . ', newest first: who changed what, when and why. Files changed outside Site MCP show up as their own version.',
        'properties'  => [
            'path'   => ['type' => 'string', 'description' => 'Only versions that changed this file.'],
            'limit'  => ['type' => 'integer', 'default' => 30],
            'offset' => ['type' => 'integer', 'default' => 0],
        ],
        'run'         => function ($args) use ($code, $pick) {
            $code = $pick($args);
            $versions = $code->history(Site_MCP_Tools::int_arg($args, 'limit', 30, 1, 200), Site_MCP_Tools::int_arg($args, 'offset', 0, 0, 100000), (string) ($args['path'] ?? ''));
            return array_map(function ($v) {
                if (count($v['files']) > 25) {
                    $v['files'] = array_slice($v['files'], 0, 25, true) + ['…' => (count($v['files']) - 25) . ' more files'];
                }
                return array_intersect_key($v, array_flip(['version', 'when', 'who', 'message', 'files']));
            }, $versions);
        },
    ]);

    $add("{$p}_diff", [
        'readonly'    => true,
        'description' => 'Show exactly what a version changed, as a unified diff. Or pass from to compare two versions, e.g. everything changed since a given version.',
        'properties'  => [
            'version' => ['type' => 'string', 'description' => "Version id from {$p}_history. Defaults to the latest."],
            'from'    => ['type' => 'string', 'description' => 'Compare from this version to version (or to the latest).'],
            'path'    => ['type' => 'string', 'description' => 'Limit the diff to one file.'],
        ],
        'run'         => function ($args) use ($code, $pick) {
            $code = $pick($args);
            $to = $code->resolve(($args['version'] ?? '') ?: 'HEAD');
            $tail = !empty($args['path']) ? ['--', $code->relative($args['path'])] : [];
            if (!empty($args['from'])) {
                $from = $code->resolve($args['from']);
                $diff = $code->git(array_merge(['diff', '--no-color', '--stat', '--patch', $from, $to], $tail));
                $out = ['from' => substr($from, 0, 7), 'to' => substr($to, 0, 7)];
            } else {
                $diff = $code->git(array_merge(['show', '--no-color', '--stat', '--patch', '--format=Version %h by %an, %ad%n%n    %s%n', '--date=format-local:%Y-%m-%d %H:%M', $to], $tail));
                $out = ['version' => substr($to, 0, 7)];
            }
            if (strlen($diff) > Site_MCP_Code::MAX_DIFF) {
                $out['truncated'] = 'The diff is ' . strlen($diff) . ' bytes; showing the first ' . Site_MCP_Code::MAX_DIFF . '. Pass path to see one file.';
                $diff = substr($diff, 0, Site_MCP_Code::MAX_DIFF);
            }
            return $out + ['diff' => $diff];
        },
    ]);

    $add("{$p}_revert", [
        'destructive' => true,
        'description' => 'Undo one version: reverse exactly what it changed, keeping every change made since. Saved as a new version, so it can be undone too. Refuses if later versions changed the same lines.',
        'properties'  => ['version' => ['type' => 'string', 'description' => "Version id from {$p}_history."]],
        'required'    => ['version'],
        'run'         => function ($args) use ($code, $pick) {
            $code = $pick($args);
            $result = $code->revert($args['version']);
            return ['message' => "Undid {$result['undid']} as version {$result['version']}; the site still loads"] + $result;
        },
    ]);

    $add("{$p}_restore", [
        'destructive' => true,
        'description' => "Put files (or all of {$code->label}) back exactly as they were at an earlier version, discarding later changes to them. Saved as a new version, so it can be undone too. To reverse just one change and keep later ones, use {$p}_revert instead.",
        'properties'  => [
            'version' => ['type' => 'string', 'description' => "Version id from {$p}_history."],
            'paths'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "Files to restore. Omit to restore all of {$code->label}."],
        ],
        'required'    => ['version'],
        'run'         => function ($args) use ($code, $pick) {
            $code = $pick($args);
            $hash = $code->resolve($args['version']);
            $short = substr($hash, 0, 7);
            $paths = array_map([$code, 'relative'], (array) ($args['paths'] ?? []));
            $what = $paths ? implode(', ', $paths) : "all of {$code->label}";
            $result = $code->change("Restore $what to version $short", function () use ($code, $hash, $paths) {
                foreach ($paths ?: ['.'] as $path) {
                    if ($path === '.' || $code->git(['cat-file', '-e', "$hash:$path"], true)[0] === 0) {
                        $code->git(['restore', "--source=$hash", '--staged', '--worktree', '--', $path]);
                    } elseif (is_file($code->path($path))) {
                        unlink($code->path($path)); // It didn't exist in that version.
                    }
                }
            });
            if (!$result) {
                return ['message' => "No change: $what already matches version $short."];
            }
            return ['message' => "Restored $what to version $short, saved as version {$result['version']}; the site still loads"] + $result;
        },
    ]);

    if ($code instanceof Site_MCP_Features) {
        $add('feature_list', [
            'readonly'    => true,
            'description' => 'List the features in the Site Features plugin, from each feature.php header (Feature: and Description: lines), and whether the plugin is turned on.',
            'run'         => function () use ($code) {
                if (!$code->exists()) {
                    return ['features' => [], 'message' => 'No features yet. feature_file_write creates the Site Features plugin with the first one.'];
                }
                return ['active' => $code->active(), 'features' => $code->catalog()] + ($code->active() ? [] : ['warning' => 'The Site Features plugin is turned off, so none of these features are running. Someone can turn it on under Plugins or Settings → Site MCP.']);
            },
        ]);
    }
}

add_action('site_mcp_register_tools', function () {
    foreach (Site_MCP_Code::all() as $code) {
        site_mcp_code_tools($code);
    }
});
