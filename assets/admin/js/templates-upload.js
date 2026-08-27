/**
 * Templates screen: client-side PDF sanitise + upload, and delete actions.
 *
 * Before upload the PDF is rewritten with pdf-lib:
 *  - document OpenAction and the JavaScript name tree are removed
 *  - /AA (additional actions) are stripped from the catalog, pages and fields
 *  - the file is saved without object streams so the server-side FPDI parser
 *    (free variant) can import it for generation.
 *
 * @package FFPM
 */

/* global PDFLib, ffpmTemplates */
( function () {
	'use strict';

	var form = document.getElementById( 'ffpm-upload-form' );
	var statusEl = document.getElementById( 'ffpm-upload-status' );

	function setStatus( text, isError ) {
		if ( statusEl ) {
			statusEl.textContent = text || '';
			statusEl.style.color = isError ? '#b32d2e' : 'inherit';
		}
	}

	var STRIP_KEYS = [ 'AA', 'OpenAction', 'JS', 'JavaScript', 'EmbeddedFiles', 'XFA' ];
	var ACTION_SUBTYPES = [ 'JavaScript', 'Launch', 'Rendition' ];

	/**
	 * Recursively delete dangerous keys from every reachable dict/array.
	 */
	function walk( node, seen ) {
		if ( ! node || seen.has( node ) ) {
			return;
		}
		seen.add( node );

		var dict = node instanceof PDFLib.PDFStream ? node.dict : node;

		if ( dict instanceof PDFLib.PDFDict ) {
			STRIP_KEYS.forEach( function ( key ) {
				var name = PDFLib.PDFName.of( key );
				if ( dict.has( name ) ) {
					dict.delete( name );
				}
			} );
			dict.entries().forEach( function ( entry ) {
				walk( entry[ 1 ], seen );
			} );
		} else if ( node instanceof PDFLib.PDFArray ) {
			for ( var i = 0; i < node.size(); i++ ) {
				walk( node.get( i ), seen );
			}
		}
	}

	/**
	 * Strip active content from a loaded PDFDocument (in place).
	 *
	 * Two passes: script/launch action objects are deleted from the document
	 * entirely (an orphaned object would still be serialised on save), then
	 * every remaining dict is walked and dangerous keys removed wherever they
	 * appear — catalog, pages, annotations, AcroForm fields alike.
	 */
	function sanitiseDocument( doc ) {
		var seen = new Set();
		var subtypeKey = PDFLib.PDFName.of( 'S' );
		var toDelete = [];

		doc.context.enumerateIndirectObjects().forEach( function ( entry ) {
			var obj = entry[ 1 ];
			var dict = obj instanceof PDFLib.PDFStream ? obj.dict : obj;

			if ( dict instanceof PDFLib.PDFDict ) {
				var subtype = dict.get( subtypeKey );
				var isDangerousAction = ACTION_SUBTYPES.some( function ( name ) {
					return subtype === PDFLib.PDFName.of( name );
				} );
				if ( isDangerousAction ) {
					toDelete.push( entry[ 0 ] );
					return;
				}
			}

			walk( obj, seen );
		} );

		toDelete.forEach( function ( ref ) {
			doc.context.delete( ref );
		} );
	}

	function sanitiseAndUpload( file, title ) {
		setStatus( ffpmTemplates.i18n.sanitising, false );

		return file
			.arrayBuffer()
			.then( function ( buffer ) {
				return PDFLib.PDFDocument.load( buffer, { ignoreEncryption: false } );
			} )
			.then( function ( doc ) {
				sanitiseDocument( doc );
				return doc.save( { useObjectStreams: false } );
			} )
			.then( function ( bytes ) {
				setStatus( ffpmTemplates.i18n.uploading, false );

				var data = new FormData();
				data.append( 'action', 'ffpm_upload_template' );
				data.append( '_ajax_nonce', ffpmTemplates.nonce );
				data.append( 'title', title );
				data.append( 'file', new Blob( [ bytes ], { type: 'application/pdf' } ), file.name );

				return fetch( ffpmTemplates.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } );
			} )
			.then( function ( response ) {
				return response.json();
			} );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var fileInput = document.getElementById( 'ffpm-template-file' );
			var titleInput = document.getElementById( 'ffpm-template-title' );
			var file = fileInput && fileInput.files ? fileInput.files[ 0 ] : null;

			if ( ! file || ! titleInput || ! titleInput.value.trim() ) {
				return;
			}

			var submit = form.querySelector( 'button[type="submit"]' );
			if ( submit ) {
				submit.disabled = true;
			}

			sanitiseAndUpload( file, titleInput.value.trim() )
				.then( function ( json ) {
					if ( json && json.success ) {
						window.location.reload();
						return;
					}
					var message = json && json.data && json.data.message ? json.data.message : ffpmTemplates.i18n.uploadFailed;
					setStatus( message, true );
				} )
				.catch( function ( error ) {
					var message = ffpmTemplates.i18n.invalid;
					if ( error && /encrypted/i.test( String( error.message || '' ) ) ) {
						message = ffpmTemplates.i18n.encrypted;
					}
					setStatus( message, true );
				} )
				.finally( function () {
					if ( submit ) {
						submit.disabled = false;
					}
				} );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.ffpm-delete-template' );
		if ( ! button ) {
			return;
		}

		if ( ! window.confirm( ffpmTemplates.i18n.confirmDel ) ) {
			return;
		}

		var data = new FormData();
		data.append( 'action', 'ffpm_delete_template' );
		data.append( '_ajax_nonce', ffpmTemplates.nonce );
		data.append( 'id', button.getAttribute( 'data-id' ) );

		fetch( ffpmTemplates.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					window.location.reload();
				}
			} );
	} );
} )();
