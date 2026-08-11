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

	/**
	 * Sistema simples de notificações "toast", usado para dar feedback
	 * amigável após ações do administrador (ex: importação de backup).
	 */
	function mcrShowToast( message, type ) {
		var $container = $( '#mcr-toast-container' );

		if ( ! $container.length ) {
			$container = $( '<div>', { id: 'mcr-toast-container' } ).appendTo( 'body' );
		}

		var $toast = $( '<div>', {
			class: 'mcr-toast mcr-toast-' + ( type || 'info' ),
			text: message,
		} ).appendTo( $container );

		window.setTimeout( function () {
			$toast.addClass( 'is-visible' );
		}, 10 );

		window.setTimeout( function () {
			$toast.removeClass( 'is-visible' );
			window.setTimeout( function () {
				$toast.remove();
			}, 250 );
		}, 4000 );
	}
	window.mcrShowToast = mcrShowToast;

	// Exibe um toast de sucesso automaticamente quando a URL contém um
	// parâmetro de confirmação conhecido (ex: depois de um redirect).
	$( function () {
		var params = new window.URLSearchParams( window.location.search );

		if ( params.get( 'updated' ) ) {
			mcrShowToast( ( window.MCRAdmin && window.MCRAdmin.toastSaved ) || 'Saved successfully.', 'success' );
		}
		if ( params.get( 'deleted' ) ) {
			mcrShowToast( ( window.MCRAdmin && window.MCRAdmin.toastDeleted ) || 'Deleted successfully.', 'success' );
		}
		if ( params.get( 'setup_complete' ) ) {
			mcrShowToast( ( window.MCRAdmin && window.MCRAdmin.toastSetupComplete ) || 'Setup complete! The plugin is ready.', 'success' );
		}
	} );

	// Mostra um indicador de carregamento nos botões de exportação e
	// sincronização, para que o administrador saiba que a ação está em
	// andamento (arquivos grandes podem levar alguns segundos a gerar).
	$( document ).on( 'click', '.mcr-export-panel button[type="submit"], .mcr-test-connection-btn, .mcr-sync-now-btn, .mcr-ms-connect-btn, #mcr-selected-export-form button[type="submit"]', function () {
		var $button = $( this );
		window.setTimeout( function () {
			$button.addClass( 'mcr-is-loading' );
		}, 0 );
		// A navegação do navegador para o download/redirecionamento
		// substitui a página, então o estado é revertido naturalmente.
	} );

	/**
	 * Excel Online: seleção em cascata de Workbook → Worksheet → Table.
	 *
	 * O <select> de workbook combina drive_id/item_id/nome em um único
	 * "value" (separados por "|"), decompostos aqui em campos ocultos
	 * antes do envio automático do formulário - assim a página recarrega
	 * já mostrando a próxima etapa (worksheets), preenchida com dados
	 * reais lidos da Microsoft Graph API no servidor.
	 */
	$( document ).on( 'change', '#mcr-ms-workbook', function () {
		var parts = $( this ).val().split( '|' );

		if ( parts.length < 3 ) {
			return;
		}

		var $form = $( this ).closest( 'form' );
		$form.find( '.mcr-ms-hidden-drive' ).val( parts[ 0 ] );
		$form.find( '.mcr-ms-hidden-item' ).val( parts[ 1 ] );
		$form.find( '.mcr-ms-hidden-name' ).val( parts.slice( 2 ).join( '|' ) );
		$form.trigger( 'submit' );
	} );

	$( document ).on( 'change', '#mcr-ms-worksheet', function () {
		$( this ).closest( 'form' ).trigger( 'submit' );
	} );

	$( document ).on( 'change', '#mcr-ms-table', function () {
		var parts = $( this ).val().split( '|' );

		if ( parts.length < 2 ) {
			return;
		}

		var $form = $( this ).closest( 'form' );
		$form.find( '.mcr-ms-hidden-table-id' ).val( parts[ 0 ] );
		$form.find( '.mcr-ms-hidden-table-name' ).val( parts.slice( 1 ).join( '|' ) );
		$form.trigger( 'submit' );
	} );

} )( jQuery );
