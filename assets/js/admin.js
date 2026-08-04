/**
 * Comportamentos administrativos do plugin Music Club Registrations.
 */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.mcr-delete-link', function ( event ) {
		var message = $( this ).data( 'confirm' ) ||
			( window.MCRAdmin && window.MCRAdmin.confirmDelete ) ||
			'Are you sure?';

		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	$( document ).on( 'click', '#doaction, #doaction2', function ( event ) {
		var $form = $( this ).closest( 'form' );
		var action = $form.find( 'select[name="action"]' ).val();
		var action2 = $form.find( 'select[name="action2"]' ).val();
		var selectedAction = ( action && action !== '-1' ) ? action : action2;

		if ( ! selectedAction || selectedAction === '-1' ) {
			return;
		}

		var checked = $form.find( 'input[name="registration_ids[]"]:checked' ).length;

		if ( checked === 0 ) {
			return;
		}

		var message = ( window.MCRAdmin && window.MCRAdmin.confirmBulk ) || 'Are you sure?';

		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	// Sincroniza os IDs selecionados na listagem com o formulário de
	// exportação "Selected", permitindo exportar apenas as linhas marcadas.
	$( document ).on( 'submit', '#mcr-selected-export-form', function () {
		var $form = $( this );
		var $listForm = $( '#mcr-search' ).closest( 'form' );

		$form.find( 'input[name="registration_ids[]"]' ).remove();

		$listForm.find( 'input[name="registration_ids[]"]:checked' ).each( function () {
			$form.append(
				$( '<input>' ).attr( {
					type: 'hidden',
					name: 'registration_ids[]',
					value: $( this ).val(),
				} )
			);
		} );
	} );
	$( document ).on( 'click', '.mcr-clear-logs-btn', function ( event ) {
		var message = ( window.MCRAdmin && window.MCRAdmin.confirmClearLogs ) || 'Are you sure?';

		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	// Facilita a pré-visualização dos campos: ao trocar o formulário
	// selecionado na tela de Settings, aciona automaticamente o botão
	// "Load Fields" (o próprio botão continua funcionando manualmente).
	$( document ).on( 'change', '#mcr-form-id', function () {
		$( '#mcr-load-fields-btn' ).trigger( 'click' );
	} );

} )( jQuery );
