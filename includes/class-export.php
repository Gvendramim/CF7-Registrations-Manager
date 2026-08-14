<?php
/**
 * Classe responsável pela exportação de inscrições em CSV e Excel (.xlsx).
 *
 * A exportação em Excel utiliza um gerador nativo de arquivos .xlsx
 * (Xlsx_Writer), sem nenhuma dependência externa via Composer - o
 * download funciona imediatamente após a ativação do plugin.
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
	 * requisições de exportação a partir do painel administrativo.
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
	 * Verifica permissões e nonce antes de processar uma exportação
	 * disparada a partir do painel administrativo.
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
			'child_name'           => __( "Child's Name", 'music-club-registrations' ),
			'child_age'            => __( "Child's Age", 'music-club-registrations' ),
			'parent_name'          => __( 'Parent/Guardian', 'music-club-registrations' ),
			'second_parent_name'   => __( 'Parent/Guardian (Additional)', 'music-club-registrations' ),
			'parent_email'         => __( 'Primary Email', 'music-club-registrations' ),
			'phone'                => __( 'Primary Phone', 'music-club-registrations' ),
			'second_parent_email'  => __( 'Additional Email', 'music-club-registrations' ),
			'second_parent_phone'  => __( 'Additional Phone', 'music-club-registrations' ),
			'child_class'          => __( 'Class', 'music-club-registrations' ),
			'interests'            => __( 'Interests', 'music-club-registrations' ),
			'additional_message'   => __( 'Additional Message', 'music-club-registrations' ),
			'status'               => __( 'Status', 'music-club-registrations' ),
			'created_at'           => __( 'Created At', 'music-club-registrations' ),
		);
	}

	/**
	 * -----------------------------------------------------------------------
	 * Nota sobre compatibilidade
	 * -----------------------------------------------------------------------
	 * Nenhuma coluna existente foi removida ao adicionar os novos campos
	 * (child_age, second_parent_email, second_parent_phone) - apenas
	 * inseridas nas posições mais lógicas. Isso preserva qualquer
	 * mapeamento já configurado na integração com o Excel Online (a
	 * correspondência é feita pelo NOME da coluna do Excel, não pela
	 * ordem/posição desta lista).
	 */

	/**
	 * Resolve as colunas selecionadas pelo administrador antes da geração
	 * do arquivo. Caso nenhuma coluna tenha sido explicitamente enviada
	 * (ex: link de exportação rápida ou chamada via API), todas as colunas
	 * são utilizadas.
	 *
	 * @param array|null $requested_keys Lista de colunas solicitadas, ou null para ler de $_REQUEST.
	 * @return array<string,string>
	 */
	public static function resolve_columns( $requested_keys = null ) {
		$all = self::get_all_columns();

		if ( null === $requested_keys ) {
			if ( empty( $_REQUEST['columns'] ) || ! is_array( $_REQUEST['columns'] ) ) {
				return $all;
			}
			$requested_keys = wp_unslash( $_REQUEST['columns'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		$requested = array_map( 'sanitize_key', (array) $requested_keys );
		$selected  = array();

		// Preserva a ordem padrão das colunas, filtrando apenas as
		// efetivamente marcadas.
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
	 * Detecta automaticamente o delimitador de CSV mais adequado, de
	 * acordo com o idioma configurado no WordPress. Locais que usam
	 * vírgula como separador decimal (Brasil, Portugal, Alemanha, França,
	 * Espanha, Itália etc.) utilizam ponto e vírgula, evitando que o
	 * Excel local exiba tudo em uma única coluna. Locais como en_US
	 * utilizam a vírgula tradicional.
	 *
	 * @return string
	 */
	public static function detect_csv_delimiter() {
		$locale = get_locale();

		$semicolon_locales = array(
			'pt_BR', 'pt_PT', 'es_ES', 'es_MX', 'es_AR', 'de_DE', 'de_AT', 'de_CH',
			'fr_FR', 'fr_CA', 'it_IT', 'nl_NL', 'pl_PL', 'ru_RU', 'sv_SE', 'da_DK',
			'fi', 'nb_NO', 'cs_CZ', 'tr_TR', 'ro_RO', 'hu_HU', 'el', 'uk',
		);

		$delimiter = in_array( $locale, $semicolon_locales, true ) ? ';' : ',';

		/**
		 * Filtra o delimitador de CSV utilizado na exportação.
		 *
		 * @param string $delimiter Delimitador detectado automaticamente.
		 * @param string $locale    Locale atual do WordPress.
		 */
		return apply_filters( 'mcr_csv_delimiter', $delimiter, $locale );
	}

	/**
	 * Monta o conteúdo completo de um arquivo CSV (com BOM UTF-8 e
	 * delimitador detectado automaticamente), pronto para download.
	 *
	 * @param array  $items     Registros a exportar.
	 * @param array  $columns   Colunas selecionadas (slug => rótulo).
	 * @param string $delimiter Delimitador de campos.
	 * @return string
	 */
	public static function build_csv_content( array $items, array $columns, $delimiter = null ) {
		if ( null === $delimiter ) {
			$delimiter = self::detect_csv_delimiter();
		}

		$handle = fopen( 'php://temp', 'w+' );

		// BOM UTF-8: garante acentuação correta no Excel Windows, Excel
		// Online e LibreOffice.
		fwrite( $handle, "\xEF\xBB\xBF" );

		fputcsv( $handle, array_values( $columns ), $delimiter );

		foreach ( $items as $item ) {
			fputcsv( $handle, self::build_row( $item, $columns ), $delimiter );
		}

		rewind( $handle );
		$content = stream_get_contents( $handle );
		fclose( $handle );

		return $content;
	}

	/**
	 * Gera um arquivo .xlsx a partir dos registros e colunas informados,
	 * utilizando o gerador nativo Xlsx_Writer (sem dependências externas).
	 *
	 * @param array  $items      Registros a exportar.
	 * @param array  $columns    Colunas selecionadas (slug => rótulo).
	 * @param string $sheet_name Nome da aba.
	 * @return string|false Caminho de um arquivo temporário com o .xlsx gerado, ou false em caso de falha.
	 */
	public static function build_xlsx_file( array $items, array $columns, $sheet_name = 'Registrations' ) {
		if ( ! Xlsx_Writer::is_supported() ) {
			return false;
		}

		$writer = new Xlsx_Writer();
		$writer->set_sheet_name( $sheet_name );
		$writer->set_headers( array_values( $columns ) );

		foreach ( $items as $item ) {
			$writer->add_row( self::build_row( $item, $columns ) );
		}

		$tmp_file = wp_tempnam( 'mcr-export.xlsx' );

		if ( ! $writer->save( $tmp_file ) ) {
			return false;
		}

		return $tmp_file;
	}

	/**
	 * Processa e envia um arquivo CSV para download a partir do painel
	 * administrativo.
	 *
	 * @return void
	 */
	public function handle_export_csv() {
		$this->verify_export_request();

		$items   = $this->resolve_export_items();
		$columns = self::resolve_columns();
		$content = self::build_csv_content( $items, $columns );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=registrations-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		header( 'Content-Length: ' . strlen( $content ) );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary/CSV file content, not HTML.

		Logger::info(
			'export',
			sprintf( 'CSV export generated with %d record(s) and %d column(s).', count( $items ), count( $columns ) ),
			get_current_user_id()
		);

		exit;
	}

	/**
	 * Processa e envia um arquivo Excel (.xlsx) para download a partir do
	 * painel administrativo.
	 *
	 * Utiliza o gerador nativo Xlsx_Writer - nenhuma biblioteca externa,
	 * Composer ou instalação manual é necessária. Caso o ambiente não
	 * possua a extensão ZipArchive (extremamente raro), o usuário recebe
	 * um aviso amigável e é redirecionado de volta, nunca um erro fatal.
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

		if ( ! Xlsx_Writer::is_supported() ) {
			Logger::warning( 'export', 'Excel export attempted but the ZipArchive PHP extension is not available on this server.', get_current_user_id() );

			wp_die(
				esc_html__( 'Excel export is not available on this server because the ZipArchive PHP extension is missing. Please ask your hosting provider to enable it, or use the CSV export instead - no other action is required.', 'music-club-registrations' ),
				esc_html__( 'Excel export unavailable', 'music-club-registrations' ),
				array( 'back_link' => true )
			);
		}

		$items   = $this->resolve_export_items();
		$columns = self::resolve_columns();

		$tmp_file = self::build_xlsx_file( $items, $columns );

		if ( ! $tmp_file ) {
			Logger::error( 'export', 'Failed to generate the Excel export file.', get_current_user_id() );

			wp_die(
				esc_html__( 'Something went wrong while generating the Excel file. Please try again or use the CSV export instead.', 'music-club-registrations' ),
				esc_html__( 'Export failed', 'music-club-registrations' ),
				array( 'back_link' => true )
			);
		}

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename=registrations-' . gmdate( 'Y-m-d-His' ) . '.xlsx' );
		header( 'Content-Length: ' . filesize( $tmp_file ) );
		header( 'Cache-Control: max-age=0' );

		readfile( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a temporary export file for direct download.
		wp_delete_file( $tmp_file );

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
	public static function build_row( array $item, array $columns ) {
		$row = array();

		foreach ( array_keys( $columns ) as $key ) {
			if ( 'status' === $key ) {
				$row[] = mcr_get_status_label( $item['status'] );
			} elseif ( 'created_at' === $key ) {
				$row[] = mysql2date( 'Y-m-d H:i:s', $item['created_at'] );
			} elseif ( 'interests' === $key ) {
				// Separador "; " (ponto-e-vírgula) em vez de vírgula: como
				// o próprio delimitador de CSV pode ser uma vírgula
				// (dependendo do idioma), usar "; " evita qualquer
				// ambiguidade visual entre múltiplos interesses e colunas,
				// e é o formato usado como referência no exemplo desta
				// funcionalidade.
				$row[] = implode( '; ', mcr_interests_to_array( $item['interests'] ) );
			} else {
				$row[] = $item[ $key ] ?? '';
			}
		}

		return $row;
	}
}
