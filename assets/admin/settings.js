/* moBooking — admin settings: colour pickers (Appearance) + repeatable event
 * types (Events). */
(function ($) {
  $(function () {
    function initColors($scope) {
      if ($.fn.wpColorPicker) {
        $scope.find('.mclb-color').wpColorPicker();
      }
    }
    initColors($(document));

    // Repeatable event-type rows.
    var $rows = $('.mclb-et-rows');
    if ($rows.length) {
      var tmpl = $('#tmpl-mclb-et-row').html() || '';

      $(document).on('click', '.mclb-et-add', function () {
        // A unique, collision-proof row index; the server ignores indices and
        // re-sequences on save, so any unique token works.
        var idx = 'n' + Date.now();
        var $row = $(tmpl.replace(/__i__/g, idx));
        $rows.append($row);
        initColors($row);
      });

      $rows.on('click', '.mclb-et-remove', function () {
        $(this).closest('.mclb-et-row').remove();
      });
    }
  });
})(jQuery);
