<?php
/**
 * Plugin Name: WP GraphQL Polylang
 * Plugin URI: https://github.com/valu-digital/wp-graphql-polylang
 * Description: Exposes Polylang languages and translations in the GraphQL schema
 * Author: Esa-Matti Suuronen, Valu Digital Oy
 * Version: 0.1.0
 * Author URI: https://valu.fi/
 *
 * @package wp-graphql-polylang
 */

namespace WPGraphQL\Extensions\Polylang;

use WPGraphQL\Types;
use GraphQLRelay\Relay;

/**
 * Integrates Polylang with WPGraphql
 *
 * - Make sure all languages appear in the queries by default
 * - Add lang where arg
 * - Add lang field
 * - Add translation fields fields:
 *   - translations: Get available translations
 *   - translation: Get specific translated version of the post
 *   - translationObjects: Get all translated objects
 */
class Polylang
{
    private $languageFields = null;

    public function __construct()
    {
        $this->show_posts_by_all_languages();

        add_action('graphql_register_types', [$this, 'register_fields'], 10, 0);
    }

    function register_types()
    {
        $language_codes = [];

        foreach (pll_languages_list() as $lang) {
            $language_codes[strtoupper($lang)] = $lang;
        }

        register_graphql_enum_type('LanguageCodeEnum', [
            'description' => __(
                'Enum of all available language codes',
                'wp-graphql-polylang'
            ),
            'values' => $language_codes,
            // 'defaultValue' => 'FI',
        ]);

        register_graphql_object_type('Language', [
            'description' => __('Language (Polylang)', 'wp-graphql-polylang'),
            'fields' => [
                'id' => [
                    'type' => [
                        'non_null' => 'ID',
                    ],
                    'description' => __(
                        'Language ID (Polylang)',
                        'wp-graphql-polylang'
                    ),
                ],
                'name' => [
                    'type' => 'String',
                    'description' => __(
                        'Human readable language name (Polylang)',
                        'wp-graphql-polylang'
                    ),
                ],
                'code' => [
                    'type' => 'LanguageCodeEnum',
                    'description' => __(
                        'Language code (Polylang)',
                        'wp-graphql-polylang'
                    ),
                ],
                'locale' => [
                    'type' => 'String',
                    'description' => __(
                        'Language locale (Polylang)',
                        'wp-graphql-polylang'
                    ),
                ],
                'slug' => [
                    'type' => 'String',
                    'description' => __(
                        'Language term slug. Prefer the "code" field if possible (Polylang)',
                        'wp-graphql-polylang'
                    ),
                ],
            ],
        ]);
    }

    public function register_fields()
    {
        $this->register_types();
        $this->add_language_root_queries();

        foreach (\WPGraphQL::get_allowed_post_types() as $post_type) {
            $this->add_post_type_fields(get_post_type_object($post_type));
        }

        foreach (\WPGraphQL::get_allowed_taxonomies() as $taxonomy) {
            $this->add_taxonomy_fields(get_taxonomy($taxonomy));
        }

        add_filter(
            'graphql_post_object_connection_query_args',
            function ($query_args) {
                // Polylang handles 'lang' query arg so convert our 'language'
                // query arg if it is set
                if (isset($query_args['language'])) {
                    $query_args['lang'] = $query_args['language'];
                    unset($query_args['language']);
                }

                return $query_args;
            },
            10,
            1
        );

        add_action(
            'graphql_post_object_mutation_update_additional_data',
            function ($post_id, array $input, \WP_Post_Type $post_type_object) {
                if (isset($input['language'])) {
                    pll_set_post_language($post_id, $input['language']);
                }
            },
            10,
            3
        );

        add_filter(
            'graphql_term_object_insert_term_args',
            function ($insert_args, $input) {
                if (isset($input['language'])) {
                    $insert_args['language'] = $input['language'];
                }

                return $insert_args;
            },
            10,
            2
        );
    }

    function add_lang_root_query(string $type)
    {
        register_graphql_fields("RootQueryTo${type}ConnectionWhereArgs", [
            'language' => [
                'type' => 'LanguageCodeEnum',
                'description' => "Filter by ${type}s by language code (Polylang)",
            ],
        ]);
    }

    function add_language_root_queries()
    {
        register_graphql_field('RootQuery', 'languages', [
            'type' => ['list_of' => 'Language'],
            'description' => __(
                'List available languages',
                'wp-graphql-polylang'
            ),
            'resolve' => function ($source, $args, $context, $info) {
                $fields = $info->getFieldSelection();

                // Oh the Polylang api is so nice here. Better ideas?

                $languages = array_map(function ($code) {
                    return [
                        'id' => Relay::toGlobalId('Language', $code),
                        'code' => $code,
                        'slug' => $code,
                    ];
                }, pll_languages_list());

                if (isset($fields['name'])) {
                    foreach (
                        pll_languages_list(['fields' => 'name'])
                        as $index => $name
                    ) {
                        $languages[$index]['name'] = $name;
                    }
                }

                if (isset($fields['locale'])) {
                    foreach (
                        pll_languages_list(['fields' => 'locale'])
                        as $index => $locale
                    ) {
                        $languages[$index]['locale'] = $locale;
                    }
                }

                return $languages;
            },
        ]);

        register_graphql_field('RootQuery', 'defaultLanguage', [
            'type' => 'Language',
            'description' => __('Get language list', 'wp-graphql-polylang'),
            'resolve' => function ($source, $args, $context, $info) {
                $fields = $info->getFieldSelection();
                $language = [];

                // All these fields are build from the same data...
                if ($this->usesSlugBasedField($fields)) {
                    $language['code'] = pll_default_language('slug');
                    $language['id'] = Relay::toGlobalId(
                        'Language',
                        $language['code']
                    );
                    $language['slug'] = $language['code'];
                }

                if (isset($fields['name'])) {
                    $language['name'] = pll_default_language('name');
                }

                if (isset($fields['locale'])) {
                    $language['locale'] = pll_default_language('locale');
                }

                return $language;
            },
        ]);

        register_graphql_field('RootQuery', 'translateString', [
            'type' => 'String',
            'description' => __(
                'Translate string using pll_translate_string() (Polylang)',
                'wp-graphql-polylang'
            ),
            'args' => [
                'string' => [
                    'type' => [
                        'non_null' => 'String',
                    ],
                ],
                'language' => [
                    'type' => [
                        'non_null' => 'LanguageCodeEnum',
                    ],
                ],
            ],
            'resolve' => function ($source, $args, $context, $info) {
                return pll_translate_string($args['string'], $args['language']);
            },
        ]);
    }

    function usesSlugBasedField(array $fields)
    {
        return isset($fields['code']) ||
            isset($fields['slug']) ||
            isset($fields['id']);
    }

    function add_taxonomy_fields(\WP_Taxonomy $taxonomy)
    {
        if (!pll_is_translated_taxonomy($taxonomy->name)) {
            return;
        }

        $type = ucfirst($taxonomy->graphql_single_name);

        $this->add_lang_root_query($type);
        $this->add_mutation_input_fields($type);

        add_action(
            "graphql_insert_{$taxonomy->name}",
            function ($term_id, $args) {
                if (isset($args['language'])) {
                    pll_set_term_language($term_id, $args['language']);
                }
            },
            10,
            2
        );

        add_action(
            "graphql_update_{$taxonomy->name}",
            function ($term_id, $args) {
                if (isset($args['language'])) {
                    pll_set_term_language($term_id, $args['language']);
                }
            },
            10,
            2
        );

        register_graphql_field($type, 'language', [
            'type' => 'Language',
            'description' => __(
                'List available translations for this post',
                'wpnext'
            ),
            'resolve' => function (\WP_Term $term, $args, $context, $info) {
                $fields = $info->getFieldSelection();
                $language = [];

                if ($this->usesSlugBasedField($fields)) {
                    $language['code'] = pll_get_term_language(
                        $term->term_id,
                        'slug'
                    );
                    $language['slug'] = $language['code'];
                    $language['id'] = Relay::toGlobalId(
                        'Language',
                        $language['code']
                    );
                }

                if (isset($fields['name'])) {
                    $language['name'] = pll_get_term_language(
                        $term->term_id,
                        'name'
                    );
                }

                if (isset($fields['locale'])) {
                    $language['locale'] = pll_get_term_language(
                        $term->term_id,
                        'locale'
                    );
                }

                return $language;
            },
        ]);

        register_graphql_field($type, 'translations', [
            'type' => [
                'list_of' => $type,
            ],
            'description' => __(
                'List all translated versions of this term',
                'wp-graphql-polylang'
            ),
            'resolve' => function (\WP_Term $term) {
                $terms = [];

                foreach (
                    pll_get_term_translations($term->term_id)
                    as $lang => $term_id
                ) {
                    if ($term_id === $term->term_id) {
                        continue;
                    }

                    $translation = get_term($term_id);

                    if (!$translation) {
                        continue;
                    }

                    if (is_wp_error($translation)) {
                        continue;
                    }

                    $terms[] = $translation;
                }

                return $terms;
            },
        ]);
    }

    function add_mutation_input_fields(string $type)
    {
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
    }

    function add_post_type_fields(\WP_Post_Type $post_type_object)
    {
        if (!pll_is_translated_post_type($post_type_object->name)) {
            return;
        }

        $type = ucfirst($post_type_object->graphql_single_name);

        $this->add_lang_root_query($type);
        $this->add_mutation_input_fields($type);

        register_graphql_field(
            $post_type_object->graphql_single_name,
            'translation',
            [
                'type' => $type,
                'description' => __(
                    'Get specific translation version of this object',
                    'wp-graphql-polylang'
                ),
                'args' => [
                    'language' => [
                        'type' => [
                            'non_null' => 'LanguageCodeEnum',
                        ],
                    ],
                ],
                'resolve' => function (\WP_Post $post, array $args) {
                    $translations = pll_get_post_translations($post->ID);
                    $post_id = $translations[$args['language']] ?? null;

                    if (!$post_id) {
                        return null;
                    }

                    return \WP_Post::get_instance($post_id);
                },
            ]
        );

        register_graphql_field(
            $post_type_object->graphql_single_name,
            'translationCodes',
            [
                'type' => ['list_of' => 'LanguageCodeEnum'],
                'description' => __(
                    'List available translations for this post',
                    'wp-graphql-polylang'
                ),
                'resolve' => function (\WP_Post $post) {
                    $codes = [];
                    $current_code = pll_get_post_language($post->ID, 'slug');

                    foreach (
                        array_keys(pll_get_post_translations($post->ID))
                        as $code
                    ) {
                        if ($code !== $current_code) {
                            $codes[] = $code;
                        }
                    }

                    return $codes;
                },
            ]
        );

        register_graphql_field(
            $post_type_object->graphql_single_name,
            'translations',
            [
                'type' => [
                    'list_of' => $type,
                ],
                'description' => __(
                    'List all translated versions of this post',
                    'wp-graphql-polylang'
                ),
                'resolve' => function (\WP_Post $post) {
                    $posts = [];

                    foreach (
                        pll_get_post_translations($post->ID)
                        as $lang => $post_id
                    ) {
                        $translation = get_post($post_id);

                        if (!$translation) {
                            continue;
                        }

                        if (is_wp_error($translation)) {
                            continue;
                        }

                        if ($post->ID === $translation->ID) {
                            continue;
                        }

                        $posts[] = $translation;
                    }

                    return $posts;
                },
            ]
        );

        register_graphql_field(
            $post_type_object->graphql_single_name,
            'language',
            [
                'type' => 'Language',
                'description' => __('Polylang language', 'wpnext'),
                'resolve' => function (\WP_Post $post, $args, $context, $info) {
                    $fields = $info->getFieldSelection();
                    $language = [];

                    if ($this->usesSlugBasedField($fields)) {
                        $language['code'] = pll_get_post_language(
                            $post->ID,
                            'slug'
                        );
                        $language['slug'] = $language['code'];
                        $language['id'] = Relay::toGlobalId(
                            'Language',
                            $language['code']
                        );
                    }

                    if (isset($fields['name'])) {
                        $language['name'] = pll_get_post_language(
                            $post->ID,
                            'name'
                        );
                    }

                    if (isset($fields['locale'])) {
                        $language['locale'] = pll_get_post_language(
                            $post->ID,
                            'locale'
                        );
                    }

                    return $language;
                },
            ]
        );
    }

    function show_posts_by_all_languages()
    {
        add_filter(
            'graphql_post_object_connection_query_args',
            function ($query_args) {
                $query_args['show_all_languages_in_graphql'] = true;
                return $query_args;
            },
            10,
            1
        );

        /**
         * Handle query var added by the above filter in Polylang which
         * causes all languages to be shown in the queries.
         * See https://github.com/polylang/polylang/blob/2ed446f92955cc2c952b944280fce3c18319bd85/include/query.php#L125-L134
         */
        add_filter(
            'pll_filter_query_excluded_query_vars',
            function () {
                $excludes[] = 'show_all_languages_in_graphql';
                return $excludes;
            },
            3,
            10
        );
    }
}

add_action('init', function () {
    if (function_exists('pll_get_post_language')) {
        new Polylang();
    }
});