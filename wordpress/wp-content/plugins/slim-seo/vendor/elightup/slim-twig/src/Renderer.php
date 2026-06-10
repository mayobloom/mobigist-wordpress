<?php
namespace SlimTwig;

class Renderer {
	public static function render( string $text, $data ): string {
		preg_match_all( '#{{\s*?([^}\s]+?)\s*?}}#', $text, $matches, PREG_SET_ORDER );
		foreach ( $matches as $variable ) {
			$value = self::render_variable( $variable[1], $data );
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$value = (string) $value;
			$text  = str_replace( $variable[0], $value, $text );
		}
		return $text;
	}

	private static function render_variable( string $variable, $data ) {
		return Data::get( $data, $variable, "{{ $variable }}" );
	}
}
