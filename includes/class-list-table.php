<?php
/**
 * Tabela de listagem das inscrições do Music Club, baseada em WP_List_Table.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class List_Table
 *
 * Responsabilidade única: renderizar a listagem administrativa de inscrições,
 * com busca, paginação, ordenação, filtros e ações em massa.
 */
class List_Table extends \WP_List_Table {

	/**
	 * Construtor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'registration',
				'plural'   => 'registrations',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define as colunas da tabela.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'                   => '<input type="checkbox" />',
			'registration_number' => __( 'Registration Number', 'music-club-registrations' ),
			'child_name'           => __( 'Child', 'music-club-registrations' ),
			'parent_name'          => __( 'Parent', 'music-club-registrations' ),
			'parent_email'         => __( 'Email', 'music-club-registrations' ),
			'phone'                => __( 'Phone', 'music-club-registrations' ),
			'child_class'          => __( 'Class', 'music-club-registrations' ),
			'interests'            => __( 'Interests', 'music-club-registrations' ),
			'created_at'           => __( 'Date', 'music-club-registrations' ),
			'status'               => __( 'Status', 'music-club-registrations' ),
		);
	}

	/**
	 * Define quais colunas são ordenáveis.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'registration_number' => array( 'registration_number', false ),
			'child_name'           => array( 'child_name', false ),
			'parent_name'          => array( 'parent_name', false ),
			'parent_email'         => array( 'parent_email', false ),
			'child_class'          => array( 'child_class', false ),
			'created_at'           => array( 'created_at', true ),
			'status'               => array( 'status', false ),
		);
	}

	/**
	 * Define as ações em massa disponíveis.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'mark_contacted' => __( 'Mark as Contacted', 'music-club-registrations' ),
			'mark_confirmed' => __( 'Mark as Confirmed', 'music-club-registrations' ),
			'mark_cancelled' => __( 'Mark as Cancelled', 'music-club-registrations' ),
			'delete'         => __( 'Delete', 'music-club-registrations' ),
		);
	}

	/**
	 * Renderiza a coluna de checkbox para ações em massa.
	 *
	 * @param array $item Linha atual.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="registration_ids[]" value="%d" />', absint( $item['id'] ) );
	}

	/**
	 * Renderiza o conteúdo padrão de uma coluna.
	 *
	 * @param array  $item        Linha atual.
	 * @param string $column_name Nome da coluna.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'parent_email':
				return esc_html( $item['parent_email'] );
			case 'phone':
				return esc_html( $item['phone'] );
			case 'child_class':
				return esc_html( $item['child_class'] );
			case 'created_at':
				return esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] ) );
			default:
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
		}
	}

	/**
	 * Renderiza a coluna de número de inscrição com link para a tela de detalhes.
	 *
	 * @param array $item Linha atual.
	 * @return string
	 */
	protected function column_registration_number( $item ) {
		$detail_url = add_query_arg(
			array(
				'page' => Admin::DETAIL_SLUG,
				'id'   => absint( $item['id'] ),
			),
			admin_url( 'admin.php' )
		);

		$actions = array(
			'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $detail_url ), esc_html__( 'View', 'music-club-registrations' ) ),
			'delete' => sprintf(
				'<a href="%s" class="mcr-delete-link" data-confirm="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'   => Admin::LIST_SLUG,
								'action' => 'delete',
								'id'     => absint( $item['id'] ),
							),
							admin_url( 'admin.php' )
						),
						'mcr_delete_registration_' . absint( $item['id'] )
					)
				),
				esc_attr__( 'Are you sure you want to delete this registration?', 'music-club-registrations' ),
				esc_html__( 'Delete', 'music-club-registrations' )
			),
		);

		return sprintf(
			'<a href="%1$s"><strong>%2$s</strong></a>%3$s',
			esc_url( $detail_url ),
			esc_html( $item['registration_number'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Renderiza a coluna de nome da criança, servindo como principal link
	 * de navegação da linha.
	 *
	 * @param array $item Linha atual.
	 * @return string
	 */
	protected function column_child_name( $item ) {
		return esc_html( $item['child_name'] );
	}

	/**
	 * Renderiza a coluna de responsável, combinando nome do responsável.
	 *
	 * @param array $item Linha atual.
	 * @return string
	 */
	protected function column_parent_name( $item ) {
		return esc_html( $item['parent_name'] );
	}

	/**
	 * Renderiza a coluna de interesses como uma lista compacta.
	 *
	 * @param array $item Linha atual.
	 * @return string
	 */
	protected function column_interests( $item ) {
		$interests = mcr_interests_to_array( $item['interests'] );

		if ( empty( $interests ) ) {
			return '&mdash;';
		}

		return esc_html( implode( ', ', $interests ) );
	}

	/**
	 * Renderiza a coluna de status como um "badge" colorido.
	 *
	 * @param array $item Linha atual.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = $item['status'];

		return sprintf(
			'<span class="mcr-status-badge %1$s">%2$s</span>',
			esc_attr( mcr_get_status_css_class( $status ) ),
			esc_html( mcr_get_status_label( $status ) )
		);
	}

	/**
	 * Exibe os filtros extras (por status) acima da tabela.
	 *
	 * @param string $which 'top' ou 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
		?>
		<div class="alignleft actions">
			<label for="mcr-filter-status" class="screen-reader-text">
				<?php esc_html_e( 'Filter by status', 'music-club-registrations' ); ?>
			</label>
			<select name="status" id="mcr-filter-status">
				<option value=""><?php esc_html_e( 'All statuses', 'music-club-registrations' ); ?></option>
				<?php foreach ( mcr_get_statuses() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_status, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'music-club-registrations' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Prepara os itens da tabela: busca, filtros, ordenação e paginação.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'DESC';

		$result = Database::query_registrations(
			array(
				'search'   => $search,
				'status'   => $status,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'page'     => $current_page,
			)
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}
}
