/**
 * WPSC_Settings_Page object and functions.
 *
 * Dependencies: jQuery, jQuery.query
 *
 * The following properties of WPSC_Settings_Page have been set by wp_localize_script():
 * - current_tab: The ID of the currently active tab
 * - nonce      : The nonce used to verify request to load tab content via AJAX
 */

/**
 * @requires jQuery
 * @requires jQuery.query
 */

(function($){

	$.extend(WPSC_Settings_Page, /** @lends WPSC_Settings_Page */ {
		/**
		 * Set to true if there are modified settings.
		 * @type {Boolean}
		 * @since 3.8.8
		 */
		unsaved_settings : false,

		/**
		 * Event binding for WPSC_Settings_Page
		 * @since 3.8.8
		 */
		init : function() {
			// make sure the event object contains the 'state' property
			$.event.props.push('state');

			// set the history state of the current page
			if (history.replaceState) {
				(function(){
					history.replaceState({tab_id : WPSC_Settings_Page.current_tab}, '', location.search + location.hash);
				})();
			}

			// load the correct settings tab when back/forward browser button is used
			$(window).bind('popstate', WPSC_Settings_Page.event_pop_state);

			$(function(){
				$('#wpsc_options').delegate('a.nav-tab'              , 'click' , WPSC_Settings_Page.event_tab_button_clicked).
				                   delegate('input, textarea, select', 'change', WPSC_Settings_Page.event_settings_changed).
				                   delegate('#wpsc-settings-form'    , 'submit', WPSC_Settings_Page.event_settings_form_submitted);
				$(window).bind('beforeunload', WPSC_Settings_Page.event_before_unload);
				$(WPSC_Settings_Page).trigger('wpsc_settings_tab_loaded');
				$(WPSC_Settings_Page).trigger('wpsc_settings_tab_loaded_' + WPSC_Settings_Page.current_tab);
			});
		},

		/**
		 * This prevents the confirm dialog triggered by event_before_unload from being displayed.
		 * @since 3.8.8
		 */
		event_settings_form_submitted : function() {
			WPSC_Settings_Page.unsaved_settings = false;
		},

		/**
		 * Mark the page as "unsaved" when a field is modified
		 * @since 3.8.8
		 */
		event_settings_changed : function() {
			WPSC_Settings_Page.unsaved_settings = true;
		},

		/**
		 * Display a confirm dialog when the user is trying to navigate
		 * away with unsaved settings
		 * @since 3.8.8
		 */
		event_before_unload : function() {
			if (WPSC_Settings_Page.unsaved_settings) {
				return WPSC_Settings_Page.before_unload_dialog;
			}
		},

		/**
		 * Load the settings tab when tab buttons are clicked
		 * @since 3.8.8
		 */
		event_tab_button_clicked : function() {
			var tab_id = $(this).data('tab-id');
			if (tab_id != WPSC_Settings_Page.current_tab) {
				WPSC_Settings_Page.load_tab(tab_id);
			}
			return false;
		},

		/**
		 * When back/forward browser button is clicked, load the correct tab
		 * @param {Object} e Event object
		 * @since 3.8.8
		 */
		event_pop_state : function(e) {
			if (e.state) {
				WPSC_Settings_Page.load_tab(e.state.tab_id, false);
			}
		},

		/**
		 * Display a small spinning wheel when loading a tab via AJAX
		 * @param  {String} tab_id Tab ID
		 * @since 3.8.8
		 */
		toggle_ajax_state : function(tab_id) {
			var tab_button = $('a[data-tab-id="' + tab_id + '"]');
			tab_button.toggleClass('nav-tab-loading');
		},

		/**
		 * Use AJAX to load a tab to the settings page. If there are unsaved settings in the
		 * current tab, a confirm dialog will be displayed.
		 *
		 * @param  {String}  tab_id The ID string of the tab
		 * @param  {Boolean} push_state True (Default) if we need to history.pushState.
		 *                              False if this is a result of back/forward browser button being pushed.
		 * @since 3.8.8
		 */
		load_tab : function(tab_id, push_state) {
			if (WPSC_Settings_Page.unsaved_settings && ! confirm(WPSC_Settings_Page.ajax_navigate_confirm_dialog)) {
				return;
			}

			if (typeof push_state == 'undefined') {
				push_state = true;
			}

			var new_url = '?page=wpsc-settings&tab=' + tab_id;
			var post_data = {
				'action' : 'wpsc_navigate_settings_tab',
				'tab_id' : tab_id,
				'nonce'  : WPSC_Settings_Page.nonce,
				'current_url' : location.href
			};

			WPSC_Settings_Page.toggle_ajax_state(tab_id);

			// pushState to save this page load into history, and alter the address field of the browser
			if (push_state && history.pushState) {
				history.pushState({'tab_id' : tab_id}, '', new_url);
			}

			/**
			 * Replace the option tab content with the AJAX response, also change
			 * the action URL of the form and switch the active tab.
			 * @param  {String} response HTML response string
			 * @since 3.8.8
			 */
			var ajax_callback = function(response) {
				var t = WPSC_Settings_Page;
				t.unsaved_settings = false;
				t.toggle_ajax_state(tab_id);
				$('#options_' + WPSC_Settings_Page.current_tab).replaceWith(response);
				WPSC_Settings_Page.current_tab = tab_id;
				$('.nav-tab-active').removeClass('nav-tab-active');
				$('[data-tab-id="' + tab_id + '"]').addClass('nav-tab-active');
				$('#wpsc_options_page form').attr('action', new_url);
				$(t).trigger('wpsc_settings_tab_loaded');
				$(t).trigger('wpsc_settings_tab_loaded_' + tab_id);
			}

			$.post(ajaxurl, post_data, ajax_callback, 'html');
		}
	});

	/**
	 * General tab
	 * @namespace
	 * @since 3.8.8
	 */
	WPSC_Settings_Page.General = {
		/**
		 * Event binding for base country drop down
		 * @since 3.8.8
		 */
		event_init : function() {
			var wrapper = $('#options_general');
			wrapper.delegate('#wpsc-base-country-drop-down', 'change', WPSC_Settings_Page.General.event_base_country_changed).
			        delegate('.wpsc-select-all', 'click', WPSC_Settings_Page.General.event_select_all).
			        delegate('.wpsc-select-none', 'click', WPSC_Settings_Page.General.event_select_none);
		},

		/**
		 * Select all countries for Target Markets
		 * @since 3.8.8
		 */
		event_select_all : function() {
			$('#wpsc-target-markets input:checkbox').each(function(){ this.checked = true; });
			return false;
		},

		/**
		 * Deselect all countries for Target Markets
		 * @since 3.8.8
		 */
		event_select_none : function() {
			$('#wpsc-target-markets input:checkbox').each(function(){ this.checked = false; });
			return false;
		},

		/**
		 * When country is changed, load the region / state drop down using AJAX
		 * @since 3.8.8
		 */
		event_base_country_changed : function() {
			var span = $('#wpsc-base-region-drop-down');
			span.find('select').remove();
			span.find('img').toggleClass('ajax-feedback-active');

			var postdata = {
				action  : 'wpsc_display_region_list',
				country : $('#wpsc-base-country-drop-down').val(),
				nonce   : WPSC_Settings_Page.nonce
			};

			var ajax_callback = function(response) {
				span.find('img').toggleClass('ajax-feedback-active');
				if (response !== '') {
					span.prepend(response);
				}
			};
			$.post(ajaxurl, postdata, ajax_callback, 'html');
		}
	};
	$(WPSC_Settings_Page).bind('wpsc_settings_tab_loaded_general', WPSC_Settings_Page.General.event_init);

	/**
	 * Presentation tab
	 * @namespace
	 * @since 3.8.8
	 */
	WPSC_Settings_Page.Presentation = {
		/**
		 * IDs of checkboxes for Grid View (excluding the Show Images Only checkbox)
		 * @type {Array}
		 * @since 3.8.8
		 */
		grid_view_boxes : ['wpsc-display-variations', 'wpsc-display-description', 'wpsc-display-add-to-cart', 'wpsc-display-more-details'],

		/**
		 * Event binding for Grid View checkboxes
		 * @since 3.8.8
		 */
		event_init : function() {
			var wrapper = $('#options_presentation'),
			    checkbox_selector = '#' + WPSC_Settings_Page.Presentation.grid_view_boxes.join(',#');
			wrapper.delegate('#wpsc-show-images-only', 'click', WPSC_Settings_Page.Presentation.event_show_images_only_clicked);
			wrapper.delegate(checkbox_selector       , 'click', WPSC_Settings_Page.Presentation.event_grid_view_boxes_clicked);
		},

		/**
		 * Deselect "Show Images Only" checkbox when any other Grid View checkboxes are selected
		 * @since 3.8.8
		 */
		event_grid_view_boxes_clicked : function() {
			document.getElementById('wpsc-show-images-only').checked = false;
		},

		/**
		 * Deselect all other Grid View checkboxes when "Show Images Only" is selected
		 * @since 3.8.8
		 */
		event_show_images_only_clicked : function() {
			var i;
			if ($(this).is(':checked')) {
				for (i in WPSC_Settings_Page.Presentation.grid_view_boxes) {
					document.getElementById(WPSC_Settings_Page.Presentation.grid_view_boxes[i]).checked = false;
				}
			}
		}
	};
	$(WPSC_Settings_Page).bind('wpsc_settings_tab_loaded_presentation', WPSC_Settings_Page.Presentation.event_init);

	/**
	 * Checkout Tab
	 * @namespace
	 * @since 3.8.8
	 */
	WPSC_Settings_Page.Checkout = {
		/**
		 * Event binding for Checkout tab
		 * @since 3.8.8
		 */
		event_init : function() {
			var wrapper = $('#options_checkout');
			wrapper.delegate('.add_new_form_set', 'click', WPSC_Settings_Page.Checkout.event_add_new_form_set);
		},

		/**
		 * Toggle "Add New Form Set" field
		 * @since 3.8.8
		 */
		event_add_new_form_set : function() {
			jQuery(".add_new_form_set_forms").toggle();
				return false;
		}
	};
	$(WPSC_Settings_Page).bind('wpsc_settings_tab_loaded_checkout', WPSC_Settings_Page.Checkout.event_init);

	/**
	 * Taxes tab
	 * @namespace
	 * @since 3.8.8
	 */
	WPSC_Settings_Page.Taxes = {
		/**
		 * Event binding for Taxes tab
		 * @since 3.8.8
		 */
		event_init : function() {
			var wrapper = $('#options_taxes');
			wrapper.delegate('#wpsc-add-tax-rates a'        , 'click' , WPSC_Settings_Page.Taxes.event_add_tax_rate).
			        delegate('.wpsc-taxes-rates-delete'     , 'click' , WPSC_Settings_Page.Taxes.event_delete_tax_rate).
			        delegate('#wpsc-add-tax-bands a'        , 'click' , WPSC_Settings_Page.Taxes.event_add_tax_band).
			        delegate('.wpsc-taxes-bands-delete'     , 'click' , WPSC_Settings_Page.Taxes.event_delete_tax_band).
			        delegate('.wpsc-taxes-country-drop-down', 'change', WPSC_Settings_Page.Taxes.event_country_drop_down_changed);
		},

		/**
		 * Load the region drop down via AJAX if the country has regions
		 * @since 3.8.8
		 */
		event_country_drop_down_changed : function() {
			var c = $(this),
			    post_data = {
					action            : 'wpec_taxes_ajax',
					wpec_taxes_action : 'wpec_taxes_get_regions',
					current_key       : c.data('key'),
					taxes_type        : c.data('type'),
					country_code      : c.val(),
					nonce             : WPSC_Settings_Page.nonce
				},
				spinner = c.siblings('.ajax-feedback'),
				ajax_callback = function(response) {
					spinner.toggleClass('ajax-feedback-active');
					if (response != '') {
						c.after(response);
					}
				};
			spinner.toggleClass('ajax-feedback-active');
			c.siblings('.wpsc-taxes-region-drop-down').remove();

			$.post(ajaxurl, post_data, ajax_callback, 'html');
		},

		/**
		 * Add new tax rate field when "Add Tax Rate" is clicked
		 * @since 3.8.8
		 * TODO: rewrote the horrible code in class wpec_taxes_controller. There's really no need for AJAX here.
		 */
		event_add_tax_rate : function() {
			WPSC_Settings_Page.Taxes.add_field('rates');
			return false;
		},

		/**
		 * Remove a tax rate row when "Delete" on that row is clicked.
		 * @since 3.8.8
		 */
		event_delete_tax_rate : function() {
			$(this).parents('.wpsc-tax-rates-row').remove();
			return false;
		},

		/**
		 * Add new tax band field when "Add Tax Band" is clicked.
		 * @since 3.8.8
		 */
		event_add_tax_band : function() {
			WPSC_Settings_Page.Taxes.add_field('bands');
			return false;
		},

		/**
		 * Delete a tax band field when "Delete" is clicked.
		 * @return {[type]}
		 */
		event_delete_tax_band : function() {
			$(this).parents('.wpsc-tax-bands-row').remove();
			return false;
		},

		/**
		 * Add a field to the Tax Rate / Tax Band form, depending on the supplied type
		 * @param {String} Either "bands" or "rates" to specify the type of field
		 * @since 3.8.8
		 */
		add_field : function(type) {
			var button_wrapper = $('#wpsc-add-tax-' + type);
			    count = $('.wpsc-tax-' + type + '-row').size(),
			    post_data = {
			    	action            : 'wpec_taxes_ajax',
			    	wpec_taxes_action : 'wpec_taxes_build_' + type + '_form',
			    	current_key       : count,
			    	nonce             : WPSC_Settings_Page.nonce,
			    },
			    ajax_callback = function(response) {
			    	button_wrapper.before(response).find('img').toggleClass('ajax-feedback-active');
			    };

			button_wrapper.find('img').toggleClass('ajax-feedback-active');
			$.post(ajaxurl, post_data, ajax_callback, 'html');
		}
	}
	$(WPSC_Settings_Page).bind('wpsc_settings_tab_loaded_taxes', WPSC_Settings_Page.Taxes.event_init);

	/**
	 * Shipping Tab
	 * @since 3.8.8
	 */
	WPSC_Settings_Page.Shipping = {
		/**
		 * Event binding for Shipping tab.
		 * @since 3.8.8
		 */
		event_init : function() {
			WPSC_Settings_Page.Shipping.wrapper = $('#options_shipping');
			WPSC_Settings_Page.Shipping.table_rate = WPSC_Settings_Page.Shipping.wrapper.find('.table-rate');
			WPSC_Settings_Page.Shipping.wrapper.
				delegate('.edit-shipping-module'         , 'click'   , WPSC_Settings_Page.Shipping.event_edit_shipping_module).
				delegate('.table-rate .add'              , 'click'   , WPSC_Settings_Page.Shipping.event_add_table_rate_layer).
				delegate('.table-rate .delete'           , 'click'   , WPSC_Settings_Page.Shipping.event_delete_table_rate_layer).
				delegate('.table-rate input[type="text"]', 'keypress', WPSC_Settings_Page.Shipping.event_enter_key_pressed);
		},

		/**
		 * When Enter key is pressed inside the table rate fields, it should either move
		 * focus to the next input field (just like tab), or create a new row and do that.
		 *
		 * This is to prevent accidental form submission.
		 *
		 * @param  {Object} e Event object
		 * @since 3.8.8
		 */
		event_enter_key_pressed : function(e) {
			var code = e.keyCode ? e.keyCode : e.which;
			if (code == 13) {
				var add_button = $(this).siblings('.actions').find('.add');
				if (add_button.size() > 0) {
					add_button.trigger('click', [true]);
				} else {
					$(this).closest('td').siblings('td').find('input').focus();
				}
				e.preventDefault();
			}
		},

		/**
		 * Add a layer row to the table rate form
		 * @param  {Object} e Event object
		 * @param  {Boolean} focus_on_new_row Defaults to false. Whether to automatically put focus on the first input of the new row.
		 * @since 3.8.8
		 */
		event_add_table_rate_layer : function(e, focus_on_new_row) {
			if (typeof focus_on_new_row === 'undefined') {
				focus_on_new_row = false;
			}

			var this_row = $(this).closest('tr'),
			    clone = this_row.clone();

			clone.find('input').val('');
			clone.find('.cell-wrapper').hide();
			clone.insertAfter(this_row).find('.cell-wrapper').slideDown(150, function() {
				if (focus_on_new_row) {
					clone.find('input').eq(0).focus();
				}
			});
			WPSC_Settings_Page.Shipping.refresh_alt_row();
			return false;
		},

		/**
		 * Delete a table rate layer row.
		 * @since 3.8.8
		 */
		event_delete_table_rate_layer : function() {
			var this_row = $(this).closest('tr');
			if (WPSC_Settings_Page.Shipping.wrapper.find('.table-rate tr:not(.js-warning)').size() == 1) {
				this_row.find('input').val('');
				this_row.fadeOut(150, function(){ $(this).fadeIn(150); } );
			} else {
				this_row.find('.cell-wrapper').slideUp(150, function(){
					this_row.remove();
					WPSC_Settings_Page.Shipping.refresh_alt_row();
				});
			}
			return false;
		},

		/**
		 * Load Shipping Module settings form via AJAX when "Edit" is clicked.
		 * @since 3.8.8
		 */
		event_edit_shipping_module : function() {
			var element = $(this),
			    shipping_module_id = element.data('module-id'),
			    spinner = element.siblings('.ajax-feedback'),
			    post_data = {
			    	action : 'wpsc_shipping_module_settings_form',
			    	'shipping_module_id' : shipping_module_id,
			    	nonce  : WPSC_Settings_Page.nonce
			    },
			    ajax_callback = function(response) {
			    	if (history.pushState) {
			    		var new_url = '?page=wpsc-settings&tab=' + WPSC_Settings_Page.current_tab + '&shipping_module_id=' + shipping_module_id;
			    		history.pushState({'tab_id' : WPSC_Settings_Page.current_tab}, '', new_url);
			    	}
			    	spinner.toggleClass('ajax-feedback-active');
			    	$('#wpsc-shipping-module-settings').replaceWith(response);
			    };

			spinner.toggleClass('ajax-feedback-active');
			$.post(ajaxurl, post_data, ajax_callback, 'html');
			return false;
		},

		/**
		 * Refresh the zebra rows of the table
		 * @since 3.8.8
		 */
		refresh_alt_row : function() {
			WPSC_Settings_Page.Shipping.wrapper.find('.alternate').removeClass('alternate');
			WPSC_Settings_Page.Shipping.wrapper.find('tr:odd').addClass('alternate');
		}
	};
	$(WPSC_Settings_Page).bind('wpsc_settings_tab_loaded_shipping', WPSC_Settings_Page.Shipping.event_init);

	WPSC_Settings_Page.Gateway = {
		event_init : function() {
			var wrapper = $('#options_gateway');
			wrapper.delegate('.edit-payment-module', 'click', WPSC_Settings_Page.Gateway.event_edit_payment_gateway);
		},

		event_edit_payment_gateway : function() {
			var element = $(this),
			    payment_gateway_id = element.data('gateway-id'),
			    spinner = element.siblings('.ajax-feedback'),
			    post_data = {
			    	action : 'wpsc_payment_gateway_settings_form',
			    	'payment_gateway_id' : payment_gateway_id,
			    	nonce  : WPSC_Settings_Page.nonce
			    },
			    ajax_callback = function(response) {
			    	if (history.pushState) {
			    		var new_url = '?page=wpsc-settings&tab=' + WPSC_Settings_Page.current_tab + '&shipping_module_id=' + payment_gateway_id;
			    		history.pushState({'tab_id' : WPSC_Settings_Page.current_tab}, '', new_url);
			    	}
			    	spinner.toggleClass('ajax-feedback-active');
			    	$('#wpsc-payment-gateway-settings-panel').replaceWith(response);
			    };

			spinner.toggleClass('ajax-feedback-active');
			$.post(ajaxurl, post_data, ajax_callback, 'html');
			return false;
		}
	};
	$(WPSC_Settings_Page).bind('wpsc_settings_tab_loaded_gateway', WPSC_Settings_Page.Gateway.event_init);
})(jQuery);

WPSC_Settings_Page.init();