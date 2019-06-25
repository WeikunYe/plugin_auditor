(function($) { 

	$('.form-edit-comment').click(function(e){

		e.preventDefault();

		// define vars
		$this = $(this);
		var wasClicked = $this.attr('data-clicked'),
			formId = $this.attr('id'),
			contentField = $('.note-id-' + formId ),
			button = $this.find(':button'),
			buttonText = button.find('.button-content');

		if ( wasClicked == 'false'){
			
			// make table editable
			contentField.prop('contenteditable','true')
				.addClass('pa-editable')
				.focus();

			// change button value and color
			button.addClass('pa-save');
			buttonText.text('Save');

			$this.attr('data-clicked', 'true');
		}

		if( wasClicked == 'true' ){

			var pluginDesc = contentField.text(),
				loader = button.find('.loader');
				data = {
			        'action': 'update_plugin_description',
			        'plugin_desc': pluginDesc,
					'log_id': formId,
					'post_id': 1,
			    };

		    // block content editing
			contentField.prop('contenteditable','false')
				.removeClass('pa-editable');

	         jQuery.ajax({
				type : "post",
				url :  myAjax.url,
				data : data,
				beforeSend: function(){
		         	buttonText.text('');
					loader.show();
				},
				success: function(response) {

					if (response == 'success'){
						loader.hide();
						button.removeClass('pa-save').addClass('pa-success');
						buttonText.text('Saved');
						setTimeout(function(){ 
							button.removeClass('pa-success');
							buttonText.fadeOut(function() {
							  $(this).text("Edit comment")
							}).fadeIn();
							
						}, 1200);
					} else {
						loader.hide();
						button.removeClass('pa-save').addClass('pa-error');
						buttonText.text('Not saved');
						setTimeout(function(){ 
							button.removeClass('pa-error');
							buttonText.fadeOut(function() {
							  $(this).text("Edit comment")
							}).fadeIn();
							
						}, 1200);

					}
				}
			});

	        $this.attr('data-clicked', 'false');

	      }  

	});

})(jQuery);