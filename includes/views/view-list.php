<?php
/**
 * View: tela de listagem de inscrições.
 *
 * Variáveis disponíveis (definidas em Admin::render_list_page):
 *
 * @var \Music_Club_Registrations\List_Table $list_table      Instância da tabela.
 * @var string                                $current_search Termo de busca atual.
 * @var string                                $current_status Filtro de status atual.
 * @var array<string,string>                  $export_columns Colunas disponíveis para exportação.
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$export_nonce = wp_create_nonce( 'mcr_export' );
$export_base  = admin_url( 'admin-post.php' );
$list_slug    = \Music_Club_Registrations\Admin::LIST_SLUG;

$column_checkboxes_html = '';
foreach ( $export_columns as $key => $label ) {
	$column_checkboxes_html .= sprintf(
		'<label class="mcr-column-option"><input type="checkbox" name="columns[]" value="%1$s" checked="checked" /> %2$s</label>',
		esc_attr( $key ),
		esc_html( $label )
	);
}
?>
<div class="wrap mcr-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Registrations', 'music-club-registrations' ); ?></h1>

	<hr class="wp-header-end" />

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( $list_slug ); ?>" />
		<?php $list_table->search_box( __( 'Search registrations', 'music-club-registrations' ), 'mcr-search' ); ?>
		<?php $list_table->display(); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Export', 'music-club-registrations' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Choose which columns to include before generating the file. Selected checkboxes on the table above are used for the "Selected" export.', 'music-club-registrations' ); ?>
	</p>

	<div class="mcr-export-panels">
		<div class="mcr-export-panel">
			<h3><?php esc_html_e( 'All Registrations', 'music-club-registrations' ); ?></h3>
			<form method="post" action="<?php echo esc_url( $export_base ); ?>">
				<input type="hidden" name="scope" value="all" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $export_nonce ); ?>" />
				<div class="mcr-column-options"><?php echo $column_checkboxes_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped when built above. ?></div>
				<button type="submit" name="action" value="mcr_export_csv" class="button"><?php esc_html_e( 'Export CSV', 'music-club-registrations' ); ?></button>
				<button type="submit" name="action" value="mcr_export_xlsx" class="button"><?php esc_html_e( 'Export Excel', 'music-club-registrations' ); ?></button>
			</form>
		</div>

		<div class="mcr-export-panel">
			<h3><?php esc_html_e( 'Filtered Results', 'music-club-registrations' ); ?></h3>
			<form method="post" action="<?php echo esc_url( $export_base ); ?>">
				<input type="hidden" name="scope" value="filtered" />
				<input type="hidden" name="s" value="<?php echo esc_attr( $current_search ); ?>" />
				<input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $export_nonce ); ?>" />
				<div class="mcr-column-options"><?php echo $column_checkboxes_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped when built above. ?></div>
				<button type="submit" name="action" value="mcr_export_csv" class="button"><?php esc_html_e( 'Export CSV', 'music-club-registrations' ); ?></button>
				<button type="submit" name="action" value="mcr_export_xlsx" class="button"><?php esc_html_e( 'Export Excel', 'music-club-registrations' ); ?></button>
			</form>
		</div>

		<div class="mcr-export-panel">
			<h3><?php esc_html_e( 'Selected Rows', 'music-club-registrations' ); ?></h3>
			<form method="post" action="<?php echo esc_url( $export_base ); ?>" id="mcr-selected-export-form">
				<input type="hidden" name="scope" value="selected" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $export_nonce ); ?>" />
				<div class="mcr-column-options"><?php echo $column_checkboxes_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped when built above. ?></div>
				<button type="submit" name="action" value="mcr_export_csv" class="button"><?php esc_html_e( 'Export CSV', 'music-club-registrations' ); ?></button>
				<button type="submit" name="action" value="mcr_export_xlsx" class="button"><?php esc_html_e( 'Export Excel', 'music-club-registrations' ); ?></button>
				<p class="description">
					<?php esc_html_e( 'Check rows on the table above first — this form will export exactly those rows.', 'music-club-registrations' ); ?>
				</p>
			</form>
		</div>
	</div>
</div>
