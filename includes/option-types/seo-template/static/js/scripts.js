/**
 * The SEO template control.
 *
 * Three jobs: put tags into a field without the user typing %%, measure what the
 * field will actually LOOK like in a search result, and show that result live.
 *
 * The measuring is the part worth explaining. A title is truncated by Google on
 * pixel width, not character count — "Illinois Wallpapering" and "lilliiiiii" are
 * the same length and nowhere near the same width — so the counter measures the
 * rendered string on a canvas at the font search results use. And it measures the
 * RESOLVED text, not the template: the width of "%%title%% %%sep%% %%sitename%%"
 * is a meaningless number to show someone.
 */
( function ( $ ) {
	'use strict';

	var data = window.fwSeoData || {};
	var l10n = data.l10n || {};

	var TITLE_FONT = '400 20px Arial, sans-serif';
	var DESC_FONT = '400 14px Arial, sans-serif';

	var measureCanvas = null;

	/**
	 * Width of a string in pixels, at the font search results render it in.
	 *
	 * @param {string} text
	 * @param {string} font
	 * @return {number}
	 */
	function measurePixels( text, font ) {
		if ( ! measureCanvas ) {
			measureCanvas = document.createElement( 'canvas' );
		}

		var context = measureCanvas.getContext( '2d' );

		if ( ! context ) {
			// No canvas (very old browser, or a hardened environment). Fall back
			// to an average glyph width rather than reporting a confident zero.
			return Math.round( text.length * 9.5 );
		}

		context.font = font;

		return Math.round( context.measureText( text ).width );
	}

	/**
	 * The human label for a tag, from the catalogue the server localised.
	 * Falls back to the tag's own id, so a tag we have no entry for still reads
	 * as a chip rather than as raw %%punctuation%%.
	 *
	 * @param {string} tag Full tag including the %% delimiters.
	 * @return {string}
	 */
	function tagLabel( tag ) {
		var rows = data.tags || [];
		var bare = tag.replace( /^%%|%%$/g, '' ).split( '|' )[ 0 ];

		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].tag === '%%' + bare + '%%' ) {
				return rows[ i ].label;
			}
		}

		return bare;
	}

	/**
	 * A chip element for one tag.
	 *
	 * contenteditable="false" is what makes it behave as a single unit: the
	 * caret steps over it and backspace removes the whole tag rather than
	 * chewing one character off a %% delimiter and silently breaking it.
	 *
	 * @param {string} tag
	 * @return {HTMLElement}
	 */
	function makeChip( tag ) {
		var chip = document.createElement( 'span' );

		chip.className = 'fw-seo-chip';
		chip.setAttribute( 'contenteditable', 'false' );
		chip.setAttribute( 'data-tag', tag );
		chip.setAttribute( 'title', tag );
		chip.textContent = tagLabel( tag );

		return chip;
	}

	/**
	 * Insert a tag chip into an editor, at the caret the user last had there.
	 *
	 * The caret has to be one the editor REMEMBERED, not one read at insert
	 * time. Clicking a toolbar button or opening the tag browser moves focus out
	 * of the editor and destroys the live selection; calling editor.focus() then
	 * puts the caret back at position zero. That position is genuinely inside
	 * the editor, so a "is the selection in my editor?" check believes it — and
	 * every insert lands at the far left no matter where the user was pointing.
	 *
	 * @param {jQuery}     $editor
	 * @param {string}     tag
	 * @param {Range|null} savedRange The caret recorded while the editor had focus.
	 */
	function insertChip( $editor, tag, savedRange ) {
		var editor = $editor.get( 0 );
		var selection = window.getSelection();
		var range;

		if ( savedRange && editor.contains( savedRange.commonAncestorContainer ) ) {
			range = savedRange.cloneRange();
			range.deleteContents();
		} else {
			// Never had a caret in this editor — append rather than dropping the
			// insert on the floor.
			range = document.createRange();
			range.selectNodeContents( editor );
			range.collapse( false );
		}

		var chip = makeChip( tag );
		var spacer = document.createTextNode( ' ' );

		// Is there already whitespace in front of the caret? Without this the
		// chip welds onto the preceding word ("EDITED" + Title), which reads as
		// one token and resolves as neither.
		var probe = range.cloneRange();
		probe.setStart( editor, 0 );

		var preceding = probe.toString();
		var needsLead = preceding.length > 0 && ! /\s$/.test( preceding );

		// insertNode() inserts at the start of the range, so each call lands
		// BEFORE the previous one: spacer, then chip, then the leading space.
		range.insertNode( spacer );
		range.insertNode( chip );

		if ( needsLead ) {
			range.insertNode( document.createTextNode( ' ' ) );
		}

		// Leave the caret after the chip and its trailing space.
		range.setStartAfter( spacer );
		range.collapse( true );

		editor.focus();

		if ( selection ) {
			selection.removeAllRanges();
			selection.addRange( range );
		}

		$editor.trigger( 'input' );

		// Hand the new caret back so the next insert continues from here rather
		// than from the position captured before this one.
		return range.cloneRange();
	}

	// -------------------------------------------------------------------------
	// The tag browser
	// -------------------------------------------------------------------------

	var $browser = null;
	var browserTarget = null;

	function buildBrowser() {
		if ( $browser ) {
			return $browser;
		}

		$browser = $(
			'<div class="fw-seo-browser" role="dialog" aria-modal="true" hidden>' +
				'<div class="fw-seo-browser-backdrop"></div>' +
				'<div class="fw-seo-browser-panel">' +
					'<div class="fw-seo-browser-head">' +
						'<strong></strong>' +
						'<button type="button" class="fw-seo-browser-close" aria-label=""></button>' +
					'</div>' +
					'<input type="search" class="fw-seo-browser-search" />' +
					'<div class="fw-seo-browser-list"></div>' +
				'</div>' +
			'</div>'
		);

		$browser.find( '.fw-seo-browser-head strong' ).text( l10n.browseTitle || 'Insert a tag' );
		$browser.find( '.fw-seo-browser-search' ).attr( 'placeholder', l10n.search || 'Search tags…' );
		$browser.find( '.fw-seo-browser-close' ).attr( 'aria-label', l10n.close || 'Close' ).html( '&times;' );

		$browser.on( 'click', '.fw-seo-browser-backdrop, .fw-seo-browser-close', closeBrowser );

		$browser.on( 'input', '.fw-seo-browser-search', function () {
			renderBrowserList( $( this ).val() );
		} );

		$browser.on( 'click', '.fw-seo-browser-row', function () {
			if ( browserTarget ) {
				browserTarget.insertTag( $( this ).data( 'tag' ) );
			}

			closeBrowser();
		} );

		$( document ).on( 'keydown.fwSeoBrowser', function ( event ) {
			if ( 27 === event.keyCode && $browser && ! $browser.prop( 'hidden' ) ) {
				closeBrowser();
			}
		} );

		$( 'body' ).append( $browser );

		return $browser;
	}

	function renderBrowserList( query ) {
		var rows = data.tags || [];
		var groups = data.groups || {};
		var needle = ( query || '' ).toLowerCase().trim();
		var $list = $browser.find( '.fw-seo-browser-list' );
		var buckets = {};
		var matched = 0;

		rows.forEach( function ( row ) {
			var haystack = ( row.tag + ' ' + row.label + ' ' + row.desc ).toLowerCase();

			if ( needle && haystack.indexOf( needle ) === -1 ) {
				return;
			}

			matched++;

			if ( ! buckets[ row.group ] ) {
				buckets[ row.group ] = [];
			}

			buckets[ row.group ].push( row );
		} );

		if ( ! matched ) {
			$list.html( $( '<p class="fw-seo-browser-empty"></p>' ).text( l10n.noResults || 'No tags match that search.' ) );

			return;
		}

		var $out = $( document.createDocumentFragment() );

		Object.keys( buckets ).forEach( function ( group ) {
			$out.append( $( '<h4 class="fw-seo-browser-group"></h4>' ).text( groups[ group ] || group ) );

			buckets[ group ].forEach( function ( row ) {
				var $row = $( '<button type="button" class="fw-seo-browser-row"></button>' ).data( 'tag', row.tag );

				$row.append( $( '<code></code>' ).text( row.tag ) );
				$row.append( $( '<span class="fw-seo-browser-label"></span>' ).text( row.label ) );

				if ( row.desc ) {
					$row.append( $( '<span class="fw-seo-browser-desc"></span>' ).text( row.desc ) );
				}

				if ( row.example ) {
					$row.append(
						$( '<span class="fw-seo-browser-example"></span>' )
							.text( ( l10n.example || 'Example' ) + ': ' + row.example )
					);
				}

				$out.append( $row );
			} );
		} );

		$list.empty().append( $out );
	}

	function openBrowser( field ) {
		browserTarget = field;

		buildBrowser().prop( 'hidden', false );

		renderBrowserList( '' );

		$browser.find( '.fw-seo-browser-search' ).val( '' ).trigger( 'focus' );
	}

	function closeBrowser() {
		if ( ! $browser ) {
			return;
		}

		$browser.prop( 'hidden', true );

		if ( browserTarget ) {
			browserTarget.$editor.trigger( 'focus' );
		}

		browserTarget = null;
	}

	// -------------------------------------------------------------------------
	// Preview + counters
	// -------------------------------------------------------------------------

	/**
	 * One template field: its counter, and its slot in whatever preview card
	 * shares its scope.
	 *
	 * The unit here is the FIELD, not the card. The settings screen has dozens
	 * of template fields and no card at all, so anything keyed to the card
	 * leaves every counter on that screen permanently blank.
	 *
	 * @param {HTMLElement} element
	 */
	function Field( element ) {
		this.el = element;
		this.$el = $( element );
		this.name = this.$el.attr( 'data-seo-field' ) || 'title';
		this.limit = parseInt( this.$el.attr( 'data-seo-limit' ), 10 ) || 0;
		this.pixels = 'pixels' === this.$el.attr( 'data-seo-measure' );
		this.$counter = this.$el.closest( '.fw-seo-template' ).find( '.fw-seo-template-counter' );

		// The card this field feeds, if any. Scoped to the nearest region that
		// holds both, so several previews can coexist without cross-painting.
		var $scope = this.$el.closest( '.fw-seo-metabox, .fw-seo-term-fields, .fw-backend-option-type-box, form' );

		this.$card = $scope.length ? $scope.find( '[data-seo-preview]' ).first() : $();

		// The hidden companion recording what the server pre-filled. Sent with
		// every resolve so an untouched pre-fill is not reported as the user's
		// own text. Absent on the settings screen, which has no pre-fills.
		this.$pristine = $scope.length
			? $scope.find( 'input[type="hidden"][name*="seo_' + this.name + '__pristine"]' ).first()
			: $();

		this.timer = null;
		this.request = null;
		this.last = null;

		// Progressive enhancement: the real control keeps the value and the
		// form name, and a chip editor is layered over it. If this script never
		// runs, the field is still a plain, fully working text input — the value
		// is never held anywhere but the element that submits it.
		this.buildEditor();
	}

	/**
	 * Replace the plain control with a contenteditable that renders %%tags%% as
	 * chips, the way AIOSEO and Yoast present them.
	 *
	 * The original input stays in the DOM, hidden, and remains the single source
	 * of truth: the editor serialises into it on every keystroke. Nothing reads
	 * the chips to decide what to save.
	 */
	Field.prototype.buildEditor = function () {
		var self = this;

		this.$editor = $( '<div class="fw-seo-template-editor" contenteditable="true" role="textbox"></div>' );

		if ( 'TEXTAREA' === this.el.tagName ) {
			this.$editor.attr( 'aria-multiline', 'true' ).addClass( 'is-multiline' );
		}

		var labelled = this.$el.closest( '.fw-option' ).find( 'label' ).first().text();

		if ( labelled ) {
			this.$editor.attr( 'aria-label', $.trim( labelled ) );
		}

		this.$el.after( this.$editor ).hide();

		this.renderChips( this.$el.val() || '' );

		this.savedRange = null;

		// Record the caret on every interaction, so an insert triggered from a
		// button (which has already taken focus away) still knows where the user
		// actually was.
		this.$editor.on( 'keyup mouseup input focus', function () {
			self.saveRange();
		} );

		this.$editor.on( 'input', function () {
			self.sync();
		} );

		// Paste as plain text: pasted markup would otherwise land inside the
		// editor as real elements and serialise into the template as garbage.
		this.$editor.on( 'paste', function ( event ) {
			event.preventDefault();

			var text = ( event.originalEvent || event ).clipboardData.getData( 'text/plain' );

			document.execCommand( 'insertText', false, text );
		} );

		// A single-line field must not accept newlines.
		this.$editor.on( 'keydown', function ( event ) {
			if ( 13 === event.keyCode && ! self.$editor.hasClass( 'is-multiline' ) ) {
				event.preventDefault();
			}
		} );
	};

	/**
	 * Remember where the caret is, while the editor still has focus.
	 */
	Field.prototype.saveRange = function () {
		var selection = window.getSelection();

		if ( ! selection || ! selection.rangeCount ) {
			return;
		}

		var range = selection.getRangeAt( 0 );

		if ( this.$editor.get( 0 ).contains( range.commonAncestorContainer ) ) {
			this.savedRange = range.cloneRange();
		}
	};

	/**
	 * Insert a tag at the remembered caret.
	 *
	 * @param {string} tag
	 */
	Field.prototype.insertTag = function ( tag ) {
		this.savedRange = insertChip( this.$editor, tag, this.savedRange );
	};

	/**
	 * Serialise the editor back into the real control.
	 */
	Field.prototype.sync = function () {
		var raw = this.serialize();

		this.$el.val( raw );
		this.schedule();
	};

	/**
	 * Editor DOM -> the raw template string.
	 *
	 * @return {string}
	 */
	Field.prototype.serialize = function () {
		var out = '';

		function walk( node ) {
			for ( var i = 0; i < node.childNodes.length; i++ ) {
				var child = node.childNodes[ i ];

				if ( 3 === child.nodeType ) {
					out += child.nodeValue;
					continue;
				}

				if ( 1 !== child.nodeType ) {
					continue;
				}

				var tag = child.getAttribute && child.getAttribute( 'data-tag' );

				if ( tag ) {
					out += tag;
					continue;
				}

				if ( 'BR' === child.tagName ) {
					out += '\n';
					continue;
				}

				walk( child );
			}
		}

		walk( this.$editor.get( 0 ) );

		// Browsers insert non-breaking spaces while editing; they are not what
		// the user typed and would resolve into the template verbatim.
		return out.replace( / /g, ' ' );
	};

	/**
	 * The raw template string -> editor DOM, with known tags as chips.
	 *
	 * @param {string} raw
	 */
	Field.prototype.renderChips = function ( raw ) {
		var editor = this.$editor.get( 0 );
		var pattern = /%%([a-z0-9_-]+)((?:\|[a-z0-9_]+(?::[^%|]*)?)*)%%/gi;
		var last = 0;
		var match;

		editor.innerHTML = '';

		while ( ( match = pattern.exec( raw ) ) !== null ) {
			if ( match.index > last ) {
				editor.appendChild( document.createTextNode( raw.slice( last, match.index ) ) );
			}

			editor.appendChild( makeChip( match[ 0 ] ) );

			last = match.index + match[ 0 ].length;
		}

		if ( last < raw.length ) {
			editor.appendChild( document.createTextNode( raw.slice( last ) ) );
		}
	};

	/**
	 * Debounced: a request per keystroke would be slow and pointless.
	 */
	Field.prototype.schedule = function () {
		var self = this;

		window.clearTimeout( this.timer );

		this.timer = window.setTimeout( function () {
			self.refresh();
		}, 350 );
	};

	Field.prototype.refresh = function () {
		var template = this.$el.val() || '';

		if ( template === this.last ) {
			return;
		}

		this.last = template;

		if ( ! data.ajaxUrl ) {
			this.paint( template, '' );

			return;
		}

		var self = this;

		if ( this.request ) {
			this.request.abort();
		}

		this.request = $.post( data.ajaxUrl, {
			action: 'fw_seo_preview',
			nonce: data.nonce,
			post_id: data.postId || 0,
			field: this.name,
			template: template,
			pristine: this.$pristine.length ? this.$pristine.val() : ''
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					self.paint( response.data.value || '', response.data.source, response.data );
				}
			} )
			.fail( function ( xhr, status ) {
				if ( 'abort' === status ) {
					return;
				}

				// The server could not resolve it (no posts yet, for instance).
				// Measuring the raw template is a poor answer but a blank
				// counter is a worse one.
				self.paint( template, '' );
			} )
			.always( function () {
				self.request = null;
			} );
	};

	/**
	 * @param {string} resolved
	 * @param {string} source
	 * @param {Object} [meta]
	 */
	Field.prototype.paint = function ( resolved, source, meta ) {
		this.count( resolved );

		// An empty box does NOT mean an empty tag — it means "use the template,
		// or generate one". Showing the resolved value as the placeholder is the
		// difference between a field that looks broken and one that is visibly
		// already doing its job. It stays a placeholder, never a value: writing
		// it into the input would turn every post into a frozen override and
		// silently detach it from the template it came from.
		if ( ! $.trim( this.$el.val() || '' ) ) {
			this.$editor.attr( 'data-placeholder', resolved );
			this.$editor.addClass( 'is-empty' );
		} else {
			this.$editor.removeClass( 'is-empty' );
		}

		if ( ! this.$card.length ) {
			return;
		}

		this.$card.find( '.fw-seo-preview-' + this.name ).text( resolved );

		if ( meta && meta.url ) {
			this.$card.find( '.fw-seo-preview-url' ).text( meta.url );
		}

		// Say where the value came from — without it the user cannot tell an
		// auto-generated description from one they wrote and forgot about.
		var labels = l10n.sourceLabel || {};

		this.$card.data( 'sources', $.extend( {}, this.$card.data( 'sources' ), (
			function ( name, value ) {
				var out = {};
				out[ name ] = value;
				return out;
			}( this.name, labels[ source ] || '' )
		) ) );

		var sources = this.$card.data( 'sources' ) || {};
		var parts = [];

		[ 'title', 'description' ].forEach( function ( name ) {
			if ( sources[ name ] ) {
				parts.push( sources[ name ] );
			}
		} );

		this.$card.find( '.fw-seo-preview-sources' ).text( parts.join( ' · ' ) );
	};

	/**
	 * @param {string} resolved
	 */
	Field.prototype.count = function ( resolved ) {
		if ( ! this.$counter.length || this.limit < 1 ) {
			return;
		}

		var length = this.pixels
			? measurePixels( resolved, 'title' === this.name ? TITLE_FONT : DESC_FONT )
			: resolved.length;

		var template = this.pixels
			? ( l10n.pixels || '%1$s out of %2$s max recommended pixels.' )
			: ( l10n.characters || '%1$s out of %2$s max recommended characters.' );

		this.$counter
			.text( template.replace( '%1$s', length ).replace( '%2$s', this.limit ) )
			.toggleClass( 'is-over', length > this.limit )
			.toggleClass( 'is-empty', 0 === length );
	};

	// -------------------------------------------------------------------------
	// Wiring
	// -------------------------------------------------------------------------

	/**
	 * @param {jQuery} $context
	 */
	function init( $context ) {
		var pending = 0;

		$context.find( '.fw-seo-template' ).each( function () {
			var $wrap = $( this );

			if ( $wrap.data( 'fwSeoReady' ) ) {
				return;
			}

			$wrap.data( 'fwSeoReady', true );

			var element = $wrap.find( '.fw-seo-template-input' ).get( 0 );

			if ( ! element ) {
				return;
			}

			var field = new Field( element );

			// Keep the caret where it is: a button that takes focus blows away
			// the selection before the click handler ever runs.
			$wrap.on( 'mousedown', '.fw-seo-insert, .fw-seo-browse', function ( event ) {
				event.preventDefault();
			} );

			$wrap.on( 'click', '.fw-seo-insert', function () {
				field.insertTag( $( this ).data( 'tag' ) );
			} );

			$wrap.on( 'click', '.fw-seo-browse', function () {
				openBrowser( field );
			} );

			// The hidden original still fires change when something sets it
			// programmatically; the editor's own input handler calls sync().
			$wrap.on( 'change', '.fw-seo-template-input', function () {
				field.schedule();
			} );

			// Paint once so the counter and the card are correct on load rather
			// than only after the first keystroke — but the settings screen has
			// a template pair per location, and resolving all of them at once
			// would fire dozens of requests for fields the user cannot even see.
			// Visible fields resolve now (staggered); the rest wait until the
			// tab they live on is opened and the field is focused.
			if ( $wrap.is( ':visible' ) ) {
				window.setTimeout( function () {
					field.refresh();
				}, 60 * ( pending++ ) );
			} else {
				$wrap.one( 'focusin', function () {
					field.refresh();
				} );
			}
		} );

		$context.find( '[data-seo-preview]' ).each( function () {
			var $card = $( this );

			if ( $card.data( 'fwSeoReady' ) ) {
				return;
			}

			$card.data( 'fwSeoReady', true );

			$card.on( 'click', '.fw-seo-preview-mode', function () {
				var mode = $( this ).data( 'mode' );

				$card.find( '.fw-seo-preview-mode' ).removeClass( 'is-active' );
				$( this ).addClass( 'is-active' );
				$card.find( '.fw-seo-preview-card' )
					.toggleClass( 'is-desktop', 'desktop' === mode )
					.toggleClass( 'is-mobile', 'mobile' === mode );
			} );
		} );
	}

	/**
	 * The robots disclosure.
	 *
	 * Tabs are the framework's own container and need no JS from us.
	 */
	function initChrome() {
		// "Use the settings for this content type" collapses the per-page rules.
		var syncRobots = function ( $switch ) {
			var $option = $switch.closest( '.fw-option' );
			var on = 'true' === String( $switch.find( 'input' ).val() );

			$option.nextAll( '.fw-backend-option-type-group' ).first()
				.toggleClass( 'fw-seo-robots-hidden', on );
		};

		$( '.fw-backend-option-type-switch' ).each( function () {
			var $switch = $( this );

			if ( $switch.find( 'input[name*="seo_robots_default"]' ).length ) {
				syncRobots( $switch );

				$switch.on( 'change', 'input', function () {
					syncRobots( $switch );
				} );
			}
		} );
	}

	$( function () {
		init( $( document ) );
		initChrome();
	} );

	// Options rendered later (a repeater row, a lazily-fetched box) announce
	// themselves through the framework's own event rather than a mutation
	// observer.
	if ( window.fwEvents ) {
		window.fwEvents.on( 'fw:options:init', function ( payload ) {
			if ( payload && payload.$elements ) {
				init( payload.$elements );
			}
		} );
	}
}( window.jQuery ) );
