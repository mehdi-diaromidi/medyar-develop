<?php
namespace DrPlus\SMS;

use DrPlus\SMS\Utils;

class Panelchi extends Gateway {
	public function __construct() {
		$settings = Utils::get_settings();
		parent::__construct( [
			'id'			=> 'panelchi',
			'url_template'	=> 'https://api.panelchi.com/sms',
			'api_key'		=> $settings['panelchi']['api_key'],
			'from'			=> '10001',
		] );

		return $this;
	}

	protected function prepare() {
		$this->set_data( [
			'request_options'	=> [
				'httpversion'	=> '1.1',
				'timeout'		=> 60,
				'headers'	=> [
					'content-type'	=> 'application/json',
					'Authorization'	=> 'Bearer ' . $this->api_key,
				],
			],
		] );

		return $this;
	}

	public function _send() {
		return $this;
	}

	public function _send_by_pattern( string $pattern ) {
		$request_args = $this->request_options;

		$variables = [];
		foreach( array_values( $this->exploded_pattern ) as $variable ) {
			$variable = explode( ":", $variable );
			$variables[$variable[0]] = $variable[1];
		}

		$request_args['body'] = json_encode( [
			'pattern'		=> $pattern,
			'variables'		=> $variables,
			'recipient'		=> $this->to[0],
			'sourceNumber'	=> $this->from,
		] );

		$request = wp_remote_post( "{$this->base_url}/pattern", $request_args );
		if( is_wp_error( $request ) ) {
			$this->wp_error = $request;
		}
		return $this;
	}
}