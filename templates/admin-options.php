<?php
/**
 * Admin options page template.
 *
 * @package WPS3_S3_Offload
 *
 * @var int $missing_number
 */

?>
<p>
	<?php
	printf(
		/* translators: %d: number of media entries still stored locally. */
		esc_html__( 'You have %d media entries, which are still stored locally', 'wp-s3-offload' ),
		(int) $missing_number
	);
	?>
</p>

<?php if ( $missing_number > 0 ) { ?>
<button class="button" id="start_batch_migration"><?php esc_html_e( 'Start Migration into S3 Storage', 'wp-s3-offload' ); ?></button>
<?php } else { ?>
<div class="notice notice-success">
	<?php esc_html_e( 'Great! All your media files are stored remotely', 'wp-s3-offload' ); ?>
</div>
<?php } ?>

<div id="migration_log" style="display:none;" data-count="<?php echo esc_attr( (string) $missing_number ); ?>">
	<div id="progress_bar">
		<div id="progress_bar_bar"></div>
		<div id="progress_bar_percent">50%</div>
	</div>
	<div id="migration_protocol"></div>
</div>

<hr />

<button class="button" id="test_connection"><?php esc_html_e( 'Test S3 Connection', 'wp-s3-offload' ); ?></button>

<style>
	#progress_bar {
		border: 1px solid #000;
		height: 30px;
		margin-top: 10px;
		position: relative;
	}

	#progress_bar_bar {
		position: absolute;
		top: 0;
		left: 0;
		bottom: 0;
		width: 50%;
		background-color: #99cc66;
		transition: width 1s;
	}

	#progress_bar_percent {
		position: absolute;
		top: 0;
		left: 0;
		bottom: 0;
		right: 0;
		font-size: 20px;
		padding-top: 5px;
		text-align: center;
		text-shadow:
			1px 1px 0 #fff,
			-1px 1px 0 #fff,
			-1px -1px 0 #fff,
			1px -1px 0 #fff;
	}
</style>
