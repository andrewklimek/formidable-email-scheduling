<?php
if ( empty( $values['fields'] ) ) {
	return;
}

foreach ( $values['fields'] as $fo_key => $fo ) {
	// don't include repeatable fields: $fo['form_id'] != $values['id']
	if ( ( isset( $post_field ) && ! in_array( $fo['type'], $post_field ) ) || \FrmField::is_no_save_field( $fo['type'] ) || $fo['type'] == 'form' || $fo['form_id'] != $values['id'] ) {
		continue;
	}
	// Dunno what this is 
	// if ( $fo['post_field'] == $post_key ) {
// 		$values[$post_key] = $fo['id'];
// 	}
	?>
	<option value="<?php echo $fo['id'] ?>" <?php selected( $field_val, $fo['id'] ) ?>><?php echo $fo['name'] ?></option>
	<?php
}