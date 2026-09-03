( function () {
	'use strict';

	if ( typeof window.wpfvAdmin === 'undefined' ) {
		return;
	}

	document.querySelectorAll( '.wpfv-nav-tab' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			const target = tab.getAttribute( 'href' );
			document.querySelectorAll( '.wpfv-nav-tab' ).forEach( function ( item ) {
				item.classList.toggle( 'nav-tab-active', item === tab );
			} );
			document.querySelectorAll( '.wpfv-tab-panel' ).forEach( function ( panel ) {
				panel.hidden = '#' + panel.id !== target;
			} );
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', target );
			}
		} );
	} );

	if ( window.location.hash ) {
		const currentTab = document.querySelector( '.wpfv-nav-tab[href="' + window.location.hash + '"]' );
		if ( currentTab ) {
			currentTab.click();
		}
	}

	document.querySelectorAll( '.wpfv-field-mapping' ).forEach( function ( mapping ) {
		const body = mapping.querySelector( 'tbody' );
		const template = mapping.querySelector( '.wpfv-mapping-template' );
		const addButton = mapping.querySelector( '.wpfv-add-mapping' );

		if ( ! body || ! template || ! addButton ) {
			return;
		}

		addButton.addEventListener( 'click', function () {
			const row = template.content.firstElementChild.cloneNode( true );
			body.appendChild( row );
			row.querySelector( '.wpfv-mapping-source' ).focus();
		} );

		mapping.addEventListener( 'click', function ( event ) {
			const removeButton = event.target.closest( '.wpfv-remove-mapping' );
			if ( ! removeButton ) {
				return;
			}
			const row = removeButton.closest( 'tr' );
			if ( row ) {
				row.remove();
			}
			if ( ! body.children.length ) {
				addButton.click();
			}
		} );
	} );

	function requestCatalog( catalog, extra ) {
		const body = new URLSearchParams( Object.assign( {
			action: 'wpfv_pipedrive_catalog',
			_ajax_nonce: window.wpfvAdmin.nonce,
			catalog: catalog
		}, extra || {} ) );

		return fetch( window.wpfvAdmin.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( response ) {
			if ( ! response.success ) {
				throw new Error( response.data && response.data.message ? response.data.message : 'Falha ao consultar o Pipedrive.' );
			}
			return response.data.items || [];
		} );
	}

	function fillSelect( select, items, placeholder ) {
		const selected = String( select.dataset.selected || select.value || '' );
		select.innerHTML = '';
		select.appendChild( new Option( placeholder, '' ) );
		items.forEach( function ( item ) {
			const option = new Option( item.label, item.value );
			option.selected = String( item.value ) === selected || String( item.legacy_value || '' ) === selected;
			select.appendChild( option );
		} );
	}

	function setStatus( message, isError ) {
		const status = document.getElementById( 'wpfv-pipedrive-catalog-status' );
		if ( status ) {
			status.textContent = message || '';
			status.style.color = isError ? '#b32d2e' : '';
		}
	}

	const pipeline = document.getElementById( 'wpfv-pipeline' );
	const stage = document.getElementById( 'wpfv-stage' );
	const fieldCache = {};
	const fieldRequests = {};

	function loadStages() {
		if ( ! pipeline || ! stage || ! pipeline.value ) {
			fillSelect( stage, [], '— Selecione o pipeline —' );
			return Promise.resolve();
		}
		stage.disabled = true;
		return requestCatalog( 'stages', { pipeline_id: pipeline.value } ).then( function ( items ) {
			fillSelect( stage, items, '— Selecione a etapa —' );
			stage.disabled = false;
		} ).catch( function ( error ) {
			stage.disabled = false;
			setStatus( error.message, true );
		} );
	}

	function loadFields( entity ) {
		if ( fieldCache[ entity ] ) {
			return Promise.resolve( fieldCache[ entity ] );
		}
		if ( fieldRequests[ entity ] ) {
			return fieldRequests[ entity ];
		}
		fieldRequests[ entity ] = requestCatalog( 'fields', { entity: entity } ).then( function ( items ) {
			fieldCache[ entity ] = items;
			delete fieldRequests[ entity ];
			return items;
		} ).catch( function ( error ) {
			delete fieldRequests[ entity ];
			throw error;
		} );
		return fieldRequests[ entity ];
	}

	function populateFieldRow( entitySelect ) {
		const target = entitySelect.closest( 'tr' ).querySelector( '.wpfv-pd-target' );
		if ( ! target ) {
			return;
		}
		target.disabled = true;
		loadFields( entitySelect.value ).then( function ( items ) {
			fillSelect( target, items, '— Selecione o campo —' );
			target.disabled = false;
		} ).catch( function ( error ) {
			target.disabled = false;
			setStatus( error.message, true );
		} );
	}

	if ( pipeline && stage ) {
		pipeline.addEventListener( 'change', function () {
			stage.dataset.selected = '';
			loadStages();
		} );

		requestCatalog( 'pipelines' ).then( function ( items ) {
			fillSelect( pipeline, items, '— Selecione o pipeline —' );
			setStatus( 'Pipelines, etapas e campos carregados diretamente do Pipedrive.', false );
			return loadStages();
		} ).catch( function ( error ) {
			setStatus( error.message, true );
		} );

		document.querySelectorAll( '.wpfv-pd-entity' ).forEach( function ( entitySelect ) {
			entitySelect.addEventListener( 'change', function () {
				const target = entitySelect.closest( 'tr' ).querySelector( '.wpfv-pd-target' );
				if ( target ) {
					target.dataset.selected = '';
				}
				populateFieldRow( entitySelect );
			} );
			populateFieldRow( entitySelect );
		} );
	}

	function requestRdCatalog( catalog, extra ) {
		const body = new URLSearchParams( Object.assign( { action: 'wpfv_rdcrm_catalog', _ajax_nonce: window.wpfvAdmin.nonce, catalog: catalog }, extra || {} ) );
		return fetch( window.wpfvAdmin.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString(), credentials: 'same-origin' } ).then( function ( response ) { return response.json(); } ).then( function ( response ) { if ( ! response.success ) { throw new Error( response.data && response.data.message ? response.data.message : 'Falha ao consultar o RD CRM.' ); } return response.data.items || []; } );
	}
	const rdPipeline = document.getElementById( 'wpfv-rdcrm-pipeline' );
	const rdStage = document.getElementById( 'wpfv-rdcrm-stage' );
	const rdOwner = document.getElementById( 'wpfv-rdcrm-owner' );
	const rdStatus = document.getElementById( 'wpfv-rdcrm-catalog-status' );
	function rdMessage( message, error ) { if ( rdStatus ) { rdStatus.textContent = message; rdStatus.style.color = error ? '#b32d2e' : ''; } }
	function loadRdStages() { if ( ! rdPipeline.value ) { fillSelect( rdStage, [], '— Selecione o funil —' ); return Promise.resolve(); } rdStage.disabled = true; return requestRdCatalog( 'stages', { pipeline_id: rdPipeline.value } ).then( function ( items ) { fillSelect( rdStage, items, '— Selecione a etapa —' ); rdStage.disabled = false; } ); }
	if ( rdPipeline && rdStage && rdOwner ) {
		rdPipeline.addEventListener( 'change', function () { rdStage.dataset.selected = ''; loadRdStages().catch( function ( error ) { rdStage.disabled = false; rdMessage( error.message, true ); } ); } );
		Promise.all( [ requestRdCatalog( 'pipelines' ), requestRdCatalog( 'users' ) ] ).then( function ( catalogs ) { fillSelect( rdPipeline, catalogs[0], '— Selecione o funil —' ); fillSelect( rdOwner, catalogs[1], '— Selecione o responsável —' ); return loadRdStages(); } ).then( function () { rdMessage( 'Funis, etapas e responsáveis carregados diretamente do RD CRM.', false ); } ).catch( function ( error ) { rdMessage( error.message, true ); } );
		const rdFields = {};
		function loadRdFieldRow( entitySelect ) { const target = entitySelect.closest( 'tr' ).querySelector( '.wpfv-rd-target' ); const entity = entitySelect.value; target.disabled = true; const request = rdFields[entity] ? Promise.resolve( rdFields[entity] ) : requestRdCatalog( 'fields', { entity: entity } ).then( function ( items ) { rdFields[entity] = items; return items; } ); request.then( function ( items ) { fillSelect( target, items, '— Selecione o campo —' ); target.disabled = false; } ).catch( function ( error ) { target.disabled = false; rdMessage( error.message, true ); } ); }
		function bindRdRow( entitySelect ) { entitySelect.addEventListener( 'change', function () { const target = entitySelect.closest( 'tr' ).querySelector( '.wpfv-rd-target' ); target.dataset.selected = ''; loadRdFieldRow( entitySelect ); } ); loadRdFieldRow( entitySelect ); }
		document.querySelectorAll( '.wpfv-rd-entity' ).forEach( bindRdRow );
		const rdMapping = document.querySelector( '.wpfv-rd-mapping' ); if ( rdMapping ) { const body = rdMapping.querySelector( 'tbody' ); const template = rdMapping.querySelector( 'template' ); rdMapping.querySelector( '.wpfv-add-rd-mapping' ).addEventListener( 'click', function () { const row = template.content.firstElementChild.cloneNode( true ); body.appendChild( row ); bindRdRow( row.querySelector( '.wpfv-rd-entity' ) ); row.querySelector( '.wpfv-rd-source' ).focus(); } ); rdMapping.addEventListener( 'click', function ( event ) { const button = event.target.closest( '.wpfv-remove-rd-mapping' ); if ( button ) { button.closest( 'tr' ).remove(); if ( ! body.children.length ) rdMapping.querySelector( '.wpfv-add-rd-mapping' ).click(); } } ); }
	}
}() );
