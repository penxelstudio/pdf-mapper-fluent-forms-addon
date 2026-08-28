/**
 * Visual field mapper.
 *
 * Renders the template PDF with PDF.js, shows detected AcroForm widgets as
 * clickable boxes, lets the admin place free pins on flat PDFs, and binds each
 * box to a Fluent Forms field. Coordinates are stored in PDF points with a
 * top-left origin (matching FPDF's coordinate system at unit "pt").
 *
 * This file is an ES module: PDF.js 6 is ESM-only, so it is imported here
 * rather than enqueued as a separate global script. The import is dynamic and
 * runs after the guard below, so the 500 KB library is only fetched on a screen
 * that actually renders a PDF.
 *
 * @package FFPM
 */

/* global ffpmMapper */
( async function () {
	'use strict';

	if ( ! document.getElementById( 'ffpm-mapper-app' ) || ! ffpmMapper.feedId ) {
		return;
	}

	// Resolve against the document, not this module's own URL — a bare import()
	// specifier is relative to the importing file, which would look for the
	// library under assets/admin/js/. plugin_dir_url() gives an absolute URL
	// today, but this keeps it correct if that ever changes.
	var pdfjsLib = await import( new URL( ffpmMapper.pdfjsSrc, document.baseURI ).href );

	pdfjsLib.GlobalWorkerOptions.workerSrc = ffpmMapper.workerSrc;

	var state = {
		pdf: null,
		pageNum: 1,
		pageCount: 1,
		scale: 1,
		pageHeightPt: 0,
		mapping: { version: 1, fields: [], repeater: null },
		settings: {},
		status: 'active',
		fields: [],
		widgets: [], // detected AcroForm rects for the current page
		selected: -1,
		placing: false
	};

	var canvas = document.getElementById( 'ffpm-canvas' );
	var overlay = document.getElementById( 'ffpm-overlay' );
	var holder = document.getElementById( 'ffpm-canvas-holder' );

	function $( id ) {
		return document.getElementById( id );
	}

	function ajax( params ) {
		var data = new FormData();
		Object.keys( params ).forEach( function ( key ) {
			data.append( key, params[ key ] );
		} );
		data.append( '_ajax_nonce', ffpmMapper.nonce );
		return fetch( ffpmMapper.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( r ) {
			return r.json();
		} );
	}

	/* ---------- boot ---------- */

	fetch(
		ffpmMapper.ajaxUrl + '?action=ffpm_mapper_data&feed=' + ffpmMapper.feedId + '&_ajax_nonce=' + ffpmMapper.nonce,
		{ credentials: 'same-origin' }
	)
		.then( function ( r ) {
			return r.json();
		} )
		.then( function ( json ) {
			if ( ! json || ! json.success ) {
				window.alert( json && json.data ? json.data.message : 'Error' );
				return;
			}
			var d = json.data;
			state.mapping = d.feed.mapping && d.feed.mapping.fields ? d.feed.mapping : state.mapping;
			state.settings = d.feed.settings || {};
			state.status = d.feed.status || 'active';
			state.fields = d.fields || [];
			state.pageCount = d.template.page_count;

			populateSourceSelects();
			applySettingsToUi();

			// No isEvalSupported flag here: PDF.js 6 compiles glyph outlines to a
			// Path2D instead of a `new Function()` body, so the FontMatrix
			// injection behind CVE-2024-4367 has no sink left to reach. Pinning
			// back to a 3.x/4.x build would reintroduce it — that eval path was
			// only ever gated by a flag, and a font matrix survives both the
			// pdf-lib sanitiser and the server-side token scan.
			return pdfjsLib.getDocument( {
				url: d.template.fileUrl,
				withCredentials: true
			} ).promise;
		} )
		.then( function ( pdf ) {
			if ( ! pdf ) {
				return;
			}
			state.pdf = pdf;
			renderPage();
		} );

	/* ---------- rendering ---------- */

	function renderPage() {
		state.pdf.getPage( state.pageNum ).then( function ( page ) {
			var baseViewport = page.getViewport( { scale: 1 } );
			var paneWidth = holder.parentElement.clientWidth - 20;

			// A pane that has not been laid out yet, or is narrower than the
			// gutter, yields a zero or negative scale. Canvas silently falls back
			// to 300x150 for a negative width, so the page would render blank
			// with no error at all — clamp instead.
			state.scale = Math.min( 1.5, Math.max( 0.25, paneWidth / baseViewport.width ) );
			state.pageHeightPt = baseViewport.height;

			var viewport = page.getViewport( { scale: state.scale } );
			canvas.width = viewport.width;
			canvas.height = viewport.height;
			holder.style.width = viewport.width + 'px';
			holder.style.height = viewport.height + 'px';

			$( 'ffpm-page-indicator' ).textContent = state.pageNum + ' / ' + state.pageCount;

			page.render( { canvasContext: canvas.getContext( '2d' ), viewport: viewport } );

			page.getAnnotations().then( function ( annotations ) {
				state.widgets = annotations
					.filter( function ( a ) {
						return 'Widget' === a.subtype && a.rect;
					} )
					.map( function ( a ) {
						// rect is [x1,y1,x2,y2] bottom-left origin → convert to top-left.
						return {
							x: a.rect[ 0 ],
							y: state.pageHeightPt - a.rect[ 3 ],
							w: a.rect[ 2 ] - a.rect[ 0 ],
							h: a.rect[ 3 ] - a.rect[ 1 ],
							name: a.fieldName || ''
						};
					} );
				drawOverlay();
			} );
		} );
	}

	function boxStyle( el, box ) {
		el.style.left = box.x * state.scale + 'px';
		el.style.top = box.y * state.scale + 'px';
		el.style.width = Math.max( 6, box.w * state.scale ) + 'px';
		el.style.height = Math.max( 6, box.h * state.scale ) + 'px';
	}

	function drawOverlay() {
		overlay.innerHTML = '';

		// Detected AcroForm widgets not yet bound — dashed blue.
		state.widgets.forEach( function ( widget ) {
			var isBound = state.mapping.fields.some( function ( f ) {
				return f.page === state.pageNum && Math.abs( f.x - widget.x ) < 1 && Math.abs( f.y - widget.y ) < 1;
			} );
			if ( isBound ) {
				return;
			}
			var el = document.createElement( 'div' );
			el.className = 'ffpm-box ffpm-box-detected';
			el.title = widget.name;
			boxStyle( el, widget );
			el.addEventListener( 'click', function () {
				addBinding( { x: widget.x, y: widget.y, w: widget.w, h: widget.h } );
			} );
			overlay.appendChild( el );
		} );

		// Existing bindings on this page — solid green (repeater: orange).
		state.mapping.fields.forEach( function ( field, index ) {
			if ( field.page !== state.pageNum ) {
				return;
			}
			var el = document.createElement( 'div' );
			el.className = 'ffpm-box ffpm-box-bound' + ( field.repeater ? ' ffpm-box-repeater' : '' ) + ( index === state.selected ? ' ffpm-box-selected' : '' );
			el.textContent = sourceLabel( field.source, field.sub );
			boxStyle( el, field );
			el.addEventListener( 'click', function () {
				selectBinding( index );
			} );
			overlay.appendChild( el );
		} );

		refreshBindingList();
	}

	/* ---------- bindings ---------- */

	function addBinding( box ) {
		state.mapping.fields.push( {
			page: state.pageNum,
			x: Math.round( box.x * 100 ) / 100,
			y: Math.round( box.y * 100 ) / 100,
			w: Math.round( box.w * 100 ) / 100,
			h: Math.round( box.h * 100 ) / 100,
			type: 'text',
			source: '',
			size: 9,
			align: 'L',
			multiline: false,
			repeater: false,
			sub: '',
			checked_value: ''
		} );
		selectBinding( state.mapping.fields.length - 1 );
	}

	function selectBinding( index ) {
		state.selected = index;
		var field = state.mapping.fields[ index ];
		if ( ! field ) {
			$( 'ffpm-binding-panel' ).hidden = true;
			drawOverlay();
			return;
		}
		$( 'ffpm-binding-panel' ).hidden = false;
		$( 'ffpm-bind-source' ).value = field.repeater ? field.source + '|' + field.sub : field.source;
		$( 'ffpm-bind-type' ).value = field.type;
		$( 'ffpm-bind-checked-value' ).value = field.checked_value;
		$( 'ffpm-bind-size' ).value = field.size;
		$( 'ffpm-bind-align' ).value = field.align;
		$( 'ffpm-bind-multiline' ).checked = field.multiline;
		$( 'ffpm-bind-repeater' ).checked = field.repeater;
		$( 'ffpm-bind-x' ).value = field.x;
		$( 'ffpm-bind-y' ).value = field.y;
		$( 'ffpm-bind-w' ).value = field.w;
		$( 'ffpm-bind-h' ).value = field.h;
		toggleCheckboxRow();
		rebuildSourceOptions();
		drawOverlay();
	}

	function toggleCheckboxRow() {
		document.querySelectorAll( '.ffpm-if-checkbox' ).forEach( function ( el ) {
			el.hidden = 'checkbox' !== $( 'ffpm-bind-type' ).value;
		} );
	}

	function current() {
		return state.mapping.fields[ state.selected ] || null;
	}

	function bindPanelEvents() {
		$( 'ffpm-bind-source' ).addEventListener( 'change', function () {
			var field = current();
			if ( ! field ) {
				return;
			}
			var value = this.value;
			if ( field.repeater && value.indexOf( '|' ) > -1 ) {
				var parts = value.split( '|' );
				field.source = parts[ 0 ];
				field.sub = parts[ 1 ];
			} else {
				field.source = value;
				field.sub = '';
			}
			drawOverlay();
		} );

		[ [ 'ffpm-bind-type', 'type' ], [ 'ffpm-bind-align', 'align' ], [ 'ffpm-bind-checked-value', 'checked_value' ] ].forEach( function ( pair ) {
			$( pair[ 0 ] ).addEventListener( 'change', function () {
				var field = current();
				if ( field ) {
					field[ pair[ 1 ] ] = this.value;
					toggleCheckboxRow();
					drawOverlay();
				}
			} );
		} );

		[ [ 'ffpm-bind-size', 'size' ], [ 'ffpm-bind-x', 'x' ], [ 'ffpm-bind-y', 'y' ], [ 'ffpm-bind-w', 'w' ], [ 'ffpm-bind-h', 'h' ] ].forEach( function ( pair ) {
			$( pair[ 0 ] ).addEventListener( 'input', function () {
				var field = current();
				var value = parseFloat( this.value );
				if ( field && ! isNaN( value ) ) {
					field[ pair[ 1 ] ] = value;
					drawOverlay();
				}
			} );
		} );

		[ [ 'ffpm-bind-multiline', 'multiline' ], [ 'ffpm-bind-repeater', 'repeater' ] ].forEach( function ( pair ) {
			$( pair[ 0 ] ).addEventListener( 'change', function () {
				var field = current();
				if ( field ) {
					field[ pair[ 1 ] ] = this.checked;
					rebuildSourceOptions();
					drawOverlay();
				}
			} );
		} );

		// No confirm() here on purpose. A browser that has had "prevent this page
		// from creating additional dialogs" triggered — easy to hit while placing
		// a lot of pins — makes confirm() return false silently for the rest of
		// the tab's life, which left this button permanently dead with nothing in
		// the console to explain it. Removing a binding is cheap to undo anyway:
		// nothing is persisted until Save, and the box can just be placed again.
		$( 'ffpm-bind-delete' ).addEventListener( 'click', function () {
			if ( state.selected < 0 ) {
				return;
			}
			var removed = state.mapping.fields[ state.selected ];
			state.mapping.fields.splice( state.selected, 1 );
			state.selected = -1;
			$( 'ffpm-binding-panel' ).hidden = true;
			drawOverlay();
			flashStatus( ffpmMapper.i18n.bindingRemoved.replace( '%s', sourceLabel( removed.source, removed.sub ) ) );
		} );
	}

	/**
	 * Show a transient message next to the Save button.
	 *
	 * @param {string} message Text to show.
	 */
	function flashStatus( message ) {
		var el = $( 'ffpm-save-status' );
		el.textContent = message;
		window.clearTimeout( flashStatus.timer );
		flashStatus.timer = window.setTimeout( function () {
			el.textContent = '';
		}, 4000 );
	}

	/* ---------- source selects ---------- */

	function sourceLabel( source, sub ) {
		if ( ! source ) {
			return ffpmMapper.i18n.unmapped;
		}
		if ( 'auto:row' === source ) {
			return ffpmMapper.i18n.rowNumber;
		}
		var name = source.replace( /^ff:/, '' );
		return sub ? name + ' › ' + sub : name;
	}

	function rebuildSourceOptions() {
		var select = $( 'ffpm-bind-source' );
		var field = current();
		var isRepeater = field ? field.repeater : false;
		select.innerHTML = '';
		select.appendChild( new Option( ffpmMapper.i18n.unmapped, '' ) );

		if ( isRepeater ) {
			select.appendChild( new Option( ffpmMapper.i18n.rowNumber, 'auto:row' ) );
			state.fields.forEach( function ( f ) {
				if ( 'repeater_field' === f.element && f.columns ) {
					f.columns.forEach( function ( col ) {
						select.appendChild( new Option( f.label + ' › ' + col.label, 'ff:' + f.name + '|' + col.index ) );
					} );
				}
			} );
		} else {
			state.fields.forEach( function ( f ) {
				if ( 'repeater_field' !== f.element ) {
					select.appendChild( new Option( f.label + ' (' + f.name + ')', 'ff:' + f.name ) );
				}
			} );
		}

		if ( field ) {
			select.value = field.repeater && field.sub !== '' ? field.source + '|' + field.sub : field.source;
		}
	}

	function populateSourceSelects() {
		var repeaterSelect = $( 'ffpm-repeater-source' );
		var condSelect = $( 'ffpm-cond-field' );
		state.fields.forEach( function ( f ) {
			if ( 'repeater_field' === f.element ) {
				repeaterSelect.appendChild( new Option( f.label + ' (' + f.name + ')', 'ff:' + f.name ) );
			} else {
				condSelect.appendChild( new Option( f.label + ' (' + f.name + ')', f.name ) );
			}
		} );

		repeaterSelect.addEventListener( 'change', showRepeaterColumns );
	}

	/**
	 * Show the fields detected inside the selected repeater so the admin can
	 * see what is available to bind to each PDF column.
	 */
	function showRepeaterColumns() {
		var wrap = $( 'ffpm-repeater-columns' );
		var list = $( 'ffpm-repeater-columns-list' );
		var value = $( 'ffpm-repeater-source' ).value;
		var name = value.replace( /^ff:/, '' );

		var field = null;
		state.fields.forEach( function ( f ) {
			if ( 'repeater_field' === f.element && f.name === name ) {
				field = f;
			}
		} );

		list.innerHTML = '';
		if ( ! field || ! field.columns || ! field.columns.length ) {
			wrap.hidden = true;
			return;
		}

		field.columns.forEach( function ( col ) {
			var li = document.createElement( 'li' );
			li.textContent = col.label + ' (' + col.name + ')';
			list.appendChild( li );
		} );
		wrap.hidden = false;
	}

	function refreshBindingList() {
		var list = $( 'ffpm-binding-list' );
		list.innerHTML = '';
		state.mapping.fields.forEach( function ( field, index ) {
			var li = document.createElement( 'li' );
			li.className = index === state.selected ? 'ffpm-selected' : '';
			li.textContent = 'p' + field.page + ' · ' + sourceLabel( field.source, field.sub ) + ( field.repeater ? ' ⟳' : '' );
			li.addEventListener( 'click', function () {
				if ( field.page !== state.pageNum ) {
					state.pageNum = field.page;
					state.selected = index;
					renderPage();
				} else {
					selectBinding( index );
				}
			} );
			list.appendChild( li );
		} );
	}

	/* ---------- settings ---------- */

	function applySettingsToUi() {
		var s = state.settings;
		$( 'ffpm-set-enabled' ).checked = 'active' === state.status;
		$( 'ffpm-set-attach' ).checked = !! s.attach_emails;
		$( 'ffpm-set-filename' ).value = s.filename || '{form}-{id}';
		$( 'ffpm-cond-enabled' ).checked = !! ( s.condition && s.condition.enabled );
		$( 'ffpm-cond-value' ).value = s.condition ? s.condition.value || '' : '';
		document.querySelector( '.ffpm-cond-row' ).hidden = ! $( 'ffpm-cond-enabled' ).checked;

		if ( state.mapping.repeater ) {
			$( 'ffpm-repeater-source' ).value = state.mapping.repeater.source;
			$( 'ffpm-repeater-rows' ).value = state.mapping.repeater.rows_per_page;
			$( 'ffpm-repeater-height' ).value = state.mapping.repeater.row_height;
		}
		showRepeaterColumns();

		window.setTimeout( function () {
			if ( s.condition && s.condition.field ) {
				$( 'ffpm-cond-field' ).value = s.condition.field;
			}
		}, 0 );

		$( 'ffpm-cond-enabled' ).addEventListener( 'change', function () {
			document.querySelector( '.ffpm-cond-row' ).hidden = ! this.checked;
		} );
	}

	function collectSettings() {
		return {
			attach_emails: $( 'ffpm-set-attach' ).checked,
			filename: $( 'ffpm-set-filename' ).value || '{form}-{id}',
			condition: {
				enabled: $( 'ffpm-cond-enabled' ).checked,
				field: $( 'ffpm-cond-field' ).value,
				value: $( 'ffpm-cond-value' ).value
			}
		};
	}

	function collectRepeater() {
		var source = $( 'ffpm-repeater-source' ).value;

		// Fall back to the source carried on the repeater bindings, so the
		// repeater config is never lost just because the dropdown wasn't touched.
		if ( ! source ) {
			state.mapping.fields.some( function ( f ) {
				if ( f.repeater && f.source && 'auto:row' !== f.source ) {
					source = f.source;
					return true;
				}
				return false;
			} );
		}

		if ( ! source ) {
			return null;
		}

		return {
			source: source,
			rows_per_page: parseInt( $( 'ffpm-repeater-rows' ).value, 10 ) || 6,
			row_height: parseFloat( $( 'ffpm-repeater-height' ).value ) || 0
		};
	}

	/**
	 * Warn when repeater columns are mapped but the row height is still 0 —
	 * every row would then draw on top of the first, which is never intended.
	 */
	function repeaterHeightWarning() {
		var hasRepeaterBinding = state.mapping.fields.some( function ( f ) {
			return f.repeater && 'auto:row' !== f.source;
		} );
		var height = parseFloat( $( 'ffpm-repeater-height' ).value ) || 0;

		if ( hasRepeaterBinding && height <= 0 ) {
			return ffpmMapper.i18n.rowHeightZero;
		}
		return '';
	}

	/* ---------- toolbar ---------- */

	$( 'ffpm-prev-page' ).addEventListener( 'click', function () {
		if ( state.pageNum > 1 ) {
			state.pageNum--;
			state.selected = -1;
			renderPage();
		}
	} );

	$( 'ffpm-next-page' ).addEventListener( 'click', function () {
		if ( state.pageNum < state.pageCount ) {
			state.pageNum++;
			state.selected = -1;
			renderPage();
		}
	} );

	$( 'ffpm-add-pin' ).addEventListener( 'click', function () {
		state.placing = true;
		holder.classList.add( 'ffpm-placing' );
	} );

	overlay.addEventListener( 'click', function ( event ) {
		if ( ! state.placing || event.target !== overlay ) {
			return;
		}
		state.placing = false;
		holder.classList.remove( 'ffpm-placing' );
		var rect = overlay.getBoundingClientRect();
		addBinding( {
			x: ( event.clientX - rect.left ) / state.scale,
			y: ( event.clientY - rect.top ) / state.scale - 7,
			w: 150,
			h: 14
		} );
	} );

	// The row-height warning is a two-step inline prompt rather than a confirm()
	// dialog: a tab with dialogs suppressed would otherwise be unable to save at
	// all, since confirm() returns false there and the save silently aborted.
	var heightWarningAcknowledged = false;

	[ 'ffpm-repeater-height', 'ffpm-repeater-rows' ].forEach( function ( id ) {
		$( id ).addEventListener( 'input', function () {
			heightWarningAcknowledged = false;
		} );
	} );

	$( 'ffpm-save' ).addEventListener( 'click', function () {
		var warning = repeaterHeightWarning();
		if ( warning && ! heightWarningAcknowledged ) {
			heightWarningAcknowledged = true;
			flashStatus( warning );
			return;
		}
		heightWarningAcknowledged = false;
		state.mapping.repeater = collectRepeater();
		var statusEl = $( 'ffpm-save-status' );
		ajax( {
			action: 'ffpm_save_mapping',
			feed: ffpmMapper.feedId,
			mapping: JSON.stringify( state.mapping ),
			settings: JSON.stringify( collectSettings() ),
			status: $( 'ffpm-set-enabled' ).checked ? 'active' : 'paused'
		} ).then( function ( json ) {
			statusEl.textContent = json && json.success ? ffpmMapper.i18n.saved : ffpmMapper.i18n.saveFailed;
			window.setTimeout( function () {
				statusEl.textContent = '';
			}, 3000 );
		} );
	} );

	$( 'ffpm-export-json' ).addEventListener( 'click', function () {
		state.mapping.repeater = collectRepeater();
		var payload = { ffpm_mapping_export: 1, mapping: state.mapping, settings: collectSettings() };
		var blob = new Blob( [ JSON.stringify( payload, null, 2 ) ], { type: 'application/json' } );
		var link = document.createElement( 'a' );
		link.href = URL.createObjectURL( blob );
		link.download = 'ffpm-mapping-feed-' + ffpmMapper.feedId + '.json';
		link.click();
		URL.revokeObjectURL( link.href );
	} );

	$( 'ffpm-import-json' ).addEventListener( 'click', function () {
		$( 'ffpm-import-file' ).click();
	} );

	$( 'ffpm-import-file' ).addEventListener( 'change', function () {
		var file = this.files[ 0 ];
		if ( ! file ) {
			return;
		}
		file.text().then( function ( text ) {
			try {
				var payload = JSON.parse( text );
				if ( ! payload.ffpm_mapping_export || ! payload.mapping || ! payload.mapping.fields ) {
					throw new Error( 'bad' );
				}
				state.mapping = payload.mapping;
				state.settings = payload.settings || state.settings;
				state.selected = -1;
				applySettingsToUi();
				drawOverlay();
			} catch ( e ) {
				window.alert( ffpmMapper.i18n.badImport );
			}
		} );
	} );

	bindPanelEvents();
}() );
