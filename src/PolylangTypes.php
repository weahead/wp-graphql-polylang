<?php

namespace WPGraphQL\Extensions\Polylang;

use GraphQLRelay\Relay;

class PolylangTypes
{
    function init()
    {
        add_action(
            'graphql_register_types',
            [$this, '__action_graphql_register_types'],
            9,
            1
        );
    }

    function __action_graphql_register_types(\WPGraphQL\Registry\TypeRegistry $type_registry)
    {
        $language_codes = [];

        foreach (pll_languages_list() as $lang) {
            $language_codes[strtoupper($lang)] = $lang;
        }

        if (empty($language_codes)) {
            $locale = get_locale();
            $language_codes[strtoupper($locale)] = [
                'value' => $locale,
                'description' => __(
                    'The default locale of the site',
                    'wp-graphql-polylang'
                ),
            ];
        }

        register_graphql_enum_type('LanguageCodeEnum', [
            'description' => __(
                'Enum of all available language codes',
                'wp-graphql-polylang'
            ),
            'values' => $language_codes,
        ]);

        register_graphql_enum_type('LanguageCodeFilterEnum', [
            'description' => __(
                'Filter item by specific language, default language or list all languages',
                'wp-graphql-polylang'
            ),
            'values' => array_merge($language_codes, [
                'DEFAULT' => 'default',
                'ALL' => 'all',
            ]),
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
                'homeUrl' => [
                    'type' => 'String',
                    'description' => __(
                        'Language term front page URL',
                        'wp-graphql-polylang'
                    ),
                ],
            ],
        ]);

        register_graphql_interface_type('NodeWithTranslations', [
            'description' => __('Interface for Nodes with translations', 'wp-graphql-polylang'),
            'interfaces' => ['ContentNode'],
            'resolveType' => function ( \WPGraphQL\Model\Post $post ) use ( $type_registry ) {
                $type      = null;
                $post_type = isset( $post->post_type ) ? $post->post_type : null;

                if ( isset( $post->post_type ) && 'revision' === $post->post_type ) {
                    $parent = get_post( $post->parentDatabaseId );
                    if ( ! empty( $parent ) && isset( $parent->post_type ) ) {
                        $post_type = $parent->post_type;
                    }
                }

                $post_type_object = ! empty( $post_type ) ? get_post_type_object( $post_type ) : null;

                if ( isset( $post_type_object->graphql_single_name ) ) {
                    $type = $type_registry->get_type( $post_type_object->graphql_single_name );
                }

                return ! empty( $type ) ? $type : null;
            },
            'fields'      => [
                'language' => [
                    'type' => 'Language',
                    'description' => __('Polylang language', 'wp-graphql-polylang'),
                    'resolve' => function (
                        \WPGraphQL\Model\Post $post,
                        $args,
                        $context,
                        $info
                    ) {
                        $fields = $info->getFieldSelection();
                        $language = [
                            'name' => null,
                            'slug' => null,
                            'code' => null,
                        ];

                        $post_id = $post->ID;

                        // The language of the preview post is not set at all so we
                        // must get the language using the original post id
                        if ($post->isPreview) {
                            $post_id = wp_get_post_parent_id($post->ID);
                        }

                        $slug = pll_get_post_language($post_id, 'slug');

                        if (!$slug) {
                            return null;
                        }

                        $language['code'] = $slug;
                        $language['slug'] = $slug;
                        $language['id'] = Relay::toGlobalId('Language', $slug);

                        if (isset($fields['name'])) {
                            $language['name'] = pll_get_post_language(
                                $post_id,
                                'name'
                            );
                        }

                        if (isset($fields['locale'])) {
                            $language['locale'] = pll_get_post_language(
                                $post_id,
                                'locale'
                            );
                        }

                        return $language;
                    },
                ],
                'translation' => [
                    'type' => 'NodeWithTranslations',
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
                    'resolve' => function (
                        \WPGraphQL\Model\Post $post,
                        array $args
                    ) {
                        $translations = pll_get_post_translations($post->ID);
                        $post_id = $translations[$args['language']] ?? null;

                        if (!$post_id) {
                            return null;
                        }

                        return new \WPGraphQL\Model\Post(
                            \WP_Post::get_instance($post_id)
                        );
                    },
                ],
                'translations' => [
                    'type'          =>  [
                        'list_of'      =>  'NodeWithTranslations',
                    ],
                    'description' => __(
                        'List all translated versions of this post',
                        'wp-graphql-polylang'
                    ),
                    'resolve' => function (\WPGraphQL\Model\Post $post) {
                        $posts = [];

                        if ($post->isPreview) {
                            $parent = wp_get_post_parent_id($post->ID);
                            $translations = pll_get_post_translations($parent);
                        } else {
                            $translations = pll_get_post_translations($post->ID);
                        }

                        foreach ($translations as $lang => $post_id) {
                            $translation = \WP_Post::get_instance($post_id);

                            if (!$translation) {
                                continue;
                            }

                            if (is_wp_error($translation)) {
                                continue;
                            }

                            if ($post->ID === $translation->ID) {
                                continue;
                            }

                            // If fetching preview do not add the original as a translation
                            if ($post->isPreview && $parent === $translation->ID) {
                                continue;
                            }

                            $model = new \WPGraphQL\Model\Post($translation);

                            // If we do not filter out privates here wp-graphql will
                            // crash with 'Cannot return null for non-nullable field
                            // Post.id.'. This might be a wp-graphql bug.
                            // Interestingly only fetching the id of the translated
                            // post caused the crash. For example title is ok even
                            // without this check
                            if ($model->is_private()) {
                                continue;
                            }

                            $posts[] = $model;
                        }

                        return $posts;
                    },
                ],
            ],
        ]);

        register_graphql_interface_type('TermNodeWithTranslations', [
            'description' => __('Interface for TermNodes with translations', 'wp-graphql-polylang'),
            'interfaces' => ['TermNode'],
            'resolveType' => function ( $term ) use ( $type_registry ) {

                /**
                 * The resolveType callback is used at runtime to determine what Type an object
                 * implementing the ContentNode Interface should be resolved as.
                 *
                 * You can filter this centrally using the "graphql_wp_interface_type_config" filter
                 * to override if you need something other than a Post object to be resolved via the
                 * $post->post_type attribute.
                 */
                $type = null;

                if ( isset( $term->taxonomyName ) ) {
                    $tax_object = get_taxonomy( $term->taxonomyName );
                    if ( isset( $tax_object->graphql_single_name ) ) {
                        $type = $type_registry->get_type( $tax_object->graphql_single_name );
                    }
                }

                return ! empty( $type ) ? $type : null;

            },
            'fields'      => [
                'language' => [
                    'type' => 'Language',
                    'description' => __('Polylang language', 'wp-graphql-polylang'),
                    'resolve' => function (
                        \WPGraphQL\Model\Term $term,
                        $args,
                        $context,
                        $info
                    ) {
                        $fields = $info->getFieldSelection();
                        $language = [];

                        if (Helpers::uses_slug_based_field($fields)) {
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
                ],
                'translation' => [
                    'type' => 'TermNodeWithTranslations',
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
                    'resolve' => function (\WPGraphQL\Model\Term $term, array $args) {
                        $translations = pll_get_term_translations($term->term_id);
                        $term_id = $translations[$args['language']] ?? null;

                        if (!$term_id) {
                            return null;
                        }

                        return new \WPGraphQL\Model\Term(get_term($term_id));
                    },
                ],
                'translations' => [
                    'type'          =>  [
                        'list_of'      =>  'TermNodeWithTranslations',
                    ],
                    'description' => __(
                        'List all translated versions of this term',
                        'wp-graphql-polylang'
                    ),
                    'resolve' => function (\WPGraphQL\Model\Term $term) {
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

                            $terms[] = new \WPGraphQL\Model\Term($translation);
                        }

                        return $terms;
                    },
                ],
            ],
        ]);
    }
}
