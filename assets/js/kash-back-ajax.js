jQuery(document).ready(function ($) {
  // Обработчик кликов по ссылкам пагинации
  $(document).on('click', '.kash-back-pagination a', function (e) {
    e.preventDefault();

    var href = $(this).attr('href');
    var page = 1;
    var $clickedElement = $(this);

    // Извлекаем номер страницы из URL
    if (href) {
      var match = href.match(/kb_page[\/=](\d+)/);
      if (match && match[1]) {
        page = parseInt(match[1]);
      } else if ($clickedElement.hasClass('next')) {
        // Если это следующая страница
        var current = parseInt($('.kash-back-pagination .page-numbers.current').text());
        page = current ? current + 1 : 1;
      } else if ($clickedElement.hasClass('prev')) {
        // Если это предыдущая страница
        var current = parseInt($('.kash-back-pagination .page-numbers.current').text());
        page = current ? current - 1 : 1;
      } else if (
        $clickedElement.hasClass('page-numbers') &&
        !isNaN(parseInt($clickedElement.text()))
      ) {
        // Если это конкретный номер страницы
        page = parseInt($clickedElement.text());
      }
    }

    loadOrdersPage(page);
  });

  // Функция загрузки страницы заказов
  function loadOrdersPage(page) {
    // Показываем индикатор загрузки
    var tableBody = $('#kash-back-orders-body');
    var originalContent = tableBody.html();
    tableBody.html(
      '<tr><td colspan="6" style="text-align: center; padding: 20px;"><div>Загрузка...</div></td></tr>',
    );
    $('html, body').animate({ scrollTop: $('#kash-back-orders-body').offset().top - 100 }, 300);

    // AJAX запрос
    $.ajax({
      url: kash_back_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'kash_back_load_orders',
        page: page,
        security: kash_back_ajax.nonce,
      },
      success: function (response) {
        if (response.success) {
          // Обновляем содержимое таблицы
          tableBody.html(response.html);

          // Обновляем пагинацию
          $('.kash-back-pagination').html(response.pagination);

          // Обновляем URL без перезагрузки страницы
          var newUrl =
            window.location.protocol + '//' + window.location.host + window.location.pathname;
          if (page > 1) {
            newUrl += '?kb_page=' + page;
          }
          window.history.pushState({ path: newUrl }, '', newUrl);

          // Прокручиваем к таблице
          $('html, body').animate(
            { scrollTop: $('#kash-back-orders-body').offset().top - 100 },
            300,
          );
        } else {
          // В случае ошибки восстанавливаем оригинальное содержимое
          tableBody.html(originalContent);
          console.error('Ошибка при загрузке данных');
        }
      },
      error: function () {
        // В случае ошибки восстанавливаем оригинальное содержимое
        tableBody.html(originalContent);
        console.error('Ошибка при выполнении AJAX запроса');
      },
    });
  }
});
