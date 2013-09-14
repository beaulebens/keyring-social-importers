<?php

require 'wp-blog-header.php';

global $_keyring_importers;

$sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'keyring_service' LIMIT 10";

while ( $ids = $wpdb->get_col( $sql ) ) {
	$post_sql = "SELECT * FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $ids ) . ")";
	foreach ( $wpdb->get_results( $post_sql ) as $post ) {
		$slug = get_post_meta( $post->ID, 'keyring_service', true );
		if ( !in_array( $slug, array_keys( $_keyring_importers ) ) )
			continue;

		$importer = $_keyring_importers[ $slug ];
		$label    = $importer::LABEL;

		echo $post->ID . '::' . $label . "<br />";

		wp_set_object_terms( $post->ID, $label, 'keyring_services' );
		delete_post_meta( $post->ID, 'keyring_service' );
	}

	flush();
	sleep( 1 );
	$posts = false;
}
