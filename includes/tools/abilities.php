<?php
defined('ABSPATH') || exit;

/**
 * Exposes every ability registered with the WordPress Abilities API as a tool
 * (e.g. fluent-cart/list-orders becomes fluent-cart__list-orders). Each ability
 * runs its own permission check.
 */
add_action('site_mcp_register_tools', function () {
    if (!function_exists('wp_get_abilities')) {
        return;
    }
    foreach (wp_get_abilities() as $ability) {
        $name = substr(str_replace('/', '__', $ability->get_name()), 0, 64);
        $schema = $ability->get_input_schema();
        $wrapped = !empty($schema) && ($schema['type'] ?? 'object') !== 'object';
        $meta = $ability->get_meta();
        $annotations = $meta['annotations'] ?? [];

        Site_MCP_Tools::add($name, [
            'group'       => 'abilities',
            'cap'         => 'read',
            'readonly'    => !empty($annotations['readonly']),
            'destructive' => !empty($annotations['destructive']),
            'description' => $ability->get_label() . ': ' . $ability->get_description(),
            'properties'  => $wrapped ? ['input' => $schema] : (array) ($schema['properties'] ?? []),
            'required'    => $wrapped ? ['input'] : (array) ($schema['required'] ?? []),
            'run'         => function ($args) use ($ability, $wrapped, $schema) {
                $input = $wrapped ? $args['input'] : ($args ?: (empty($schema) ? null : []));
                return $ability->execute($input);
            },
        ]);
    }
}, 20);
