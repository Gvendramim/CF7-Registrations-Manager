<?php
/**
 * Classe responsável pela exportação de inscrições em CSV e Excel (.xlsx).
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Export
 *
 * Responsabilidade única: transformar conjuntos de inscrições em arquivos
 * para download (CSV e XLSX), respeitando as colunas selecionadas pelo
 * administrador antes da geração do arquivo.
 */
class Export {

	/**
	 * Registra os hooks de admin-post.php utilizados para processar as
	 * requisições de exportação.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_mcr_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_mcr_export_xlsx', array( $this, 'handle_export_xlsx' ) );
	}

	/**
	 * Resolve quais registros devem ser exportados, de acordo com o escopo
	 * solicitado ("all", "filtered" ou "selected").
	 *
	 * @return array
	 */
	private function resolve_export_items() {
		$scope = isset( $_REQUEST['scope'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['scope'] ) ) : 'all';

		if ( 'selected' === $scope ) {
			$ids = isset( $_REQUEST['registration_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['registration_ids'] ) ) : array();

			return Database::get_registrations_by_ids( $ids );
		}

		$args = array(
			'search' => '',
			'status' => '',
		);

		if ( 'filtered' === $scope ) {
			$args['search'] = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
			$args['status'] = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
		}

		$ids = Database::get_all_ids( $args );

		return Database::get_registrations_by_ids( $ids );
	}

	/**
	 * Verifica permissões e nonce antes de processar uma exportação.
	 *
	 * @return bool
	 */
	private function verify_export_request() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to export this data.', 'music-club-registrations' ) );
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'mcr_export' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'music-club-registrations' ) );
		}

		return true;
	}

	/**
	 * Retorna todas as colunas disponíveis para exportação, na ordem
	 * padrão, com seus rótulos. Usada tanto para gerar os arquivos quanto
	 * para renderizar o seletor de colunas na tela de listagem.
	 *
	 * @return array<string,string>
	 */
	public static function get_all_columns() {
		return array(
			'registration_number' => __( 'Registration Number', 'music-club-registrations' ),
			'child_name'           => __( 'Student Name', 'music-club-registrations' ),
			'parent_name'          => __( 'Parent/Guardian', 'music-club-registrations' ),
			'parent_email'         => __( 'Email', 'music-club-registrations' ),
			'phone'                => __( 'Phone', 'music-club-registrations' ),
			'child_class'          => __( 'Class', 'music-club-registrations' ),
			'interests'            => __( 'Program', 'music-club-registrations' ),
			'additional_message'   => __( 'Additional Message', 'music-club-registrations' ),
			'status'               => __( 'Status', 'music-club-registrations' ),
			'created_at'           => __( 'Date', 'music-club-registrations' ),
		);
	}

	/**
	 * Resolve as colunas selecionadas pelo administrador antes da geração
	 * do arquivo. Caso nenhuma coluna tenha sido explicitamente enviada
	 * (ex: link de exportação rápida), todas as colunas são utilizadas.
	 *
	 * @return array<string,string>
	 */
	private function resolve_selected_columns() {
		$all = self::get_all_columns();

		if ( empty( $_REQUEST['columns'] ) || ! is_array( $_REQUEST['columns'] ) ) {
			return $all;
		}

		$requested = array_map( 'sanitize_key', wp_unslash( $_REQUEST['columns'] ) );
		$selected  = array();

		// Preserva a ordem padrão das colunas, filtrando apenas as
		// efetivamente marcadas pelo administrador.
		foreach ( $all as $key => $label ) {
			if ( in_array( $key, $requested, true ) ) {
				$selected[ $key ] = $label;
			}
		}

		// Nunca gera um arquivo sem colunas: se nada válido foi selecionado,
		// recorre a todas as colunas.
		return $selected ?: $all;
	}

	/**
	 * Processa e envia um arquivo CSV para download.
	 *
	 * @return void
	 */
	public function handle_export_csv() {
		$this->verify_export_request();

		$items   = $this->resolve_export_items();
		$columns = $this->resolve_selected_columns();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=registrations-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		// BOM UTF-8 para compatibilidade com Excel.
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv( $output, array_values( $columns ) );

		foreach ( $items as $item ) {
			fputcsv( $output, $this->build_row( $item, $columns ) );
		}

		fclose( $output );

		Logger::info(
			'export',
			sprintf( 'CSV export generated with %d record(s) and %d column(s).', count( $items ), count( $columns ) ),
			get_current_user_id()
		);

		exit;
	}

	/**
	 * Processa e envia um arquivo Excel (.xlsx) para download.
	 *
	 * Utiliza a biblioteca PhpSpreadsheet quando disponível (via Composer,
	 * carregada a partir de vendor/autoload.php). Caso a biblioteca não
	 * esteja instalada, ou a exportação em Excel esteja desativada nas
	 * configurações do plugin, o usuário é informado e redirecionado de
	 * volta, evitando gerar um arquivo .xlsx corrompido ou inválido.
	 *
	 * @return void
	 */
	public function handle_export_xlsx() {
		$this->verify_export_request();

		if ( ! Settings::get( 'enable_excel_export', true ) ) {
			wp_die(
				esc_html__( 'Excel export is currently disabled in the plugin settings. Please enable it under Music Club > Settings, or use the CSV export instead.', 'music-club-registrations' ),
				esc_html__( 'Feature disabled', 'music-club-registrations' ),
				array( 'back_link' => true )
			);
		}

		if ( ! $this->is_phpspreadsheet_available() ) {
			wp_die(
				wp_kses_post(
					sprintf(
						/* translators: %s: composer command */
						__( 'The Excel export feature requires the PhpSpreadsheet library. Please run %s in the plugin directory, or use the CSV export instead.', 'music-club-registrations' ),
						'<code>composer require phpoffice/phpspreadsheet</code>'
					)
				),
				esc_html__( 'Missing dependency', 'music-club-registrations' ),
				array( 'back_link' => true )
			);
		}

		require_once MCR_PLUGIN_DIR . 'vendor/autoload.php';

		$items   = $this->resolve_export_items();
		$columns = $this->resolve_selected_columns();

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Registrations' );

		$col_index = 1;
		foreach ( array_values( $columns ) as $header ) {
			$sheet->setCellValueByColumnAndRow( $col_index, 1, $header );
			++$col_index;
		}
		$sheet->getStyle( 1, 1, $col_index - 1, 1 )->getFont()->setBold( true );

		$row_index = 2;
		foreach ( $items as $item ) {
			$col_index = 1;
			foreach ( $this->build_row( $item, $columns ) as $value ) {
				$sheet->setCellValueByColumnAndRow( $col_index, $row_index, $value );
				++$col_index;
			}
			++$row_index;
		}

		foreach ( range( 1, count( $columns ) ) as $col ) {
			$sheet->getColumnDimensionByColumn( $col )->setAutoSize( true );
		}

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename=registrations-' . gmdate( 'Y-m-d-His' ) . '.xlsx' );
		header( 'Cache-Control: max-age=0' );

		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
		$writer->save( 'php://output' );

		Logger::info(
			'export',
			sprintf( 'Excel export generated with %d record(s) and %d column(s).', count( $items ), count( $columns ) ),
			get_current_user_id()
		);

		exit;
	}

	/**
	 * Monta uma linha de exportação (CSV ou Excel) a partir de um registro
	 * e do conjunto de colunas selecionadas.
	 *
	 * @param array $item    Registro de inscrição.
	 * @param array $columns Colunas selecionadas (slug => rótulo).
	 * @return array<int,string>
	 */
	private function build_row( array $item, array $columns ) {
		$row = array();

		foreach ( array_keys( $columns ) as $key ) {
			if ( 'status' === $key ) {
				$row[] = mcr_get_status_label( $item['status'] );
			} elseif ( 'created_at' === $key ) {
				$row[] = mysql2date( 'Y-m-d H:i:s', $item['created_at'] );
			} elseif ( 'interests' === $key ) {
				$row[] = implode( ', ', mcr_interests_to_array( $item['interests'] ) );
			} else {
				$row[] = $item[ $key ] ?? '';
			}
		}

		return $row;
	}

	/**
	 * Verifica se a biblioteca PhpSpreadsheet está disponível no plugin.
	 *
	 * @return bool
	 */
	private function is_phpspreadsheet_available() {
		if ( class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			return true;
		}

		$autoload = MCR_PLUGIN_DIR . 'vendor/autoload.php';

		return file_exists( $autoload );
	}
}
