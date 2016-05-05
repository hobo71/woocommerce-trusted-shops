<?php

class WC_Trusted_Shops_Schedule {

	public $base = null;

	protected static $_instance = null;

	public static function instance( $base ) {
		if ( is_null( self::$_instance ) )
			self::$_instance = new self( $base );
		return self::$_instance;
	}

	private function __construct( $base ) {
		$this->base = $base;

		if ( $this->base->is_rich_snippets_enabled() ) {
			
			add_action( 'woocommerce_gzd_trusted_shops_reviews', array( $this, 'update_reviews' ) );
			
			if ( empty( $this->base->reviews_cache ) )
				add_action( 'init', array( $this, 'update_reviews' ) );
		}
		
		if ( $this->base->is_review_widget_enabled() ) {
			
			add_action( 'woocommerce_gzd_trusted_shops_reviews', array( $this, 'update_review_widget' ) );
			
			if ( empty( $this->base->review_widget_attachment ) )
				add_action( 'init', array( $this, 'update_review_widget' ) );
		}
		
		if ( $this->base->is_review_reminder_enabled() )
			add_action( 'woocommerce_gzd_trusted_shops_reviews', array( $this, 'send_mails' ) );
	}

	/**
	 * Update Review Cache by grabbing information from xml file
	 */
	public function update_reviews() {

		$update = array();
		
		if ( $this->base->is_enabled() ) {
		
			if ( function_exists( 'curl_version' ) ) {
				
				$success = false;
				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_HEADER, false );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
				curl_setopt( $ch, CURLOPT_POST, false );
				curl_setopt( $ch, CURLOPT_URL, $this->base->api_url );
				$output = curl_exec( $ch );
				$httpcode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				
				if ( ! curl_errno( $ch ) && $httpcode != 503 )
					$success = true;
				
				curl_close( $ch );

				if ( $success ) {
					$output = json_decode( $output, true );
					$reviews = $output[ 'response' ][ 'data' ][ 'shop' ][ 'qualityIndicators' ][ 'reviewIndicator' ];
					$update[ 'count' ] = (string) $reviews[ 'activeReviewCount' ];
					$update[ 'avg' ] = (float) $reviews[ 'overallMark' ];
					$update[ 'max' ] = '5.00';
				}
			}
		}
		update_option( 'woocommerce' . $this->base->option_prefix . '_trusted_shops_reviews_cache', $update );
	}

	/**
	 * Updates the review widget graphic and saves it as an attachment
	 */
	public function update_review_widget() {
		
		$filename = $this->base->id . '.gif';
		$raw_data = file_get_contents( 'https://www.trustedshops.com/bewertung/widget/widgets/' . $filename );
		$uploads = wp_upload_dir();
		
		if ( is_wp_error( $uploads ) )
			return;
		
		$filepath = trailingslashit( $uploads['path'] ) . $filename;
  		file_put_contents( $filepath, $raw_data );
  		
  		$attachment = array(
  			'guid' => $uploads[ 'url' ] . '/' . basename( $filepath ),
  			'post_mime_type' => 'image/gif',
  			'post_title' => _x( 'Trusted Shops Customer Reviews', 'trusted-shops', 'woocommerce-trusted-shops' ),
  			'post_content' => '',
  			'post_status' => 'publish',
  		);
		
		if ( ! $this->base->get_review_widget_attachment() ) {
			$attachment_id = wp_insert_attachment( $attachment, $filepath );
			update_option( 'woocommerce' . $this->base->option_prefix . '_trusted_shops_review_widget_attachment', $attachment_id );
		} else {
			$attachment_id = $this->base->get_review_widget_attachment();
			update_attached_file( $attachment_id, $filepath );
			$attachment[ 'ID' ] = $attachment_id;
			wp_update_post( $attachment );
		}
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		
		// Generate the metadata for the attachment, and update the database record.
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $filepath );
		wp_update_attachment_metadata( $attachment_id, $attach_data );
	}

	/**
	 * Send review reminder mails after x days
	 */
	public function send_mails() {
		
		$order_query = new WP_Query(
			array( 
				'post_type'   => 'shop_order', 
				'post_status' => array( 'wc-completed' ), 
				'showposts'   => -1,
				'meta_query'  => array(
					array(
						'key'     => '_trusted_shops_review_mail_sent',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		while ( $order_query->have_posts() ) {

			$order_query->next_post();
			$order = wc_get_order( $order_query->post->ID );
			$diff = $this->base->plugin->get_date_diff( $order->completed_date, date( 'Y-m-d H:i:s' ) );
			
			if ( $diff[ 'd' ] >= (int) $this->base->review_reminder_days ) {
				
				if ( $mail = $this->base->plugin->emails->get_email_instance_by_id( 'customer_trusted_shops' ) ) {
					$mail->trigger( $order->id );
					update_post_meta( $order->id, '_trusted_shops_review_mail_sent', 1 );
				}
			}
		}
	}

}