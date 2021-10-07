<?php

namespace WPGraphQL\Extensions\Polylang\Model;

use Exception;
use GraphQLRelay\Relay;
use WPGraphQL\Model\Model;

/**
 * Class Language - Models data for Languages
 *
 * @property string $id
 * @property string $code
 * @property string $homeUrl
 * @property string $locale
 * @property string $name
 * @property string $slug
 *
 * @package WPGraphQL\Extensions\Polylang\Model
 */
class Language extends Model {

  /**
   * Stores the incoming Language data
   *
   * @var object $data
   */
  protected $data;

  /**
   * Language constructor.
   *
   * @param string $slug The slug for the incoming language that needs modeling
   *
   * @return void
   * @throws Exception
   */
  public function __construct($slug) {

    // $the_languages = pll_the_languages(['raw' => 1, 'hide_if_empty' => 0]);
    $slugs = pll_languages_list(['fields' => 'slug']);
    $names = pll_languages_list(['fields' => 'name']);
    $locales = pll_languages_list(['fields' => 'locale']);
    $language = null;
    foreach ($slugs as $i => $s) {
      if ($s === $slug) {
        $language = [
          'slug' => $s,
          'name' => $names[$i],
          'locale' => $locales[$i],
        ];
        break;
      }
    }

    if (empty($language)) {
      throw new Exception( sprintf( __( 'Unable to find language for slug "%s" on %s object', 'wp-graphql-polylang' ), $slug, $this->get_model_name() ) );
    }

    $this->data = (object) $language;
    parent::__construct();
  }

  /**
   * Initializes the Language object
   *
   * @return void
   */
  protected function init() {

    if ( empty( $this->fields ) ) {

      $this->fields = [
        'id' => function () {
          if (! empty($this->data->slug)) {
            return Relay::toGlobalId('Language', $this->data->slug);
          }
          return null;
        },
        'code' => function () {
          return ! empty( $this->data->slug ) ? strtoupper($this->data->slug) : null;
        },
        'homeUrl' => function () {
          return ! empty( $this->data->slug ) ? pll_home_url($this->data->slug) : null;
        },
        'locale' => function () {
          return ! empty( $this->data->locale ) ? $this->data->locale : null;
        },
        'name' => function () {
          if (! empty($this->data->name)) {
            return $this->html_entity_decode( $this->data->name, 'name', true );
          }
          return null;
        },
        'slug' => function () {
          return ! empty( $this->data->slug ) ? $this->data->slug : null;
        },
      ];
    }

  }

}
