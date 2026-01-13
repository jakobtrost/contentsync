/**
 * This file handles the Wizard.
 */
(function() { 

	jQuery(function() {

		if (typeof $ === 'undefined') $ = jQuery;

		greyd.wizard.init();
	} );

} )(jQuery);


var greyd = greyd || {};

greyd.wizard = new function() {

	this.alert_on_leave = false;

	this.init = function() {
		// events
		this.addEvents();
	}
	this.addEvents = function() {

		// alert on window leave when var is true
		$(window).on('beforeunload', function(e){
			if (greyd.wizard.alert_on_leave) return true;
			else e = null;
		});

		// add click events
		$('.wizard .reload').on('click', this.reload);
		$('.wizard .close_wizard').on('click', this.close);
		$('.wizard .finish_wizard').on('click', function(){ greyd.wizard.finish(this); });
		$('.wizard .start_wizard').on('click', this.start);
		$('.wizard .start_library').on('click', function() { greyd.templateLibrary.open(); });
		$('.wizard .wizard_option').on('click', this.select);
		$('.wizard .back').on('click', this.back);
		$('.wizard .prev').on('click', this.prev);
		$('.wizard .next').on('click', this.next);
		$('.wizard .setup').on('click', this.next);

		$('.wizard .pagination .page').on('click', function() { greyd.wizard.setState($(this).data("slide")) });
	}

	this.initnew = function() {
		$('#greyd-wizard .wizard_box').addClass('big');
		greyd.wizard.open();
		greyd.wizard.start();
	}
	this.open = function(mode='') {
		greyd.wizard.reset(mode);
		$('.wizard').fadeIn();
	}
	this.close = function() {
		$('.wizard').fadeOut();

		// set timeout to call after href
		if ( location.search.indexOf("&wizard=") > -1 || location.search.indexOf("?wizzard=") > -1) {
			setTimeout(function() { wizzard.reload(); },0); // reload without url-param
		}
	}
	this.finish = function(el) {
		var button = $(el);
		if ( button.attr('href') ) {
			if ( !button.attr('target') ) return;
		}
		greyd.wizard.close();
	}
	this.reload = function() {
		location.search = location.search.replace(/\&wizard\=.*/g, '').replace(/\&wizzard\=.*/g, '');
		// location.reload(true);
	}
	this.start = function() {
		$('.wizard').addClass('started');
		if ($(this).hasClass('reset'))
			greyd.wizard.setState(20);
		else
			greyd.wizard.setState(1);
	}
	this.back = function(el) {
		greyd.wizard.setState(1);
	}
	this.prev = function() {
		var state = $('.wizard').data('state');
		state--;
		if (state < 1) greyd.wizard.reset();
		else greyd.wizard.setState(state);
	}
	this.next = function() {
		var state = $('.wizard').data('state');
		state++;
		greyd.wizard.setState(state);
	}

	this.reset = function(mode='') {
		$('.wizard').removeClass('started');
		greyd.wizard.setState(0, mode);

		// greyd.wizard.setState(8);
		$('.wizard .wizard_content .wizard_option').each(function() {
			$(this).removeClass('active');
			$(this).data('option', -1);
			var src = $(this).children('img').attr('src');
			if ($(this).hasClass('result')) {
				$(this).children('img').attr('src', src.replace(/(assets\/wizard\/img\/)(.*)(?=.svg)/g, 'assets/wizard/img/no-select'));
			} 
			else {
				$(this).children('img').attr('src', src.replace('_select', ''));
			}
		});
		$('.wizard_content .install_txt').css('opacity', 0);
	}

	this.setState = function(state, mode='') {
		var setstate = state;
		if (mode.length > 0) setstate = state+'-'+mode;

		greyd.wizard.showSlide($('.wizard .wizard_content').find("div[data-slide='" + setstate + "']"));
		if (setstate != '0-init' && setstate != '0-reset' && setstate > 0 && setstate < 5) {
			$('.wizard .wizard_foot > div').each(function() {
				if ($(this).data('slide') == 1) $(this).show();
				else $(this).hide();
			});
			$('.wizard .pagination').children().each(function() {
				$(this).removeClass('current');
				if ($(this).index() == setstate-1) $(this).addClass('current');
			});
		}
		else {
			if ( $('.wizard .wizard_foot > div[data-slide="'+setstate+'"]').length === 0 ) {
				greyd.wizard.start();
			} else {
				$('.wizard .wizard_foot > div').each(function() {
					if ($(this).data('slide') == setstate) $(this).show();
					else $(this).hide();
				});
			}
		}
		$('.wizard').data('state', state);

		// toggle infos
		var txt = $('.wizard_content > .active .install_txt');
		var opac = 0;
		if (txt.length > 0) {
			txt.each(function(i, obj){
				setTimeout(function() { $(obj).css('opacity', 1); }, opac);
				opac += 500;
			});
		}
	}

	this.showSlide = function(slide) {
		slide.addClass("active");
		slide.siblings().removeClass("active");
	}

	this.select = function() {
		if ($(this).hasClass('result')) {
			greyd.wizard.setState($(this).index()+1);
		} 
		else {
			$(this).parent().children().each(function() {
				$(this).removeClass('active');
				var src = $(this).children('img').attr('src');
				$(this).children('img').attr('src', src.replace('_select', ''));
			});
			$(this).addClass('active');
			var src = $(this).children('img').attr('src');
			var title = $(this).children('p').html();
			$(this).children('img').attr('src', src.replace('.svg', '_select.svg'));

			var state = $('.wizard').data('state');
			var option = $(this).index();
			var result = $('.wizard_option.result')[state-1];
			$(result).data('option', option);
			$(result).attr('title', title);
			$(result).children('img').attr('src', src);
			greyd.wizard.next();
		}
	}

	this.selectContent = function(el) {
		$(el).parent().children().each(function() {
			$(this).removeClass('active');
			var src = $(this).children('img').attr('src');
			$(this).children('img').attr('src', src.replace('_select', ''));
		});
		$(el).addClass('active');
		var src = $(el).children('img').attr('src');
		$(el).children('img').attr('src', src.replace('.png', '_select.png'));
		greyd.wizard.setCopyContent();
	}

	this.setCopyContent = function() {
		var el = $('#greyd-wizard #copy');
		var type = $('#greyd-wizard #ttype').val();
		if ($('#greyd-wizard #copy_'+type).length == 0) {
			$(el).css('display', 'none');
			if ($(el).hasClass('active')) {
				greyd.wizard.selectContent($('#greyd-wizard #default'));
			}
		}
		else {
			$(el).css('display', 'block');
			$(el).find('select').each(function() {
				$(this).css('display', 'none');
			});
			if ($(el).hasClass('active')) {
				$('#greyd-wizard #copy_'+type).css('display', 'block');
			}
		}
	}

	/**
	 * Check for existing posts
	 * @param {array} posts 
	 */
	this.checkExisting = function(posts) {
		// console.log("new slug: "+$('#greyd-wizard #slug').val());
		// console.log("new name: "+$('#greyd-wizard #name').val());
		var index = -1;
		if (Array.isArray(posts) && posts.length > 0) {
			var slug = $('#greyd-wizard #slug').val();
			if (slug == "") slug = $('#greyd-wizard #name').val();
			for (var i=0; i<posts.length; i++) {
				if (posts[i].slug == slug || posts[i].title == slug) {
					index = i;
					break;
				}
			}
		}
		if (index > -1) {
			// console.log("template exists!");
			$('#greyd-wizard .name_double').css('display', 'block');
			$('#greyd-wizard .name_double .double_name').html(posts[index].title);
			var url = location.href.split("wp-admin");
			var edit = url[0]+"wp-admin/post.php?post="+posts[index].id+"&action=edit";
			$('#greyd-wizard .name_double .double_edit').attr('href', edit);
			$('#greyd-wizard .name_ok').css('display', 'none');
			$('#greyd-wizard .create.button').addClass('disabled');
		}
		else {
			// console.log("template does not exist!");
			$('#greyd-wizard .name_double').css('display', 'none');
			$('#greyd-wizard .name_ok').css('display', 'block');
			$('#greyd-wizard .create.button').removeClass('disabled');
		}
	}

	/**
	 * Create a post.
	 * @param {string} mode 
	 * @param {object} data 
	 */
	this.createPost = function(mode, data) {
		var txt = $('.wizard_content .install_txt');
		$.post(
			greyd.ajax_url,
			{
				'action': mode === 'create_template' ? 'contentsync_ajax' : 'contentsync_admin_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': data
			}, 
			function(response) {
				if (response.indexOf('error::') > -1) {
					console.warn(response);
					var tmp = response.split('error::');

					$('#greyd-wizard .error_msg').html(tmp[1]);
					greyd.wizard.setState('0-error');
				}
				else {
					console.info(response);
					var mode = 'success';
					if (response.indexOf('info::') > -1) mode = 'info';
					var tmp = response.split(mode+'::');
					var msg = tmp[1];
					var url = location.href.split("wp-admin");
					var post_id = msg.split('(id ');
					post_id = post_id[1].split(')');
					var edit = url[0]+"wp-admin/post.php?post="+post_id[0]+"&action=edit";

					$('#greyd-wizard .finish_wizard').attr('href', edit);
					$('#greyd-wizard .wizard_box').removeClass('big');
					$(txt[0]).html($(txt[0]).html().replace('%s', data.name)); 
					$(txt[0]).css('opacity', 1); 
					greyd.wizard.setState(11);
					setTimeout(function() { $(txt[1]).css('opacity', 1); }, 100);
				}
			}
		);
	}


	/**
	 * Backward compatibility function for silent Greyd installation.
	 * @since 1.7.0
	 * 
	 * Used by hub.js.
	 * @todo refactor to setup a new installation client.
	 */
	this.install_silent = function(mode, blog_id, callback) {
		if (
			typeof wizzard !== 'undefined'
			&& typeof wizzard.install_silent === 'function'
		) {
			console.log("deprecated install_silent");
			return wizzard.install_silent(mode, blog_id, callback);
		}
		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': blog_id
			}, 
			function(response) {
				if (response.indexOf('error::') > -1) {
					if (callback) {
						console.warn( response );
						callback.apply(this, ['fail']);
					}
				}
				else {
					if (callback) {
						console.info( response );
						callback.apply(this, ['reload']);
					}
				}
			}
		);
		return false;
	}
}
