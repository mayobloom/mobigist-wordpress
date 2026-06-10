<?php
namespace eLightUp\SlimSEO\Common;

class Assets {
	public static function enqueue_css( string $name ): void {
		wp_enqueue_style(
			"slim-seo-common-$name",
			self::get_base_url() . "css/$name.css",
			[],
			filemtime( self::get_base_dir() . "css/$name.css" )
		);
	}

	public static function enqueue_js( string $name ): void {
		wp_enqueue_script(
			"slim-seo-common-$name",
			self::get_base_url() . "js/$name.js",
			[],
			filemtime( self::get_base_dir() . "js/$name.js" ),
			true
		);
	}

	private static function get_base_url(): string {
		return plugin_dir_url( dirname( __FILE__ ) ) . 'assets/';
	}

	private static function get_base_dir(): string {
		return plugin_dir_path( dirname( __FILE__ ) ) . 'assets/';
	}
}