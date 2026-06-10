<?php
namespace eLightUp\SlimSEO\Common\Helpers;

class Data {
	public static function get_post_types(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		unset( $post_types['attachment'] );

		return apply_filters( 'slim_seo_post_types', $post_types );
	}

	public static function get_taxonomies(): array {
		$taxonomies = get_taxonomies( [
			'public'  => true,
			'show_ui' => true,
		], 'objects' );

		return apply_filters( 'slim_seo_taxonomies', $taxonomies );
	}
}
