<?php

namespace WPGraphQL\Extensions\Polylang;

use WPGraphQL\Extensions\Polylang\Model\Language;

class LanguageRootQueries
{
    function init()
    {
        add_action(
            'graphql_register_types',
            [$this, '__action_graphql_register_types'],
            10,
            0
        );
    }

    function __action_graphql_register_types()
    {
        register_graphql_field('RootQuery', 'languages', [
            'type' => ['list_of' => 'Language'],
            'description' => __(
                'List available languages',
                'wp-graphql-polylang'
            ),
            'resolve' => function ($source, $args, $context, $info) {
                $slugs = \pll_languages_list();
                $languages = [];
                foreach ($slugs as $slug) {
                    $languages[] = new Language($slug);
                }
                return $languages;
            },
        ]);

        register_graphql_field('RootQuery', 'defaultLanguage', [
            'type' => 'Language',
            'description' => __('Get language list', 'wp-graphql-polylang'),
            'resolve' => function ($source, $args, $context, $info) {
                return new Language(pll_default_language('slug'));
            },
        ]);
    }
}
