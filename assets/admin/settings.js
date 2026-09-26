/* moBooking — admin settings: init WP colour pickers on the Appearance tab. */
(function ($) {
  $(function () {
    if ($.fn.wpColorPicker) {
      $('.mclb-color').wpColorPicker();
    }
  });
})(jQuery);
