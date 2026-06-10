<?php
namespace SlimTwig;

class Data {
	/**
	 * Get an item from an array or object using "dot" notation.
	 * Similar to data_get() function in Laravel.
	 *
	 * @link https://github.com/laravel/framework/blob/master/src/Illuminate/Collections/helpers.php#L46
	 * @param  array|object $target
	 * @param  string       $key
	 * @param  mixed        $default
	 * @return mixed
	 */
	public static function get( $target, string $key, $default = null ) {
		$key = explode( '.', $key );

		foreach ( $key as $segment ) {
			if ( is_array( $target ) && isset( $target[ $segment ] ) ) {
				$target = $target[ $segment ];
			} elseif ( is_object( $target ) && isset( $target->$segment ) ) {
				$target = $target->$segment;
			} else {
				return $default;
			}
		}

		return $target;
	}
}
