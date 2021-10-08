<?php

namespace WPGraphQL\Extensions\Polylang;

use WPGraphQL\Extensions\Polylang\Model\Language;

class PostObject
{
    function init()
    {
        add_action(
            'graphql_register_types',
            [$this, '__action_graphql_register_types'],
            10,
            0
        );

        add_action(
            'graphql_post_object_mutation_update_additional_data',
            [
                $this,
                '__action_graphql_post_object_mutation_update_additional_data',
            ],
            10,
            4
        );

        add_filter(
            'graphql_map_input_fields_to_wp_query',
            [__NAMESPACE__ . '\\Helpers', 'map_language_to_query_args'],
            10,
            2
        );

        /**
         * Check translated front page
         */
        add_action(
            'graphql_resolve_field',
            [$this, '__action_is_translated_front_page'],
            10,
            8
        );
    }

    /**
     * Handle 'language' in post object create&language mutations
     */
    function __action_graphql_post_object_mutation_update_additional_data(
        $post_id,
        array $input,
        \WP_Post_Type $post_type_object,
        $mutation_name
    ) {
        $is_create = substr($mutation_name, 0, 6) === 'create';

        if (isset($input['language'])) {
            pll_set_post_language($post_id, $input['language']);
        } elseif ($is_create) {
            $default_lang = pll_default_language();
            pll_set_post_language($post_id, $default_lang);
        }
    }

    function __action_graphql_register_types()
    {
        register_graphql_fields('RootQueryToContentNodeConnectionWhereArgs', [
            'language' => [
                'type' => 'LanguageCodeFilterEnum',
                'description' =>
                    'Filter content nodes by language code (Polylang)',
            ],
            'languages' => [
                'type' => [
                    'list_of' => [
                        'non_null' => 'LanguageCodeEnum',
                    ],
                ],
                'description' =>
                    'Filter content nodes by one or more languages (Polylang)',
            ],
        ]);

        foreach (\WPGraphQL::get_allowed_post_types() as $post_type) {
            $this->add_post_type_fields(get_post_type_object($post_type));
        }
    }

    function add_post_type_fields(\WP_Post_Type $post_type_object)
    {
        if (!pll_is_translated_post_type($post_type_object->name)) {
            return;
        }

        $type = ucfirst($post_type_object->graphql_single_name);

        register_graphql_fields("RootQueryTo${type}ConnectionWhereArgs", [
            'language' => [
                'type' => 'LanguageCodeFilterEnum',
                'description' => "Filter by ${type}s by language code (Polylang)",
            ],
            'languages' => [
                'type' => [
                    'list_of' => [
                        'non_null' => 'LanguageCodeEnum',
                    ],
                ],
                'description' => "Filter ${type}s by one or more languages (Polylang)",
            ],
        ]);

        register_graphql_fields("Create${type}Input", [
            'language' => [
                'type' => 'LanguageCodeEnum',
            ],
        ]);

        register_graphql_fields("Update${type}Input", [
            'language' => [
                'type' => 'LanguageCodeEnum',
            ],
        ]);

        register_graphql_interfaces_to_types(['NodeWithTranslations'], [$type]);
    }

    function __action_is_translated_front_page(
        $result,
        $source,
        $args,
        $context,
        $info,
        $type_name,
        $field_key
    ) {
        if ('isFrontPage' !== $field_key) {
            return $result;
        }

        if (!($source instanceof \WPGraphQL\Model\Post)) {
            return $result;
        }

        if ('page' !== get_option('show_on_front', 'posts')) {
            return $result;
        }

        if (empty((int) get_option('page_on_front', 0))) {
            return $result;
        }

        $translated_front_page = pll_get_post_translations(
            get_option('page_on_front', 0)
        );

        if (empty($translated_front_page)) {
            return false;
        }

        return in_array($source->ID, $translated_front_page);
    }
}
