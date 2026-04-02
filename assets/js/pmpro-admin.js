/**
 * PMPro membership level admin: TutorPress course category row + checklist helpers.
 */
(function () {
	'use strict';

	function syncCourseCategoryRow() {
		var select = document.getElementById( 'TUTORPRESS_PMPRO_membership_model_select' );
		var row = document.querySelector( 'tr.membership_course_categories' );
		if ( ! select || ! row ) {
			return;
		}
		row.style.display = select.value === 'category_wise_membership' ? '' : 'none';
	}

	function setChecklistChecked( checked ) {
		var checklist = document.getElementById( 'tutorpress-pmpro-course-categories-checklist' );
		if ( ! checklist ) {
			return;
		}
		var boxes = checklist.querySelectorAll( 'input[type="checkbox"]' );
		var i;
		for ( i = 0; i < boxes.length; i++ ) {
			boxes[ i ].checked = checked;
		}
	}

	function bindSelectAllNone() {
		var allBtn = document.getElementById( 'tutorpress-pmpro-course-categories-select-all' );
		var noneBtn = document.getElementById( 'tutorpress-pmpro-course-categories-select-none' );
		if ( allBtn ) {
			allBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setChecklistChecked( true );
			} );
		}
		if ( noneBtn ) {
			noneBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setChecklistChecked( false );
			} );
		}
	}

	function init() {
		var select = document.getElementById( 'TUTORPRESS_PMPRO_membership_model_select' );
		syncCourseCategoryRow();
		if ( select ) {
			// Select2 fires jQuery events, not native DOM events — use jQuery when available.
			if ( window.jQuery ) {
				window.jQuery( select ).on( 'change', syncCourseCategoryRow );
			} else {
				select.addEventListener( 'change', syncCourseCategoryRow );
			}
		}
		bindSelectAllNone();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
