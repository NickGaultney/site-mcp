<?php
defined('ABSPATH') || exit;

use FluentForm\App\Models\Form;
use FluentForm\App\Models\FormMeta;
use FluentForm\App\Models\Submission;
use FluentForm\App\Modules\Acl\Acl;

function site_mcp_forms_can($permission)
{
    return function ($args) use ($permission) {
        return Acl::hasPermission($permission, isset($args['form_id']) ? (int) $args['form_id'] : false);
    };
}

function site_mcp_form($id)
{
    $form = Form::find((int) $id);
    if (!$form) {
        throw new Site_MCP_Error('Form not found.');
    }
    return $form;
}

add_action('site_mcp_register_tools', function () {
    if (!class_exists(Form::class)) {
        return;
    }

    Site_MCP_Tools::add('forms_list', [
        'group'       => 'forms',
        'readonly'    => true,
        'cap'         => site_mcp_forms_can('fluentform_dashboard_access'),
        'description' => 'List Fluent Forms forms with their IDs, status and entry counts. Embed a form in content with the shortcode [fluentform id="ID"].',
        'run'         => function () {
            return Form::orderBy('id')->get()->map(function ($f) {
                return ['id' => $f->id, 'title' => $f->title, 'status' => $f->status, 'entries' => Submission::where('form_id', $f->id)->where('status', '!=', 'trashed')->count(), 'shortcode' => '[fluentform id="' . $f->id . '"]'];
            })->toArray();
        },
    ]);

    Site_MCP_Tools::add('form_get', [
        'group'       => 'forms',
        'readonly'    => true,
        'cap'         => site_mcp_forms_can('fluentform_forms_manager'),
        'description' => 'Get a form\'s field definitions (Fluent Forms JSON) and its confirmation message.',
        'properties'  => ['form_id' => ['type' => 'integer']],
        'required'    => ['form_id'],
        'run'         => function ($args) {
            $form = site_mcp_form($args['form_id']);
            $settings = json_decode((string) FormMeta::where('form_id', $form->id)->where('meta_key', 'formSettings')->value('value'), true);
            return ['id' => $form->id, 'title' => $form->title, 'status' => $form->status, 'confirmation_message' => $settings['confirmation']['messageToShow'] ?? '', 'form_fields' => json_decode($form->form_fields, true)];
        },
    ]);

    Site_MCP_Tools::add('form_update', [
        'group'       => 'forms',
        'cap'         => site_mcp_forms_can('fluentform_forms_manager'),
        'description' => 'Change a form\'s title, status (published/unpublished), confirmation message (HTML), or its whole form_fields JSON (same shape form_get returns: {fields: [...], submitButton: {...}}). Edit field labels and placeholders by changing form_get output and sending it back.',
        'properties'  => [
            'form_id'              => ['type' => 'integer'],
            'title'                => ['type' => 'string'],
            'status'               => ['type' => 'string', 'enum' => ['published', 'unpublished']],
            'confirmation_message' => ['type' => 'string'],
            'form_fields'          => ['type' => 'object'],
        ],
        'required'    => ['form_id'],
        'run'         => function ($args) {
            $form = site_mcp_form($args['form_id']);
            $update = array_intersect_key($args, array_flip(['title', 'status']));
            if (isset($args['form_fields'])) {
                if (!isset($args['form_fields']['fields']) || !is_array($args['form_fields']['fields'])) {
                    throw new Site_MCP_Error('form_fields must contain a fields array.');
                }
                $update['form_fields'] = wp_json_encode($args['form_fields']);
            }
            if ($update) {
                $form->fill($update)->save();
            }
            if (isset($args['confirmation_message'])) {
                $meta = FormMeta::where('form_id', $form->id)->where('meta_key', 'formSettings')->first();
                $settings = $meta ? json_decode($meta->value, true) : Form::getFormsDefaultSettings($form->id);
                $settings['confirmation']['messageToShow'] = wp_kses_post($args['confirmation_message']);
                FormMeta::updateOrCreate(['form_id' => $form->id, 'meta_key' => 'formSettings'], ['value' => wp_json_encode($settings)]);
            }
            return ['message' => 'Form updated', 'id' => $form->id];
        },
    ]);

    Site_MCP_Tools::add('form_entries', [
        'group'       => 'forms',
        'readonly'    => true,
        'cap'         => site_mcp_forms_can('fluentform_entries_viewer'),
        'description' => 'List submissions for a form, newest first, with the submitted values.',
        'properties'  => [
            'form_id'  => ['type' => 'integer'],
            'search'   => ['type' => 'string'],
            'status'   => ['type' => 'string', 'enum' => ['all', 'unread', 'read', 'trashed'], 'default' => 'all'],
            'per_page' => ['type' => 'integer', 'default' => 25],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required'    => ['form_id'],
        'run'         => function ($args) {
            $per = Site_MCP_Tools::int_arg($args, 'per_page', 25, 1, 200);
            $query = Submission::where('form_id', (int) $args['form_id'])->orderBy('id', 'DESC');
            $status = $args['status'] ?? 'all';
            $status === 'all' ? $query->where('status', '!=', 'trashed') : $query->where('status', $status);
            if (!empty($args['search'])) {
                $query->where('response', 'LIKE', '%' . $GLOBALS['wpdb']->esc_like($args['search']) . '%');
            }
            $total = (clone $query)->count();
            $rows = $query->offset($per * (Site_MCP_Tools::int_arg($args, 'page', 1, 1, 10000) - 1))->limit($per)->get();
            return ['total' => $total, 'entries' => $rows->map(function ($s) {
                return ['id' => $s->id, 'date' => $s->created_at, 'status' => $s->status, 'source_url' => $s->source_url, 'values' => json_decode($s->response, true)];
            })->toArray()];
        },
    ]);

    Site_MCP_Tools::add('form_entry_update', [
        'group'       => 'forms',
        'cap'         => site_mcp_forms_can('fluentform_manage_entries'),
        'description' => 'Mark a submission read/unread, move it to the trash, or delete it permanently (status=deleted).',
        'properties'  => [
            'form_id'  => ['type' => 'integer'],
            'entry_id' => ['type' => 'integer'],
            'status'   => ['type' => 'string', 'enum' => ['read', 'unread', 'trashed', 'deleted']],
        ],
        'required'    => ['form_id', 'entry_id', 'status'],
        'run'         => function ($args) {
            $entry = Submission::where('form_id', (int) $args['form_id'])->find((int) $args['entry_id']);
            if (!$entry) {
                throw new Site_MCP_Error('Entry not found.');
            }
            if ($args['status'] === 'deleted') {
                $entry->delete();
                return ['message' => 'Entry deleted', 'id' => (int) $args['entry_id']];
            }
            $entry->fill(['status' => $args['status']])->save();
            return ['message' => 'Entry marked ' . $args['status'], 'id' => $entry->id];
        },
    ]);
});
